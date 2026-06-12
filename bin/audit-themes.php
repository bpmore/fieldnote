<?php

/**
 * Theme compliance auditor. CLI only.
 *
 *   php bin/audit-themes.php [theme ...]
 *
 * For every theme (or just the ones named on the command line) this checks:
 *
 *   CSS  - the eight required color tokens exist in both schemes
 *        - WCAG 2.2 AA contrast over the required pair matrix, both schemes
 *        - color-scheme declared
 *        - no color literals outside the two token blocks
 *        - no outline:none / outline:0
 *   PHP  - header.php calls fn_skip_link() and marks id="main"
 *        - home.php calls fn_pagination()
 *        - post.php calls fn_image_alt()
 *        - exactly one <h1> per rendered page (header+home, header+post, header+404)
 *
 * Exit code 0 = all green, 1 = failures.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Fieldnote\CssTokens;

const REQUIRED_TOKENS = ['--bg', '--surface', '--text', '--muted', '--accent', '--accent-contrast', '--line', '--focus'];

// [foreground, background, minimum ratio]
const PAIR_MATRIX = [
    ['--text', '--bg', 4.5],
    ['--text', '--surface', 4.5],
    ['--muted', '--bg', 4.5],
    ['--muted', '--surface', 4.5],
    ['--accent', '--bg', 4.5],
    ['--accent', '--surface', 4.5],
    ['--accent-contrast', '--accent', 4.5],
    ['--line', '--bg', 3.0],
    ['--focus', '--bg', 3.0],
];

$templatesDir = dirname(__DIR__) . '/templates';
$only = array_slice($argv, 1);

$failures = 0;

/** Parse a CSS color literal to [r,g,b] 0-255, or null if unsupported. */
function parseColor(string $value): ?array
{
    $value = strtolower(trim($value));
    if (preg_match('/^#([0-9a-f]{3})$/', $value, $m)) {
        return [hexdec($m[1][0] . $m[1][0]), hexdec($m[1][1] . $m[1][1]), hexdec($m[1][2] . $m[1][2])];
    }
    if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $value, $m)) {
        return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))];
    }
    if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $value, $m)) {
        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }
    $named = ['white' => [255, 255, 255], 'black' => [0, 0, 0]];
    return $named[$value] ?? null;
}

function relativeLuminance(array $rgb): float
{
    $chan = array_map(static function (int $c): float {
        $c /= 255;
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, $rgb);
    return 0.2126 * $chan[0] + 0.7152 * $chan[1] + 0.0722 * $chan[2];
}

function contrast(array $a, array $b): float
{
    $l1 = relativeLuminance($a);
    $l2 = relativeLuminance($b);
    [$hi, $lo] = $l1 >= $l2 ? [$l1, $l2] : [$l2, $l1];
    return ($hi + 0.05) / ($lo + 0.05);
}

// Token-block parsing lives in Fieldnote\CssTokens, shared with the admin
// theme preview so the two never drift.

$themes = [];
foreach (glob($templatesDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $name = basename($dir);
    if ($only && !in_array($name, $only, true)) {
        continue;
    }
    if (is_file($dir . '/home.php') && is_file($dir . '/post.php')) {
        $themes[$name] = $dir;
    }
}

foreach ($themes as $name => $dir) {
    $problems = [];

    // ---------------------------------------------------------------- CSS --
    $cssPath = $dir . '/assets/theme.css';
    if (!is_file($cssPath)) {
        $problems[] = 'missing assets/theme.css';
    } else {
        $css = (string) file_get_contents($cssPath);

        $rootBody = CssTokens::rootBlock($css);
        $light = $rootBody !== null ? CssTokens::extractTokens($rootBody) : [];
        $darkBody = CssTokens::schemeBlock($css, 'dark');
        $lightBody = CssTokens::schemeBlock($css, 'light');

        if ($rootBody === null) {
            $problems[] = 'no :root token block';
        } elseif ($darkBody === null && $lightBody === null) {
            $problems[] = 'no prefers-color-scheme block (need a second scheme)';
        } else {
            // Default polarity: :root + dark override. Dark-default themes:
            // :root + light override.
            $override = CssTokens::extractTokens((string) ($darkBody ?? $lightBody));
            $schemes = [
                'default' => $light,
                ($darkBody !== null ? 'dark' : 'light') => array_merge($light, $override),
            ];

            foreach ($schemes as $schemeName => $tokens) {
                foreach (REQUIRED_TOKENS as $tok) {
                    if (!isset($tokens[$tok])) {
                        $problems[] = "[$schemeName] missing token $tok";
                    }
                }
                foreach (PAIR_MATRIX as [$fgTok, $bgTok, $min]) {
                    if (!isset($tokens[$fgTok], $tokens[$bgTok])) {
                        continue; // missing token already reported
                    }
                    $fg = parseColor($tokens[$fgTok]);
                    $bg = parseColor($tokens[$bgTok]);
                    if ($fg === null || $bg === null) {
                        $problems[] = "[$schemeName] unparsable color in $fgTok or $bgTok";
                        continue;
                    }
                    $ratio = contrast($fg, $bg);
                    if ($ratio < $min) {
                        $problems[] = sprintf('[%s] %s on %s = %.2f:1 (need %.1f:1)', $schemeName, $fgTok, $bgTok, $ratio, $min);
                    }
                }
            }
        }

        if (!preg_match('/color-scheme\s*:/', $css)) {
            $problems[] = 'missing color-scheme declaration';
        }
        if (preg_match('/outline\s*:\s*(none|0)\b/', $css)) {
            $problems[] = 'outline:none/0 found (kills focus visibility)';
        }

        // Color literals outside token blocks: strip both token blocks and
        // all custom-property declarations, then look for literals in what
        // remains. gradient stops etc. must come from tokens.
        $stripped = $css;
        if ($rootBody !== null) {
            $stripped = str_replace($rootBody, '', $stripped);
        }
        foreach (['dark', 'light'] as $s) {
            $b = CssTokens::schemeBlock($css, $s);
            if ($b !== null) {
                $stripped = str_replace($b, '', $stripped);
            }
        }
        $stripped = preg_replace('/--[a-z0-9-]+\s*:[^;]+;/i', '', $stripped) ?? $stripped;
        $stripped = preg_replace('/\/\*.*?\*\//s', '', $stripped) ?? $stripped;
        if (preg_match_all('/#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(/i', $stripped, $m)) {
            $problems[] = 'color literal(s) outside token blocks: ' . count($m[0]) . ' occurrence(s)';
        }
    }

    // ---------------------------------------------------------------- PHP --
    $header = (string) @file_get_contents($dir . '/header.php');
    $home   = (string) @file_get_contents($dir . '/home.php');
    $post   = (string) @file_get_contents($dir . '/post.php');
    $nf     = (string) @file_get_contents($dir . '/404.php');

    if (!str_contains($header, 'fn_skip_link(')) {
        $problems[] = 'header.php missing fn_skip_link()';
    }
    if (!str_contains($header, 'id="main"')) {
        $problems[] = 'header.php missing id="main" on main element';
    }
    if (!str_contains($home, 'fn_pagination(')) {
        $problems[] = 'home.php missing fn_pagination()';
    }
    if (str_contains($post, 'imageUrl') && !str_contains($post, 'fn_image_alt(')) {
        $problems[] = 'post.php missing fn_image_alt() on hero image';
    }

    // Exactly one h1 per rendered page type.
    $h1 = static fn (string $s): int => preg_match_all('/<h1[\s>]/i', $s);
    foreach (['home' => $home, 'post' => $post, '404' => $nf] as $pageName => $body) {
        $count = $h1($header) + $h1($body);
        if ($count !== 1) {
            $problems[] = "$pageName page renders $count <h1> elements (need exactly 1)";
        }
    }

    if ($problems) {
        $failures++;
        echo "\u{2717} $name\n";
        foreach ($problems as $p) {
            echo "    - $p\n";
        }
    } else {
        echo "\u{2713} $name\n";
    }
}

echo "\n" . (count($themes) - $failures) . '/' . count($themes) . " themes pass\n";
exit($failures > 0 ? 1 : 0);

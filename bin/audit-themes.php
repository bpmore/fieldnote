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
use Fieldnote\Wcag;

// Color math and the token contract live in Fieldnote\Wcag, shared with the
// admin palette customizer so a palette the auditor would reject can never
// be saved from the UI either.
const REQUIRED_TOKENS = Wcag::REQUIRED_TOKENS;
const PAIR_MATRIX = Wcag::PAIR_MATRIX;

$templatesDir = dirname(__DIR__) . '/templates';
$only = array_slice($argv, 1);

$failures = 0;

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
            // Same resolution the palette editor uses. Labels stay light/dark
            // rather than the old default/dark-or-light, which named :root
            // "default" even on a dark theme and made a failure message read
            // as the opposite scheme to the one that failed.
            $schemes = CssTokens::schemeTokens($css) ?? ['light' => $light, 'dark' => $light];

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
                    $fg = Wcag::parseColor($tokens[$fgTok]);
                    $bg = Wcag::parseColor($tokens[$bgTok]);
                    if ($fg === null || $bg === null) {
                        $problems[] = "[$schemeName] unparsable color in $fgTok or $bgTok";
                        continue;
                    }
                    $ratio = Wcag::contrast($fg, $bg);
                    if ($ratio < $min) {
                        $problems[] = sprintf('[%s] %s on %s = %.2f:1 (need %.1f:1)', $schemeName, $fgTok, $bgTok, $ratio, $min);
                    }
                }
            }
        }

        // color-scheme tells the UA how to render what the theme does not:
        // form controls, scrollbars, the canvas behind the page. The old check
        // here was dead — it grepped for "color-scheme:", which every
        // "prefers-color-scheme:" media query already satisfies, and a theme
        // with no :root declaration at all sailed past it.
        //
        // Read it out of the :root block only. `html { color-scheme: … }`
        // does not override it: :root is a pseudo-class (0,1,0) and beats a
        // type selector (0,0,1) whatever the source order, so a theme carrying
        // both was quietly running whichever value sat in :root.
        $declared = preg_match('/(?<!prefers-)color-scheme\s*:\s*([a-z ]+?)\s*[;}]/i', (string) $rootBody, $csMatch)
            ? trim($csMatch[1])
            : '';
        if ($declared === '') {
            $problems[] = 'no color-scheme in :root';
        } elseif ($darkBody !== null && $lightBody === null && $declared !== 'light dark') {
            // Light by default with a dark override: both schemes render.
            $problems[] = "color-scheme is '$declared', expected 'light dark' (light theme with a dark override)";
        } elseif ($lightBody !== null && $darkBody === null && !in_array($declared, ['dark light', 'dark'], true)) {
            // Dark by default. 'dark light' when the override is a real light
            // scheme; plain 'dark' when it is a variation that stays dark —
            // six themes ship that deliberately, and claiming 'light' there
            // would have the UA render light widgets on a dark page.
            $problems[] = "color-scheme is '$declared', expected 'dark light' or 'dark' (dark theme)";
        }
        // CssTokens reads the FIRST prefers-color-scheme block and nothing else,
        // so a second one defining tokens is invisible to the contrast pass above
        // and to the admin preview. Counted on comment-stripped CSS and only
        // where tokens are actually defined: a second block carrying ordinary
        // rules is a README matter, not a hole in the gate.
        $noComments = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $tokenBlocks = 0;
        if (preg_match_all('/@media\s*\(prefers-color-scheme:[^)]*\)\s*\{/', $noComments, $blockAt, PREG_OFFSET_CAPTURE)) {
            foreach ($blockAt[0] as [$blockText, $blockOffset]) {
                if (preg_match('/--[a-z0-9-]+\s*:\s*[^;]+;/i', substr($noComments, $blockOffset, 400))) {
                    $tokenBlocks++;
                }
            }
        }
        if ($tokenBlocks > 1) {
            $problems[] = "$tokenBlocks prefers-color-scheme blocks define tokens (only the first is read)";
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
        // Deliberately NOT stripping every custom-property declaration: doing
        // that ignored location, so `.card { --accent: #ff0000; }` at file scope
        // was skipped and never flagged — which is precisely the rule this
        // check exists to enforce. Only the three token blocks are exempt, and
        // they were already removed above.
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

    // Eleven helpers appear in every installed theme; the gate used to check
    // three of them, and the README filed six of the rest under "Optional
    // helpers" while its own prose said every theme calls them. A theme could
    // drop fn_render_head — and with it the shared a11y baseline that supplies
    // the focus ring this very script checks for — and still pass.
    //
    // One table, so the contract is a list rather than a habit.
    $requiredCalls = [
        'header.php' => ['fn_render_head(', 'fn_skip_link(', 'fn_utility_bar('],
        'home.php'   => ['fn_search_status(', 'fn_pagination(', 'fn_post_url('],
        'post.php'   => ['fn_post_admin('],
        'footer.php' => ['fn_footer_copyright(', 'fn_social_links(', 'fn_a11y_badge('],
    ];
    foreach ($requiredCalls as $themeFile => $calls) {
        $body = (string) @file_get_contents("$dir/$themeFile");
        foreach ($calls as $call) {
            if (!str_contains($body, $call)) {
                $problems[] = "$themeFile missing " . rtrim($call, '(') . '()';
            }
        }
    }
    if (!str_contains($header, 'id="main"')) {
        $problems[] = 'header.php missing id="main" on main element';
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

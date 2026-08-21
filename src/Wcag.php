<?php

namespace Fieldnote;

/**
 * WCAG 2.2 color math and the theme token contract, shared by the theme
 * auditor (bin/audit-themes.php) and the admin palette customizer so a
 * palette that fails the auditor can never be saved from the UI either.
 */
final class Wcag
{
    /** Every theme must declare these in both schemes. */
    public const REQUIRED_TOKENS = ['--bg', '--surface', '--text', '--muted', '--accent', '--accent-contrast', '--line', '--focus'];

    /** Human labels for the customizer UI. */
    public const TOKEN_ROLES = [
        '--bg'              => 'Page background',
        '--surface'         => 'Cards and panels',
        '--text'            => 'Body text',
        '--muted'           => 'Secondary text',
        '--accent'          => 'Links and accents',
        '--accent-contrast' => 'Text on accent',
        '--line'            => 'Borders and rules',
        '--focus'           => 'Focus ring',
    ];

    /** [foreground, background, minimum ratio] */
    public const PAIR_MATRIX = [
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

    /** Parse a CSS color literal to [r,g,b] 0-255, or null if unsupported. */
    public static function parseColor(string $value): ?array
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

    /** @param array{0:int,1:int,2:int} $rgb */
    public static function relativeLuminance(array $rgb): float
    {
        $chan = array_map(static function (int $c): float {
            $c /= 255;
            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);
        return 0.2126 * $chan[0] + 0.7152 * $chan[1] + 0.0722 * $chan[2];
    }

    public static function contrast(array $a, array $b): float
    {
        $l1 = self::relativeLuminance($a);
        $l2 = self::relativeLuminance($b);
        [$hi, $lo] = $l1 >= $l2 ? [$l1, $l2] : [$l2, $l1];
        return ($hi + 0.05) / ($lo + 0.05);
    }

    /**
     * Run the pair matrix over a resolved token map.
     *
     * @param array<string,string> $tokens
     * @return list<array{fg:string,bg:string,ratio:float,min:float}> failures
     */
    public static function failingPairs(array $tokens): array
    {
        $failures = [];
        foreach (self::PAIR_MATRIX as [$fgTok, $bgTok, $min]) {
            if (!isset($tokens[$fgTok], $tokens[$bgTok])) {
                continue;
            }
            $fg = self::parseColor($tokens[$fgTok]);
            $bg = self::parseColor($tokens[$bgTok]);
            if ($fg === null || $bg === null) {
                // Not a contrast failure, so it does not get reported as one.
                // This used to push ratio => 0.0 -- a value contrast cannot
                // take, since real ratios are 1 to 21 -- which the palette
                // editor then rendered to the writer as "is 0.00:1, needs
                // 4.5:1". Unreachable there anyway: every value it passes in
                // is a validated #rrggbb. Callers that accept arbitrary CSS
                // check parseColor themselves; bin/audit-themes.php does.
                continue;
            }
            $ratio = self::contrast($fg, $bg);
            if ($ratio < $min) {
                $failures[] = ['fg' => $fgTok, 'bg' => $bgTok, 'ratio' => $ratio, 'min' => $min];
            }
        }
        return $failures;
    }

    /**
     * Nearest color to $fg that reaches $min contrast against $bg, found by
     * walking lightness outward in both directions while keeping hue and
     * saturation — "your blue, but readable."
     */
    public static function suggestColor(string $fg, string $bg, float $min): ?string
    {
        $fgRgb = self::parseColor($fg);
        $bgRgb = self::parseColor($bg);
        if ($fgRgb === null || $bgRgb === null) {
            return null;
        }
        [$h, $s, $l] = self::rgbToHsl($fgRgb);
        for ($delta = 1; $delta <= 100; $delta++) {
            foreach ([-1, 1] as $dir) {
                $candidate = $l + $dir * $delta;
                if ($candidate < 0 || $candidate > 100) {
                    continue;
                }
                $rgb = self::hslToRgb($h, $s, $candidate);
                if (self::contrast($rgb, $bgRgb) >= $min) {
                    return self::toHex($rgb);
                }
            }
        }
        return null;
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    public static function toHex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }

    /** @return array{0:float,1:float,2:float} [hue 0-360, sat 0-100, light 0-100] */
    public static function rgbToHsl(array $rgb): array
    {
        [$r, $g, $b] = array_map(static fn (int $c): float => $c / 255, $rgb);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l   = ($max + $min) / 2;
        if ($max === $min) {
            return [0.0, 0.0, $l * 100];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => fmod(($g - $b) / $d + 6, 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };
        return [$h * 60, $s * 100, $l * 100];
    }

    /** @return array{0:int,1:int,2:int} */
    public static function hslToRgb(float $h, float $s, float $l): array
    {
        $s /= 100;
        $l /= 100;
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60  => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default  => [$c, 0, $x],
        };
        return [
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        ];
    }
}

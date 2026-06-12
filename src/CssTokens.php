<?php

namespace Fieldnote;

/**
 * Shared parser for the theme CSS token contract: the `:root` token block
 * and its single prefers-color-scheme override block. Used by both
 * bin/audit-themes.php and the admin theme preview so the auditor and the
 * preview read theme.css with one implementation.
 */
final class CssTokens
{
    /** Find the body of `:root { ... }` inside an (optional) wrapping string. */
    public static function rootBlock(string $css): ?string
    {
        if (preg_match('/:root\s*\{([^}]*)\}/s', $css, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Body of the :root block inside a prefers-color-scheme media query. */
    public static function schemeBlock(string $css, string $scheme): ?string
    {
        if (preg_match('/@media[^{]*prefers-color-scheme\s*:\s*' . $scheme . '[^{]*\{(.*)$/s', $css, $m)) {
            // Walk braces to find the end of the media block.
            $depth = 1;
            $body = '';
            foreach (str_split($m[1]) as $ch) {
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $body .= $ch;
            }
            return self::rootBlock($body);
        }
        return null;
    }

    /**
     * Extract custom-property declarations from a CSS block body.
     * Resolves var() indirection within the same map (up to 3 hops).
     *
     * @return array<string,string>
     */
    public static function extractTokens(string $blockBody): array
    {
        $tokens = [];
        if (preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $blockBody, $m, PREG_SET_ORDER)) {
            foreach ($m as $decl) {
                $tokens[$decl[1]] = trim($decl[2]);
            }
        }
        for ($i = 0; $i < 3; $i++) {
            foreach ($tokens as $name => $value) {
                if (preg_match('/^var\(\s*(--[a-z0-9-]+)\s*\)$/i', $value, $m) && isset($tokens[$m[1]])) {
                    $tokens[$name] = $tokens[$m[1]];
                }
            }
        }
        return $tokens;
    }
}

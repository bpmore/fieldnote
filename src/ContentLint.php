<?php

namespace Fieldnote;

/**
 * Accessibility lint for post content. Themes are machine-enforced by the
 * auditor; this is the same idea applied to the writing. Checks the rendered
 * HTML (Parsedown safe mode, same as the live site) and returns suggestions.
 * Suggestions never block publishing — the writer stays in charge.
 */
final class ContentLint
{
    /** Link texts that say nothing about the destination. */
    private const VAGUE_LINKS = ['click here', 'here', 'click', 'read more', 'more', 'link', 'this', 'this page'];

    /** @return string[] human-readable suggestions, empty when clean */
    public static function check(string $markdown): array
    {
        $parser = new \ParsedownExtra();
        $parser->setSafeMode(true);
        $html = $parser->text($markdown);

        $warnings = [];

        // Heading structure: the theme owns the page's single <h1>, so body
        // headings should start at ## and never skip levels.
        preg_match_all('/<h([1-6])[\s>]/i', $html, $m);
        $levels = array_map('intval', $m[1]);
        if (in_array(1, $levels, true)) {
            $warnings[] = 'A "# heading" renders as a second <h1> — the page already has one (the post title). Start body headings at "##".';
        }
        $prev = 1;
        foreach ($levels as $level) {
            if ($level === 1) {
                continue;
            }
            if ($level > $prev + 1) {
                $warnings[] = sprintf('Heading levels jump from h%d to h%d — screen-reader users navigate by heading level, and skipped levels read as missing content.', $prev, $level);
                break;
            }
            $prev = $level;
        }

        // Link text that doesn't describe its destination.
        preg_match_all('/<a [^>]*>(.*?)<\/a>/is', $html, $m);
        foreach ($m[1] as $inner) {
            $plain = strtolower(trim(strip_tags($inner)));
            if (in_array($plain, self::VAGUE_LINKS, true)) {
                $warnings[] = sprintf('Link text "%s" doesn\'t say where it goes — screen readers often list links out of context. Name the destination instead.', $plain);
            } elseif (preg_match('#^https?://#', $plain)) {
                $warnings[] = 'A bare URL is used as link text — it gets read out character by character. Give the link a human label.';
            }
        }

        // Images without alt text. The markdown ![](url) form renders alt="";
        // in body content that's rarely intentional decoration.
        if (preg_match('/<img[^>]*\salt=""/i', $html)) {
            $warnings[] = 'An image has empty alt text. Describe what it shows — or if it is purely decorative, this is fine to ignore.';
        }

        // Long all-caps passages are read letter-by-letter by some screen
        // readers, and are harder for everyone.
        if (preg_match('/(?:\b\p{Lu}{2,}\b[^\p{L}\n]{0,3}){11,}/u', strip_tags($html))) {
            $warnings[] = 'A long all-caps passage — some screen readers spell capitalized runs letter by letter. Use normal case (themes can style emphasis).';
        }

        return $warnings;
    }
}

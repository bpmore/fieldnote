<?php

namespace Fieldnote;

/**
 * Notion Markdown export → normalized Porter entries. docs/importers-spec.md.
 *
 * Notion's "Markdown & CSV" export is a zip of `.md` files named
 * `<Title> <32-hex id>.md`, with no frontmatter: the first line is `# Title`
 * and (for database pages) a block of `Property: value` lines follows. This
 * strips the leading `# Title` (so the post title isn't repeated as a body
 * h1), lifts Tags/Date properties, and passes the rest through as Markdown.
 *
 * Known limit: Notion bundles inline images as zip-relative paths
 * (`![](Title%20id/img.png)`), not URLs, so they aren't localized in v1 — the
 * accessibility report flags them and they can be fixed before publishing.
 */
final class NotionImporter
{
    public static function looksLikeNotion(string $zipPath): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }
        $hit = false;
        for ($i = 0; $i < $zip->numFiles && !$hit; $i++) {
            // Notion appends a 32-hex page id to every exported filename.
            $hit = (bool) preg_match('/ [0-9a-f]{32}\.md$/i', (string) $zip->getNameIndex($i));
        }
        $zip->close();
        return $hit;
    }

    /** @return list<array<string,mixed>> */
    public static function parse(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [];
        }
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!preg_match('/\.md$/i', $name) || str_ends_with($name, '/')) {
                continue;
            }
            $entry = self::parseMd((string) $zip->getFromIndex($i), $name);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        $zip->close();
        return $entries;
    }

    private static function parseMd(string $raw, string $name): ?array
    {
        $raw      = str_replace("\r\n", "\n", $raw);
        $slugBase = (string) preg_replace('/\s+[0-9a-f]{32}$/i', '', basename($name, '.md'));
        $lines    = explode("\n", $raw);
        $i        = 0;
        $n        = count($lines);

        $skipBlank = static function () use (&$i, $lines, $n): void {
            while ($i < $n && trim($lines[$i]) === '') {
                $i++;
            }
        };

        // Title from the leading `# Heading`, removed from the body.
        $skipBlank();
        $title = '';
        if ($i < $n && preg_match('/^#\s+(.+)$/', $lines[$i], $m)) {
            $title = trim($m[1]);
            $i++;
        }

        // Notion's property block: contiguous `Property: value` lines.
        $skipBlank();
        $tags = [];
        $date = null;
        while ($i < $n && preg_match('/^([A-Z][\w ]{0,30}):\s*(.*)$/', $lines[$i], $pm)) {
            $key = strtolower(trim($pm[1]));
            $val = trim($pm[2]);
            if (in_array($key, ['tags', 'tag', 'category', 'categories'], true)) {
                foreach (preg_split('/[,;]\s*/', $val) as $t) {
                    if (trim((string) $t) !== '') {
                        $tags[] = trim((string) $t);
                    }
                }
            } elseif (in_array($key, ['date', 'published', 'created', 'publish date', 'published at'], true) && $val !== '') {
                $date = strtotime($val) ?: $date;
            }
            $i++;
        }

        $body = trim(implode("\n", array_slice($lines, $i)));
        if ($title === '' && $body === '') {
            return null;
        }
        if ($title === '') {
            $title = str_replace('-', ' ', $slugBase);
        }

        return [
            'title'    => $title,
            'slug'     => $slugBase,
            'date'     => $date ?: time(),
            'tags'     => $tags,
            'author'   => '',
            'markdown' => $body,
            'source'   => $title,
        ];
    }
}

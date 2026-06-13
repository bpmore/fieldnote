<?php

namespace Fieldnote;

/**
 * WriteFreely / Write.as JSON export → normalized Porter entries.
 * docs/importers-spec.md.
 *
 * WriteFreely is Markdown-native, so each post's `body` is Markdown and is
 * passed straight through (no HTML→Markdown step). The export is a JSON array
 * of posts, or a `{posts:[…]}` / write.as `{data:[…]}` wrapper. Titles are
 * optional — fall back to the first `# heading` or the slug. Inline
 * `#hashtags` (how WriteFreely does tags) are lifted into the post's tags.
 */
final class WriteFreelyImporter
{
    public static function looksLikeWriteFreely(string $head): bool
    {
        // WriteFreely posts carry these; Ghost (also JSON) is checked first.
        return (str_contains($head, '"appearance"') || str_contains($head, '"rtl"'))
            && !str_contains($head, '"exported_on"');
    }

    /** @return list<array<string,mixed>> */
    public static function parse(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [];
        }
        $posts = array_is_list($json)
            ? $json
            : ($json['posts'] ?? $json['data']['posts'] ?? $json['data'] ?? []);
        if (!is_array($posts)) {
            return [];
        }

        $entries = [];
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }
            $body = (string) ($post['body'] ?? '');
            if ($body === '') {
                continue;
            }
            $title = trim((string) ($post['title'] ?? ''));
            if ($title === '' && preg_match('/^#\s+(.+)$/m', $body, $m)) {
                $title = trim($m[1]);
            }
            $slug = (string) ($post['slug'] ?? '');
            $date = (string) ($post['created'] ?? $post['published'] ?? $post['created_at'] ?? '');
            $entries[] = [
                'title'    => $title !== '' ? $title : ($slug !== '' ? $slug : 'post'),
                'slug'     => $slug !== '' ? $slug : $title,
                'date'     => ($date !== '' ? strtotime($date) : false) ?: time(),
                'tags'     => self::hashtags($body),
                'author'   => '',
                'markdown' => $body, // already Markdown — Porter passes it through
                'source'   => $title !== '' ? $title : ($slug !== '' ? $slug : 'post'),
            ];
        }
        return $entries;
    }

    /** Inline #hashtags are WriteFreely's tags; a leading `# ` heading is not one. */
    private static function hashtags(string $body): array
    {
        preg_match_all('/(?:^|\s)#([a-z0-9][a-z0-9_-]{1,30})\b/i', $body, $m);
        return array_values(array_unique(array_slice($m[1] ?? [], 0, 8)));
    }
}

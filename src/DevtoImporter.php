<?php

namespace Fieldnote;

/**
 * DEV (dev.to) export → normalized Porter entries. docs/importers-spec.md.
 *
 * DEV is Markdown-native. Its data export / API shape is a JSON array of
 * articles (or `{articles:[…]}` / `{data:[…]}`), each with `body_markdown`,
 * `title`, `slug`, `tag_list`, `published_at`, and `cover_image`. The body is
 * passed straight through as Markdown; if it carries a leading frontmatter
 * block (DEV's editor format), that's parsed off for the title/tags. Liquid
 * `{% … %}` embeds pass through as text.
 */
final class DevtoImporter
{
    public static function looksLikeDevto(string $head): bool
    {
        return str_contains($head, '"body_markdown"') || str_contains($head, '"tag_list"');
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
        $articles = array_is_list($json) ? $json : ($json['articles'] ?? $json['data'] ?? []);
        if (!is_array($articles)) {
            return [];
        }

        $entries = [];
        foreach ($articles as $a) {
            if (!is_array($a)) {
                continue;
            }
            $body = (string) ($a['body_markdown'] ?? $a['body'] ?? '');
            if ($body === '') {
                continue;
            }
            $title = trim((string) ($a['title'] ?? ''));
            $tags  = self::tagList($a);

            // DEV's editor stores a frontmatter block inside body_markdown.
            if (str_starts_with(ltrim($body), '---')) {
                [$meta, $rest] = Porter::parseFrontmatter(ltrim($body));
                if ($meta !== []) {
                    $body = $rest;
                    if ($title === '' && ($meta['title'] ?? '') !== '') {
                        $title = trim((string) $meta['title']);
                    }
                    if ($tags === [] && isset($meta['tags'])) {
                        $tags = is_array($meta['tags']) ? $meta['tags'] : preg_split('/[,\s]+/', (string) $meta['tags']);
                    }
                }
            }

            $slug  = (string) ($a['slug'] ?? '');
            $date  = (string) ($a['published_at'] ?? $a['created_at'] ?? '');
            $cover = (string) ($a['cover_image'] ?? $a['social_image'] ?? '');

            $entry = [
                'title'    => $title !== '' ? $title : ($slug !== '' ? $slug : 'post'),
                'slug'     => $slug !== '' ? $slug : $title,
                'date'     => ($date !== '' ? strtotime($date) : false) ?: time(),
                'tags'     => (array) $tags,
                'author'   => trim((string) ($a['user']['name'] ?? '')),
                'markdown' => trim($body),
                'source'   => $title !== '' ? $title : ($slug !== '' ? $slug : 'post'),
            ];
            // Porter::readEntry applies the http(s) rule to every importer.
            if ($cover !== '') {
                $entry['featuredImageUrl'] = $cover;
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    /** tag_list may be an array, or a comma/space string; likewise `tags`. */
    private static function tagList(array $a): array
    {
        foreach (['tag_list', 'tags'] as $key) {
            if (isset($a[$key]) && is_array($a[$key])) {
                return $a[$key];
            }
            if (isset($a[$key]) && is_string($a[$key]) && trim($a[$key]) !== '') {
                return preg_split('/[,\s]+/', trim($a[$key]));
            }
        }
        return [];
    }
}

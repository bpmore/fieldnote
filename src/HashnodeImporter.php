<?php

namespace Fieldnote;

/**
 * Hashnode export → normalized Porter entries. docs/importers-spec.md.
 *
 * Hashnode is Markdown-native. Its export is a JSON array of posts (or a
 * `{posts:[…]}` / `{publication:{posts:[…]}}` wrapper), each with
 * `contentMarkdown`, `title`, `slug`, `tags` (objects with name/slug),
 * `publishedAt`/`dateAdded`, `coverImage`, and `brief` (the deck). The body is
 * passed through as Markdown; the brief is kept as an emphasized lead.
 */
final class HashnodeImporter
{
    public static function looksLikeHashnode(string $head): bool
    {
        return str_contains($head, '"contentMarkdown"');
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
            : ($json['posts'] ?? $json['publication']['posts'] ?? $json['data']['posts'] ?? $json['data'] ?? []);
        if (!is_array($posts)) {
            return [];
        }

        $entries = [];
        foreach ($posts as $p) {
            if (!is_array($p)) {
                continue;
            }
            $body = (string) ($p['contentMarkdown'] ?? $p['content'] ?? '');
            if ($body === '') {
                continue;
            }
            $title = trim((string) ($p['title'] ?? ''));
            $brief = trim((string) ($p['brief'] ?? $p['subtitle'] ?? ''));
            if ($brief !== '' && $brief !== $title) {
                $body = '*' . $brief . "*\n\n" . $body;
            }

            $tags = [];
            foreach ((array) ($p['tags'] ?? []) as $t) {
                $name = is_array($t) ? (string) ($t['name'] ?? $t['slug'] ?? '') : (is_string($t) ? trim($t) : '');
                if ($name !== '') {
                    $tags[] = $name;
                }
            }

            $slug  = (string) ($p['slug'] ?? '');
            $date  = (string) ($p['publishedAt'] ?? $p['dateAdded'] ?? $p['dateUpdated'] ?? '');
            $cover = $p['coverImage'] ?? '';
            $cover = is_array($cover) ? (string) ($cover['url'] ?? '') : (string) $cover;

            $entry = [
                'title'    => $title !== '' ? $title : ($slug !== '' ? $slug : 'post'),
                'slug'     => $slug !== '' ? $slug : $title,
                'date'     => ($date !== '' ? strtotime($date) : false) ?: time(),
                'tags'     => $tags,
                'author'   => trim((string) ($p['author']['name'] ?? '')),
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
}

<?php

namespace Fieldnote;

/**
 * Ghost JSON export → normalized Porter entries. docs/importers-spec.md.
 *
 * A Ghost export is one JSON file: `db[0].data` holds `posts`, `tags`,
 * `posts_tags`, `users`, and `posts_authors`. Bodies use the rendered `html`
 * field (Ghost also stores lexical/mobiledoc, which this v1 doesn't convert —
 * an html-less post imports with an empty body). Tags and author are joined by
 * id. Absolute feature images are localized by Porter; relative
 * `/content/images/...` paths are skipped (no site base in the export).
 */
final class GhostImporter
{
    public static function looksLikeGhost(string $head): bool
    {
        return str_contains($head, '"exported_on"')
            || (str_contains($head, '"db"') && str_contains($head, '"posts"'));
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
        $data = $json['db'][0]['data'] ?? $json['data'] ?? null;
        if (!is_array($data) || empty($data['posts'])) {
            return [];
        }

        $tagName = [];
        foreach ($data['tags'] ?? [] as $t) {
            $tagName[(string) ($t['id'] ?? '')] = (string) ($t['name'] ?? '');
        }
        $postTags = [];
        foreach ($data['posts_tags'] ?? [] as $pt) {
            $pid = (string) ($pt['post_id'] ?? '');
            $tid = (string) ($pt['tag_id'] ?? '');
            if ($pid !== '' && ($tagName[$tid] ?? '') !== '') {
                $postTags[$pid][] = $tagName[$tid];
            }
        }
        $userName = [];
        foreach ($data['users'] ?? [] as $u) {
            $userName[(string) ($u['id'] ?? '')] = (string) ($u['name'] ?? '');
        }
        $postAuthor = [];
        foreach ($data['posts_authors'] ?? [] as $pa) {
            $pid = (string) ($pa['post_id'] ?? '');
            if ($pid !== '' && !isset($postAuthor[$pid])) {
                $postAuthor[$pid] = (string) ($pa['author_id'] ?? '');
            }
        }

        $entries = [];
        foreach ($data['posts'] as $post) {
            if ((string) ($post['type'] ?? 'post') !== 'post' || !empty($post['page'])) {
                continue; // skip pages
            }
            $pid     = (string) ($post['id'] ?? '');
            $title   = trim((string) ($post['title'] ?? ''));
            $date    = (string) ($post['published_at'] ?? $post['created_at'] ?? '');
            $feature = (string) ($post['feature_image'] ?? '');
            $entry = [
                'title'  => $title,
                'slug'   => (string) ($post['slug'] ?? ''),
                'date'   => ($date !== '' ? strtotime($date) : false) ?: time(),
                'tags'   => $postTags[$pid] ?? [],
                'author' => $userName[$postAuthor[$pid] ?? ''] ?? '',
                'html'   => (string) ($post['html'] ?? ''),
                'source' => $title !== '' ? $title : (string) ($post['slug'] ?? ''),
            ];
            // Porter::readEntry applies the http(s) rule to every importer.
            if ($feature !== '') {
                $entry['featuredImageUrl'] = $feature;
            }
            $entries[] = $entry;
        }
        return $entries;
    }
}

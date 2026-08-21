<?php

namespace Fieldnote;

/**
 * Blogger / Blogspot Atom export → normalized Porter entries.
 * docs/importers-spec.md.
 *
 * A Blogger export is one Atom file (GData). Each `<entry>` carries a kind
 * category — `…/g/2005#kind` with term `…/blogger/2008/kind#post` for posts
 * (vs #comment, #page, #template, #settings). Only posts are imported. Label
 * categories (scheme `blogger.com/atom/ns#`) become tags; `content` is HTML.
 * Everything imports as a draft, so the source draft state is ignored.
 */
final class BloggerImporter
{
    public static function looksLikeBlogger(string $head): bool
    {
        return stripos($head, 'blogger') !== false
            && (str_contains($head, '2005/Atom') || str_contains($head, '<feed'));
    }

    /** @return list<array<string,mixed>> */
    public static function parse(string $path): array
    {
        $xml = @simplexml_load_file($path, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        if ($xml === false || $xml->getName() !== 'feed') {
            return [];
        }
        $atom = 'http://www.w3.org/2005/Atom';

        $entries = [];
        foreach ($xml->children($atom)->entry as $entry) {
            $a = $entry->children($atom);

            $isPost = false;
            $tags   = [];
            foreach ($a->category as $cat) {
                $attr   = $cat->attributes();
                $scheme = (string) $attr->scheme;
                $term   = (string) $attr->term;
                if (str_contains($scheme, 'schemas.google.com/g/2005#kind')) {
                    $isPost = str_ends_with($term, '#post');
                } elseif (str_contains($scheme, 'blogger.com/atom/ns') && $term !== '') {
                    $tags[] = $term;
                }
            }
            if (!$isPost) {
                continue;
            }

            $title = trim((string) $a->title);
            $body  = (string) $a->content;
            $date  = (string) $a->published ?: (string) $a->updated;
            $link  = '';
            foreach ($a->link as $l) {
                if ((string) $l->attributes()->rel === 'alternate') {
                    $link = (string) $l->attributes()->href;
                    break;
                }
            }

            $entries[] = [
                'title'  => $title,
                'slug'   => self::slug($link, $title),
                'date'   => ($date !== '' ? strtotime($date) : false) ?: time(),
                'tags'   => $tags,
                'author' => trim((string) $a->author->name),
                'html'   => $body,
                'source' => $title !== '' ? $title : $link,
            ];
        }
        return $entries;
    }

    /** Blogger permalinks end in .html; strip it. Porter slugifies the rest. */
    private static function slug(string $link, string $title): string
    {
        if ($link !== '') {
            $seg = basename(trim((string) parse_url($link, PHP_URL_PATH), '/'));
            $seg = (string) preg_replace('/\.html$/i', '', $seg);
            if ($seg !== '') {
                return $seg;
            }
        }
        return $title;
    }
}

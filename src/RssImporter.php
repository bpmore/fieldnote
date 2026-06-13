<?php

namespace Fieldnote;

/**
 * Generic RSS / Atom feed → normalized Porter entries. docs/importers-spec.md.
 *
 * The universal floor: when there's no dedicated importer for a platform, its
 * feed still gets you the posts. Handles RSS 2.0 (`channel/item`), RSS 1.0 /
 * RDF (top-level `item`), and Atom (`feed/entry`). Bodies are the richest
 * available HTML (`content:encoded` > `description`; Atom `content` >
 * `summary`) — Porter converts them to Markdown. Lossy by nature: many feeds
 * truncate post bodies, so this is a fallback, not a full migration.
 */
final class RssImporter
{
    public static function looksLikeFeed(string $head): bool
    {
        return str_contains($head, '<rss')
            || str_contains($head, '<feed')
            || str_contains($head, 'www.w3.org/2005/Atom')
            || str_contains($head, 'rdf:RDF');
    }

    /** @return list<array<string,mixed>> */
    public static function parse(string $path): array
    {
        $xml = @simplexml_load_file($path, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        if ($xml === false) {
            return [];
        }
        return $xml->getName() === 'feed' ? self::parseAtom($xml) : self::parseRss($xml);
    }

    private static function parseRss(\SimpleXMLElement $xml): array
    {
        $contentNs = 'http://purl.org/rss/1.0/modules/content/';
        $dcNs      = 'http://purl.org/dc/elements/1.1/';
        // RSS 2.0 nests items under channel; RSS 1.0 (RDF) puts them at the root.
        $items = isset($xml->channel->item) ? $xml->channel->item : $xml->item;

        $entries = [];
        foreach ($items as $item) {
            $content = $item->children($contentNs);
            $dc      = $item->children($dcNs);
            $title   = trim((string) $item->title);
            $link    = trim((string) $item->link);
            $body    = trim((string) ($content->encoded ?? ''));
            if ($body === '') {
                $body = (string) $item->description;
            }
            $tags = [];
            foreach ($item->category as $c) {
                $t = trim((string) $c);
                if ($t !== '') {
                    $tags[] = $t;
                }
            }
            $date = (string) $item->pubDate ?: (string) ($dc->date ?? '');
            $img  = self::rssImage($item);
            $entry = [
                'title'  => $title,
                'slug'   => self::slug($link, $title),
                'date'   => ($date !== '' ? strtotime($date) : false) ?: time(),
                'tags'   => array_values(array_unique($tags)),
                'author' => trim((string) ($dc->creator ?? '')) ?: trim((string) $item->author),
                'html'   => $body,
                'source' => $title !== '' ? $title : $link,
            ];
            if ($img !== '') {
                $entry['featuredImageUrl'] = $img;
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    private static function parseAtom(\SimpleXMLElement $xml): array
    {
        // Atom's default namespace makes self-closing <link>/<category> cast
        // falsy, so read everything through the namespaced children directly —
        // no `?:` fallbacks (those would drop the slug link and the tags).
        $atom = 'http://www.w3.org/2005/Atom';

        $entries = [];
        foreach ($xml->children($atom)->entry as $entry) {
            $a     = $entry->children($atom);
            $title = trim((string) $a->title);
            // Attributes on namespaced children must be read via attributes(),
            // not [] (which would look in the Atom namespace and find nothing).
            $link = '';
            foreach ($a->link as $l) {
                $rel = (string) $l->attributes()->rel;
                if ($rel === '' || $rel === 'alternate') {
                    $link = (string) $l->attributes()->href;
                    break;
                }
            }
            $body = trim((string) $a->content);
            if ($body === '') {
                $body = (string) $a->summary;
            }
            $tags = [];
            foreach ($a->category as $c) {
                $t = trim((string) $c->attributes()->term);
                if ($t !== '') {
                    $tags[] = $t;
                }
            }
            $date = (string) $a->published ?: (string) $a->updated;
            $entries[] = [
                'title'  => $title,
                'slug'   => self::slug($link, $title),
                'date'   => ($date !== '' ? strtotime($date) : false) ?: time(),
                'tags'   => array_values(array_unique($tags)),
                'author' => trim((string) $a->author->name),
                'html'   => $body,
                'source' => $title !== '' ? $title : $link,
            ];
        }
        return $entries;
    }

    /** Featured image from an image enclosure or media:content; Porter slugifies the slug. */
    private static function rssImage(\SimpleXMLElement $item): string
    {
        foreach ($item->enclosure as $enc) {
            if (str_starts_with((string) $enc['type'], 'image/')) {
                return (string) $enc['url'];
            }
        }
        $media = $item->children('http://search.yahoo.com/mrss/');
        foreach ($media->content as $m) {
            $attr = $m->attributes();
            if (str_starts_with((string) $attr->type, 'image/') || (string) $attr->medium === 'image') {
                return (string) $attr->url;
            }
        }
        return '';
    }

    /** Prefer the link's last path segment; Porter slugifies whatever we return. */
    private static function slug(string $link, string $title): string
    {
        if ($link !== '') {
            $seg = basename(trim((string) parse_url($link, PHP_URL_PATH), '/'));
            if ($seg !== '' && !is_numeric($seg)) {
                return $seg;
            }
        }
        return $title;
    }
}

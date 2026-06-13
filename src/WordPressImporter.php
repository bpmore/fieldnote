<?php

namespace Fieldnote;

/**
 * WordPress eXtended RSS (WXR) → normalized Porter entries. docs/importers-spec.md.
 *
 * v1 imports published/draft/private/pending posts (not pages, attachments,
 * nav menus, or custom post types). Bodies are the raw `content:encoded`
 * HTML — Porter converts them to Markdown and localizes images. Featured
 * images are resolved from the post's `_thumbnail_id` meta against the
 * attachment items in the same file. Squarespace exports in this format too.
 */
final class WordPressImporter
{
    /** Sniff: does this look like a WordPress WXR export? */
    public static function looksLikeWxr(string $head): bool
    {
        return str_contains($head, 'xmlns:wp=')
            || str_contains($head, 'wordpress.org/export')
            || (str_contains($head, '<wp:') && str_contains($head, '<rss'));
    }

    /** @return list<array<string,mixed>> normalized entries */
    public static function parse(string $xmlPath): array
    {
        // LIBXML_NONET blocks network/XXE; NOCDATA flattens content:encoded.
        $xml = @simplexml_load_file($xmlPath, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        if ($xml === false || !isset($xml->channel)) {
            return [];
        }
        $ns = $xml->getNamespaces(true);
        $wpNs      = $ns['wp']      ?? 'http://wordpress.org/export/1.2/';
        $contentNs = $ns['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
        $dcNs      = $ns['dc']      ?? 'http://purl.org/dc/elements/1.1/';

        $attachments = []; // post_id => url
        $entries     = [];

        foreach ($xml->channel->item as $item) {
            $wp      = $item->children($wpNs);
            $content = $item->children($contentNs);
            $dc      = $item->children($dcNs);
            $type    = (string) $wp->post_type;

            if ($type === 'attachment') {
                $url = (string) $wp->attachment_url;
                if ($url !== '') {
                    $attachments[(string) $wp->post_id] = $url;
                }
                continue;
            }
            if ($type !== 'post') {
                continue; // v1: posts only
            }
            if (!in_array((string) $wp->status, ['publish', 'draft', 'private', 'pending'], true)) {
                continue;
            }

            $tags = [];
            foreach ($item->category as $cat) {
                $domain = (string) $cat['domain'];
                $label  = trim((string) $cat);
                if (($domain === 'post_tag' || $domain === 'category') && $label !== '' && strcasecmp($label, 'Uncategorized') !== 0) {
                    $tags[] = $label;
                }
            }

            $thumbId = '';
            foreach ($wp->postmeta as $meta) {
                if ((string) $meta->meta_key === '_thumbnail_id') {
                    $thumbId = (string) $meta->meta_value;
                }
            }

            $date = (string) $wp->post_date_gmt;
            $ts   = ($date !== '' && !str_starts_with($date, '0000'))
                ? (strtotime($date . ' UTC') ?: null)
                : null;
            $ts ??= strtotime((string) $item->pubDate) ?: time();

            $entries[] = [
                'title'             => trim((string) $item->title),
                'slug'              => (string) $wp->post_name,
                'date'              => $ts,
                'tags'              => array_values(array_unique($tags)),
                'author'            => trim((string) $dc->creator),
                'html'              => (string) $content->encoded,
                'status'            => (string) $wp->status,
                'source'            => trim((string) $item->title) ?: (string) $wp->post_name,
                '_thumbnail_id'     => $thumbId,
            ];
        }

        // Resolve featured images now that all attachments are known.
        foreach ($entries as &$entry) {
            $tid = (string) $entry['_thumbnail_id'];
            if ($tid !== '' && isset($attachments[$tid])) {
                $entry['featuredImageUrl'] = $attachments[$tid];
            }
            unset($entry['_thumbnail_id']);
        }
        unset($entry);

        return $entries;
    }
}

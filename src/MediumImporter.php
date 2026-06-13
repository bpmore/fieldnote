<?php

namespace Fieldnote;

/**
 * Medium export → normalized Porter entries. docs/importers-spec.md.
 *
 * Medium's "Download your information" is a zip with a `posts/` folder of HTML
 * files, one per post, using h-entry microformats: `h1.p-name` (title),
 * `[data-field=body].e-content` (body), `time.dt-published` (date),
 * `a.p-canonical` (URL → slug), `[data-field=subtitle].p-summary` (deck).
 * Filenames prefixed `draft_` are drafts (everything imports as a draft
 * anyway). Bodies are parsed with DOMDocument; Porter converts them to
 * Markdown and localizes images. Medium export carries no tags.
 */
final class MediumImporter
{
    public static function looksLikeMedium(string $zipPath): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }
        $hit = false;
        for ($i = 0; $i < $zip->numFiles && !$hit; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#(?:^|/)posts/.+\.html$#i', $name)) {
                $sample = (string) $zip->getFromIndex($i);
                $hit = str_contains($sample, 'h-entry') || str_contains($sample, 'p-name') || str_contains($sample, 'medium.com');
            }
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
            if (!preg_match('#(?:^|/)posts/([^/]+)\.html$#i', $name, $m)) {
                continue;
            }
            $entry = self::parsePost((string) $zip->getFromIndex($i), $m[1]);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        $zip->close();
        return $entries;
    }

    private static function parsePost(string $html, string $file): ?array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new \DOMXPath($doc);

        $title = self::text($xp, "//h1[contains(@class,'p-name')]");
        if ($title === '') {
            $title = preg_replace('/\s*[–—-]\s*Medium\s*$/u', '', self::text($xp, '//title'));
        }

        $bodyNode = self::node($xp, "//*[@data-field='body']") ?? self::node($xp, "//*[contains(@class,'e-content')]");
        $body = $bodyNode !== null ? self::innerHtml($bodyNode) : '';

        $subtitle = self::text($xp, "//*[@data-field='subtitle']");
        if ($subtitle === '') {
            $subtitle = self::text($xp, "//*[contains(@class,'p-summary')]");
        }
        if ($subtitle !== '' && $subtitle !== $title) {
            $body = '<p><em>' . htmlspecialchars($subtitle, ENT_QUOTES) . '</em></p>' . $body;
        }

        if (trim(strip_tags($body)) === '' && $title === '') {
            return null;
        }

        $dateAttr  = self::attr($xp, "//time[contains(@class,'dt-published')]", 'datetime');
        $canonical = self::attr($xp, "//a[contains(@class,'p-canonical')]", 'href');
        $date = $dateAttr !== '' ? (strtotime($dateAttr) ?: 0) : 0;
        if ($date === 0 && preg_match('/^(\d{4}-\d{2}-\d{2})/', $file, $dm)) {
            $date = strtotime($dm[1]) ?: 0;
        }

        $slug = self::slug($canonical, $file);
        return [
            'title'  => $title !== '' ? $title : $slug,
            'slug'   => $slug,
            'date'   => $date ?: time(),
            'tags'   => [],
            'author' => '',
            'html'   => $body,
            'source' => $title !== '' ? $title : $file,
        ];
    }

    private static function slug(string $canonical, string $file): string
    {
        $cand = '';
        if ($canonical !== '') {
            $cand = basename(trim((string) parse_url($canonical, PHP_URL_PATH), '/'));
        }
        if ($cand === '') {
            $cand = preg_replace('/^(?:draft_|\d{4}-\d{2}-\d{2}_)/', '', $file);
        }
        // Drop Medium's trailing -<hash> id; Porter slugifies the rest.
        return (string) preg_replace('/-[0-9a-f]{8,}$/i', '', $cand);
    }

    private static function node(\DOMXPath $xp, string $query): ?\DOMNode
    {
        $n = $xp->query($query);
        return $n !== false && $n->length > 0 ? $n->item(0) : null;
    }

    private static function text(\DOMXPath $xp, string $query): string
    {
        $node = self::node($xp, $query);
        return $node !== null ? trim($node->textContent) : '';
    }

    private static function attr(\DOMXPath $xp, string $query, string $attr): string
    {
        $node = self::node($xp, $query);
        return $node instanceof \DOMElement ? trim($node->getAttribute($attr)) : '';
    }

    private static function innerHtml(\DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= (string) $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }
}

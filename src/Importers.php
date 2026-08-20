<?php

namespace Fieldnote;

/**
 * The list of platforms the importer understands, in one place.
 *
 * There used to be four: an allowlist, the dry-run dispatch, the confirm
 * dispatch, and the <option> list in the form. The two dispatch tables were
 * the same ten rows differing only in which Porter method they called, and
 * both fell through to the markdown-zip path by default — so an id present in
 * one and missing from the other did not fail, it quietly ran the wrong
 * importer. On a .json or .xml upload that surfaced as "0 created", after the
 * user had already confirmed a dry run listing their posts correctly.
 *
 * The auto-detect chain in the import route is deliberately NOT here. Its
 * order is load-bearing — WriteFreely is sniffed after Ghost and depends on
 * it — and an ordered if/elseif ladder says that plainly, where an array
 * would need a comment explaining that its order is semantic.
 */
final class Importers
{
    /**
     * Platform id => [converter, label]. The converter is a callable taking a
     * file path and returning normalized entries for Porter; the label is what
     * the import form shows.
     *
     * @var array<string,array{0:callable-string|array{0:class-string,1:string},1:string}>
     */
    private const PLATFORMS = [
        'wordpress'   => [[WordPressImporter::class, 'parse'],   'WordPress (.xml export)'],
        'substack'    => [[SubstackImporter::class, 'parse'],    'Substack (.zip export)'],
        'medium'      => [[MediumImporter::class, 'parse'],      'Medium (.zip export)'],
        'notion'      => [[NotionImporter::class, 'parse'],      'Notion (Markdown .zip export)'],
        'ghost'       => [[GhostImporter::class, 'parse'],       'Ghost (.json export)'],
        'writefreely' => [[WriteFreelyImporter::class, 'parse'], 'WriteFreely / Write.as (.json export)'],
        'devto'       => [[DevtoImporter::class, 'parse'],       'DEV / dev.to (.json export)'],
        'hashnode'    => [[HashnodeImporter::class, 'parse'],    'Hashnode (.json export)'],
        'blogger'     => [[BloggerImporter::class, 'parse'],     'Blogger / Blogspot (.xml export)'],
        'rss'         => [[RssImporter::class, 'parse'],         'RSS / Atom feed (file or URL below)'],
    ];

    /** Ids that are not converters: handled by Porter's own archive reader. */
    private const ARCHIVE = 'markdown';

    /** Same wire format, different product name. */
    private const ALIASES = ['squarespace' => 'wordpress'];

    /** Every value the form may legitimately submit, 'auto' aside. */
    public static function isKnown(string $id): bool
    {
        return $id === self::ARCHIVE
            || isset(self::PLATFORMS[$id])
            || isset(self::ALIASES[$id]);
    }

    /** Resolve an alias to the id whose converter actually runs. */
    public static function canonical(string $id): string
    {
        return self::ALIASES[$id] ?? $id;
    }

    /**
     * Parse a file with the converter for $id, or null when $id has none —
     * which means Porter's archive path handles it. Callers branch on null
     * rather than repeating a ten-arm table.
     *
     * @return list<array<string,mixed>>|null
     */
    public static function parse(string $id, string $path): ?array
    {
        $platform = self::PLATFORMS[self::canonical($id)] ?? null;
        return $platform === null ? null : ($platform[0])($path);
    }

    /**
     * Options for the import form, in the order they are offered. Derived so
     * a platform cannot be importable but unofferable, or the reverse.
     *
     * @return array<string,string> id => label
     */
    public static function options(): array
    {
        $options = [
            'auto'        => 'Auto-detect',
            self::ARCHIVE => 'Markdown / Fieldnote export (.zip of .md with frontmatter)',
        ];
        foreach (self::PLATFORMS as $id => [, $label]) {
            $options[$id] = $label;
            foreach (self::ALIASES as $alias => $target) {
                if ($target === $id) {
                    $options[$alias] = ucfirst($alias) . ' (.xml export, ' . explode(' ', $label)[0] . ' format)';
                }
            }
        }
        return $options;
    }
}

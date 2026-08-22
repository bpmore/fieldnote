<?php

namespace Fieldnote;

use SleekDB\Store;

/**
 * Markdown export and import. See docs/roadmap.md item 3.1.
 *
 * Export: a zip of posts as `posts/YYYY-MM-DD-slug.md` with YAML
 * frontmatter, the referenced uploads, and an informational `site.yaml`.
 * Import: the same zip, or any zip of `.md` files with Jekyll/Hugo/Bear
 * style frontmatter. Existing slugs are skipped, never overwritten, so
 * importing the same archive twice cannot duplicate anything.
 *
 * The YAML in play is the tiny frontmatter subset (scalars, inline and
 * block lists); both the emitter and parser are deliberately small rather
 * than pulling in a YAML dependency.
 */
final class Porter
{
    /**
     * Don't chew through hostile archives forever.
     *
     * MAX_ENTRIES counts POSTS, not archive members. It used to bound the
     * member index, which quietly made the real limit depend on how many
     * non-post members happened to sit between the posts — and exportZip
     * writes an image member before each post that has one, so a blog with
     * cover images hit the ceiling at roughly half its post count and the
     * remainder was dropped without a word. MAX_MEMBERS keeps the original
     * protection: an archive of a million tiny files still stops early,
     * it just no longer takes real posts down with it.
     */
    private const MAX_ENTRIES = 2000;
    private const MAX_MEMBERS = 20000;
    private const MAX_MD_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private Store $blogStore,
        private Store $imageStore,
        private ImageHandler $images,
        private string $uploadDir,
    ) {
    }

    // ------------------------------------------------------------- export --

    /** Build the export zip in data/cache; returns its path or null. */
    public function exportZip(array $siteConfig): ?string
    {
        $dir = FN_DATA_DIR . '/cache';
        if (!is_dir($dir) && !(mkdir($dir, 0750, true) || is_dir($dir))) {
            return null;
        }
        $path = $dir . '/export-' . bin2hex(random_bytes(6)) . '.zip';
        $zip  = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        foreach ($this->blogStore->findAll() as $post) {
            $imageZipPath = '';
            if (isset($post['image']) && is_numeric($post['image'])) {
                $record = $this->imageStore->findById((int) $post['image']);
                $rel    = (string) ($record['path'] ?? '');
                // Through ImageHandler, so a legacy absolute path inside the
                // uploads dir exports instead of being silently skipped —
                // which dropped the featured image from the zip, and with it
                // from any re-import.
                $disk   = $this->images->absolutePath($rel);
                if ($disk !== null && is_file($disk)) {
                    $imageZipPath = 'uploads/' . ltrim(str_replace($this->uploadDir, '', $disk), '/');
                    $zip->addFile($disk, $imageZipPath);
                }
            }
            $meta = [
                'title'         => (string) ($post['title'] ?? ''),
                'slug'          => (string) ($post['slug'] ?? ''),
                'date'          => date(DATE_ATOM, (int) ($post['date'] ?? 0)),
                'author'        => (string) ($post['author'] ?? ''),
                'tags'          => array_values((array) ($post['tags'] ?? [])),
                'draft'         => !empty($post['draft']),
                'image'         => $imageZipPath,
                'published_at'  => !empty($post['publishedAt']) ? date(DATE_ATOM, (int) $post['publishedAt']) : '',
                'scheduled_for' => !empty($post['scheduledFor']) ? date(DATE_ATOM, (int) $post['scheduledFor']) : '',
                'password_hash' => (string) ($post['password'] ?? ''),
            ];
            $name = 'posts/' . date('Y-m-d', (int) ($post['date'] ?? 0)) . '-' . $post['slug'] . '.md';
            $zip->addFromString($name, self::emitFrontmatter($meta) . (string) ($post['content'] ?? '') . "\n");
        }

        // Informational snapshot of the site config. Import ignores it (the
        // target install already ran setup); secrets never leave the server.
        $cfg = $siteConfig;
        unset($cfg['password'], $cfg['sessionEpoch']);
        $zip->addFromString('site.yaml', self::emitLines($cfg));

        $zip->close();
        return $path;
    }

    // ------------------------------------------------------------- import --

    /**
     * Inspect a zip without changing anything (the dry-run screen).
     *
     * @return array{posts: list<array{file:string,title:string,slug:string,draft:bool,collision:bool,image:string}>, errors: list<string>}
     */
    /**
     * Walk the markdown posts in an open archive, newest concern first: the
     * .md filter, the size cap, and the post budget live here once instead of
     * being restated by the dry run and the import. They used to be two
     * copies, and import's own comment deferred its correctness to "already
     * reported by analyze" — a promise nothing enforced.
     *
     * Deliberately NOT merged into one method behind a $dryRun flag: these are
     * separate requests with different return types, and a mode flag would
     * only move the branching. What they share is the traversal.
     *
     * @param list<string> $errors collects skip messages, by reference
     * @return \Generator<int, array{0:string, 1:array<string,mixed>}>
     */
    private function eachMarkdownPost(\ZipArchive $zip, array &$errors): \Generator
    {
        $seen    = 0;
        $members = min($zip->numFiles, self::MAX_MEMBERS);
        for ($i = 0; $i < $members && $seen < self::MAX_ENTRIES; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!preg_match('/\.md$/i', $name) || str_ends_with($name, '/')) {
                continue;
            }
            $stat = $zip->statIndex($i);
            if (($stat['size'] ?? 0) > self::MAX_MD_BYTES) {
                $errors[] = "$name: skipped (over 2 MB)";
                continue;
            }
            $seen++;
            yield [$name, $this->entryToPost($name, (string) $zip->getFromIndex($i))];
        }
    }

    public function analyze(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['posts' => [], 'errors' => ['Could not open the archive.']];
        }
        $posts  = [];
        $errors = [];
        foreach ($this->eachMarkdownPost($zip, $errors) as [$name, $post]) {
            $image = (string) $post['imageZipPath'];
            if ($image !== '' && $zip->locateName($image) === false) {
                $image = ''; // referenced but not in the archive
            }
            $posts[] = [
                'file'      => $name,
                'title'     => $post['title'],
                'slug'      => $post['slug'],
                'draft'     => $post['draft'],
                'collision' => $this->blogStore->findOneBy(['slug', '=', $post['slug']]) !== null,
                'image'     => $image,
            ];
        }
        $zip->close();
        if ($posts === [] && $errors === []) {
            $errors[] = 'No .md files found in the archive.';
        }
        return ['posts' => $posts, 'errors' => $errors];
    }

    /**
     * Perform the import. Slug collisions are skipped, never overwritten.
     *
     * @return array{created:int,skipped:int,images:int,errors:list<string>}
     */
    public function import(string $zipPath, array $siteConfig): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['created' => 0, 'skipped' => 0, 'images' => 0, 'errors' => ['Could not open the archive.']];
        }
        $created = $skipped = $importedImages = 0;
        $errors  = [];

        // Oversize skips are reported by analyze, which the user has already
        // seen; collecting them again here would double them up in the result.
        $ignored = [];
        foreach ($this->eachMarkdownPost($zip, $ignored) as [$name, $post]) {
            if ($this->blogStore->findOneBy(['slug', '=', $post['slug']]) !== null) {
                $skipped++;
                continue;
            }

            $record = [
                'title'        => $post['title'],
                'slug'         => $post['slug'],
                'date'         => $post['date'],
                'publishedAt'  => $post['publishedAt'],
                'draft'        => $post['draft'],
                'author'       => $post['author'] !== '' ? $post['author'] : (string) ($siteConfig['author'] ?? ''),
                'tags'         => $post['tags'],
                'content'      => $post['body'],
                'password'     => $post['passwordHash'],
                'scheduledFor' => $post['scheduledFor'],
            ];

            if ($post['imageZipPath'] !== '' && ($bytes = $zip->getFromName($post['imageZipPath'])) !== false) {
                $tmp = tempnam(sys_get_temp_dir(), 'fnimp');
                file_put_contents($tmp, $bytes);
                $stored = $this->images->storeLocalFile($tmp);
                @unlink($tmp);
                if ($stored !== null) {
                    $imageRecord     = $this->imageStore->insert(['url' => $stored[0], 'path' => $stored[1]]);
                    $record['image'] = $imageRecord['_id'];
                    $importedImages++;
                } else {
                    $errors[] = $name . ': featured image could not be ingested';
                }
            }

            $this->blogStore->insert($record);
            $created++;
        }
        $zip->close();
        return ['created' => $created, 'skipped' => $skipped, 'images' => $importedImages, 'errors' => $errors];
    }

    // ------------------------------------------ platform converter pipeline --
    // docs/importers-spec.md. A converter (e.g. WordPressImporter) parses its
    // source format into normalized entries; everything downstream — HTML to
    // Markdown, image localization, dedup, the dry-run, and import-as-draft —
    // lives here, so each converter only has to read its own format.
    //
    // Entry shape: {title, slug?, date:int, tags:string[], author?, html?|
    //               markdown?, featuredImageUrl?, source?}

    /**
     * Read one converter entry into the fields both passes need.
     *
     * The contract used to be this comment and nothing else, re-read by each
     * pass with its own defaults: the dry run showed a missing title as blank
     * while the import titled the post with its slug. Tags were read as
     * fn_parse_tags() on the markdown path and a bare (array) cast here, so
     * "a, b" arrived as one tag named "a, b" on one route and two tags on the
     * other. Neither divergence was visible from either side alone.
     *
     * @param  array<string,mixed> $entry
     * @return array{slug:string, title:string, author:string, date:int, tags:list<string>, markdown:string, image:string, source:string}
     */
    private function readEntry(array $entry): array
    {
        $slug = $this->entrySlug($entry);
        $tags = $entry['tags'] ?? [];
        // A converter may hand tags over as a list or as one comma string.
        $tags = is_string($tags) ? fn_parse_tags($tags) : (array) $tags;

        $image = (string) ($entry['featuredImageUrl'] ?? '');
        // Only absolute http(s) URLs are fetchable. Three of the ten importers
        // checked this for themselves and two did not; SafeHttp rejects the
        // rest anyway, but rejecting here means the dry run and the import
        // agree about whether an entry has an image at all.
        if ($image !== '' && !preg_match('#^https?://#i', $image)) {
            $image = '';
        }

        return [
            'slug'     => $slug,
            'title'    => ((string) ($entry['title'] ?? '')) !== '' ? (string) $entry['title'] : $slug,
            'author'   => (string) ($entry['author'] ?? ''),
            'date'     => (int) ($entry['date'] ?? time()),
            'tags'     => array_slice(array_values(array_unique(array_map(
                static fn ($t): string => fn_slugify((string) $t),
                $tags
            ))), 0, 8),
            'markdown' => $this->entryMarkdown($entry),
            'image'    => $image,
            'source'   => (string) ($entry['source'] ?? $entry['title'] ?? $slug),
        ];
    }

    /**
     * Dry-run for converter entries: title/slug/collision plus a per-post
     * accessibility report (run on the converted Markdown), nothing written.
     *
     * @param  list<array<string,mixed>> $entries
     * @return array{posts: list<array{file:string,title:string,slug:string,draft:bool,collision:bool,image:string,a11y:list<string>}>, errors: list<string>}
     */
    public function analyzeEntries(array $entries): array
    {
        $posts = [];
        foreach (array_slice($entries, 0, self::MAX_ENTRIES) as $e) {
            $entry = $this->readEntry($e);
            $posts[] = [
                'file'      => $entry['source'],
                'title'     => $entry['title'],
                'slug'      => $entry['slug'],
                'draft'     => true, // imports always land as drafts
                'collision' => $this->blogStore->findOneBy(['slug', '=', $entry['slug']]) !== null,
                'image'     => $entry['image'],
                'a11y'      => ContentLint::check($entry['markdown']),
            ];
        }
        return ['posts' => $posts, 'errors' => $posts === [] ? ['Nothing importable found.'] : []];
    }

    /**
     * Import converter entries. Every post lands as a **draft** (so the
     * publish-time accessibility gate applies before it goes public); slug
     * collisions are skipped; featured and inline images are localized into
     * uploads via ImageHandler (SSRF-guarded by SafeHttp). docs/importers-spec.md.
     *
     * @param  list<array<string,mixed>> $entries
     * @return array{created:int,skipped:int,images:int,errors:list<string>}
     */
    public function importEntries(array $entries, array $siteConfig): array
    {
        $created = $skipped = $images = 0;
        $errors  = [];
        foreach (array_slice($entries, 0, self::MAX_ENTRIES) as $e) {
            $entry = $this->readEntry($e);
            if ($this->blogStore->findOneBy(['slug', '=', $entry['slug']]) !== null) {
                $skipped++;
                continue;
            }
            // Inline images are localized on the write pass only, so the dry
            // run's accessibility report reads the same markdown the writer
            // pasted rather than one with rewritten image URLs.
            [$body, $inlined] = $this->localizeInlineImages($entry['markdown']);
            $images += $inlined;

            $record = [
                'title'        => $entry['title'],
                'slug'         => $entry['slug'],
                'date'         => $entry['date'],
                'publishedAt'  => 0,
                'draft'        => true,
                'author'       => $entry['author'] !== '' ? $entry['author'] : (string) ($siteConfig['author'] ?? ''),
                'tags'         => $entry['tags'],
                'content'      => $body,
                'password'     => '',
                'scheduledFor' => 0,
            ];

            if ($entry['image'] !== '') {
                $stored = $this->images->storeFromUrl($entry['image']);
                if ($stored !== null) {
                    $rec = $this->imageStore->insert(['url' => $stored[0], 'path' => $stored[1]]);
                    $record['image'] = $rec['_id'];
                    $images++;
                } else {
                    $errors[] = $entry['title'] . ': featured image could not be fetched';
                }
            }

            $this->blogStore->insert($record);
            $created++;
        }
        return ['created' => $created, 'skipped' => $skipped, 'images' => $images, 'errors' => $errors];
    }

    /** Markdown for one entry: passthrough if already Markdown, else HTML to Markdown. */
    private function entryMarkdown(array $entry): string
    {
        if (isset($entry['markdown'])) {
            return rtrim((string) $entry['markdown'], "\n");
        }
        $html = (string) ($entry['html'] ?? '');
        return $html === '' ? '' : self::htmlToMarkdown($html);
    }

    private static function htmlToMarkdown(string $html): string
    {
        $conv = new \League\HTMLToMarkdown\HtmlConverter([
            'strip_tags'   => true,
            'hard_break'   => true,
            'remove_nodes' => 'script style',
            'use_autolinks' => false,
        ]);
        if (class_exists(\League\HTMLToMarkdown\Converter\TableConverter::class)) {
            $conv->getEnvironment()->addConverter(new \League\HTMLToMarkdown\Converter\TableConverter());
        }
        return trim($conv->convert($html));
    }

    /** Download remote inline images into uploads and rewrite the Markdown to the local copy. */
    private function localizeInlineImages(string $md): array
    {
        $count = 0;
        $out = preg_replace_callback(
            '/!\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/i',
            function (array $m) use (&$count): string {
                $stored = $this->images->storeFromUrl($m[2]);
                if ($stored === null) {
                    return $m[0]; // leave the original URL; noted as a non-fatal gap
                }
                $count++;
                return '![' . $m[1] . '](' . $stored[0] . ')';
            },
            $md
        );
        return [(string) $out, $count];
    }

    private function entrySlug(array $entry): string
    {
        $slug = (string) ($entry['slug'] ?? '');
        return $slug !== ''
            ? fn_slugify(basename(trim($slug, '/')))
            : fn_slugify((string) ($entry['title'] ?? 'post'));
    }

    // -------------------------------------------------- entry normalization --

    /**
     * Normalize one .md entry to Fieldnote's post shape, mapping Jekyll /
     * Hugo / Bear frontmatter conventions and our own export keys.
     *
     * @return array{title:string,slug:string,date:int,publishedAt:int,draft:bool,author:string,tags:string[],body:string,imageZipPath:string,passwordHash:string,scheduledFor:int}
     */
    private function entryToPost(string $name, string $raw): array
    {
        [$meta, $body] = self::parseFrontmatter($raw);

        $fileDate = null;
        $fileSlug = '';
        if (preg_match('/(\d{4}-\d{2}-\d{2})-(.+)\.md$/i', basename($name), $m)) {
            $fileDate = strtotime($m[1] . ' 12:00:00') ?: null;
            $fileSlug = $m[2];
        }

        $title = trim((string) ($meta['title'] ?? ''));
        if ($title === '' && preg_match('/^#\s+(.+)$/m', $body, $m)) {
            $title = trim($m[1]);
        }
        if ($title === '') {
            $title = $fileSlug !== '' ? ucfirst(str_replace('-', ' ', $fileSlug)) : basename($name, '.md');
        }

        $slugSource = (string) ($meta['slug'] ?? $meta['permalink'] ?? '');
        $slug = $slugSource !== ''
            ? fn_slugify(basename(trim($slugSource, '/')))
            : fn_slugify($fileSlug !== '' ? $fileSlug : $title);

        $date = self::toTime($meta['date'] ?? $meta['published_date'] ?? null) ?? $fileDate ?? time();

        if (array_key_exists('draft', $meta)) {
            $draft = (bool) $meta['draft'];
        } elseif (array_key_exists('published', $meta)) {
            $draft = !$meta['published'];
        } else {
            $draft = false;
        }

        $tags = $meta['tags'] ?? $meta['categories'] ?? [];
        if (is_string($tags)) {
            $tags = fn_parse_tags($tags);
        } else {
            $tags = array_slice(array_values(array_unique(array_map(
                static fn ($t): string => fn_slugify((string) $t),
                (array) $tags
            ))), 0, 8);
        }

        $image = trim((string) ($meta['image'] ?? $meta['cover'] ?? $meta['cover_image'] ?? $meta['feature_image'] ?? ''));
        if ($image !== '' && preg_match('#^[a-z]+://#i', $image)) {
            $image = ''; // remote URLs are never fetched during import
        }

        $publishedAt = self::toTime($meta['published_at'] ?? null) ?? ($draft ? 0 : $date);

        return [
            'title'        => $title,
            'slug'         => $slug,
            'date'         => $date,
            'publishedAt'  => $publishedAt,
            'draft'        => $draft,
            'author'       => trim((string) ($meta['author'] ?? '')),
            'tags'         => $tags,
            // Trailing newlines are markdown-insignificant; dropping them
            // makes export -> import byte-identical for the body.
            'body'         => rtrim($body, "\n"),
            'imageZipPath' => ltrim($image, '/'),
            'passwordHash' => (string) ($meta['password_hash'] ?? ''),
            'scheduledFor' => self::toTime($meta['scheduled_for'] ?? null) ?? 0,
        ];
    }

    private static function toTime(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : $ts;
    }

    // ----------------------------------------------------- tiny YAML subset --

    /** @param array<string,mixed> $meta */
    public static function emitFrontmatter(array $meta): string
    {
        return "---\n" . self::emitLines($meta) . "---\n\n";
    }

    /** @param array<string,mixed> $map */
    private static function emitLines(array $map): string
    {
        $out = '';
        foreach ($map as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_bool($value)) {
                $out .= $key . ': ' . ($value ? 'true' : 'false') . "\n";
            } elseif (is_int($value) || is_float($value)) {
                $out .= $key . ': ' . $value . "\n";
            } else {
                // json_encode of a string or array is valid YAML flow syntax.
                $out .= $key . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        return $out;
    }

    /**
     * Parse `--- ... ---` frontmatter: scalars, inline [a, b] lists, and
     * block `- item` lists. Anything fancier passes through as a string.
     *
     * @return array{0: array<string,mixed>, 1: string} [meta, body]
     */
    public static function parseFrontmatter(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        if (!str_starts_with($raw, "---\n")) {
            return [[], $raw];
        }
        $end = strpos($raw, "\n---\n", 4);
        if ($end !== false) {
            $bodyStart = $end + 5;
        } elseif (str_ends_with($raw, "\n---")) {
            $end       = strlen($raw) - 4; // closing fence at EOF
            $bodyStart = strlen($raw);
        } else {
            return [[], $raw];
        }
        $block = substr($raw, 4, $end - 4);
        $body  = ltrim(substr($raw, $bodyStart), "\n");

        $meta    = [];
        $lastKey = null;
        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $m)) {
                $lastKey        = strtolower($m[1]);
                $meta[$lastKey] = self::scalar(trim($m[2]));
            } elseif ($lastKey !== null && preg_match('/^\s+-\s*(.+)$/', $line, $m)) {
                if (!is_array($meta[$lastKey])) {
                    $meta[$lastKey] = $meta[$lastKey] === '' ? [] : [$meta[$lastKey]];
                }
                $meta[$lastKey][] = self::scalar(trim($m[1]));
            }
        }
        return [$meta, $body];
    }

    private static function scalar(string $value): mixed
    {
        if ($value === '') {
            return '';
        }
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if ($value[0] === '[' || $value[0] === '"') {
            $decoded = json_decode($value, true);
            if ($decoded !== null) {
                return $decoded;
            }
            // Tolerate unquoted inline lists: [a, b]
            if ($value[0] === '[') {
                return array_map('trim', explode(',', trim($value, '[] ')));
            }
        }
        if ($value[0] === "'" && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }
        return $value;
    }
}

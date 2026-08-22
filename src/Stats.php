<?php

namespace Fieldnote;

/**
 * Cookie-less, JS-less view counting with a hard privacy guarantee:
 * no IP address or user agent is ever written to disk, and views cannot be
 * correlated across days.
 *
 * How: each day gets a random salt. A visitor is sha256(salt|ip|ua) — used
 * only to deduplicate within that day. The salt and the dedup set are
 * deleted when the day ends, leaving only {slug: count} aggregates. Without
 * the salt, the hashes are not invertible or comparable to anything.
 *
 * Salt and dedup set live in one file, `.day-<date>.json`. They are one fact
 * with one lifetime — hashes made under a salt mean nothing without it — and
 * splitting them across two files meant two reads, two writes, two prune arms
 * (which did not even match each other: one compared with str_ends_with, the
 * other with str_contains), and a state where a seen set outlived its salt and
 * went on deduplicating against hashes nothing could produce any more.
 */
final class Stats
{
    private string $dir;

    public function __construct(string $dataDir)
    {
        $this->dir = rtrim($dataDir, '/') . '/stats';
    }

    /** Count one view of $slug, deduplicated per visitor per day. */
    public function record(string $slug): void
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        // No UA or an obvious agent: feed readers, crawlers, and scripts
        // aren't readers. Heuristic, intentionally conservative.
        if ($slug === '' || $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|monitor|feed|curl|wget|python|http[s]?client/i', $ua)) {
            return;
        }
        if (!is_dir($this->dir) && !(mkdir($this->dir, 0750, true) || is_dir($this->dir))) {
            return;
        }

        $day     = date('Y-m-d');
        $dayFile = $this->dir . '/.day-' . $day . '.json';
        $state   = $this->readJson($dayFile);
        $salt    = (string) ($state['salt'] ?? '');
        $seen    = (array) ($state['seen'] ?? []);
        if ($salt === '') {
            $salt = bin2hex(random_bytes(16));
            // Any dedup keys found beside a missing salt were made under a salt
            // that is gone, so they can never match again. Dropping them says
            // that, instead of carrying a set that only looks meaningful.
            $seen = [];
            $this->prune(); // first view of a new day sweeps old day state
        }

        $visitor = substr(hash('sha256', $salt . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $ua), 0, 16);
        $key     = $visitor . ':' . $slug;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = 1;
        $this->writeJson($dayFile, ['salt' => $salt, 'seen' => $seen]);

        $countFile = $this->dir . '/' . $day . '.json';
        $counts    = $this->readJson($countFile);
        $counts[$slug] = (int) ($counts[$slug] ?? 0) + 1;
        $this->writeJson($countFile, $counts);
    }

    /** @return array<string,mixed> */
    private function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        return (array) json_decode((string) file_get_contents($file), true);
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $file, array $data): void
    {
        @file_put_contents($file, json_encode($data), LOCK_EX);
        @chmod($file, 0640);
    }

    /**
     * Aggregate counts for the last $days days.
     *
     * @return array<string,int> slug => views
     */
    public function totals(int $days = 30): array
    {
        $totals = [];
        for ($i = 0; $i < $days; $i++) {
            $file = $this->dir . '/' . date('Y-m-d', time() - $i * 86400) . '.json';
            if (!is_file($file)) {
                continue;
            }
            foreach ((array) json_decode((string) file_get_contents($file), true) as $slug => $count) {
                $totals[(string) $slug] = ($totals[(string) $slug] ?? 0) + (int) $count;
            }
        }
        arsort($totals);
        return $totals;
    }

    /**
     * Drop day state from previous days (the privacy guarantee) and aggregate
     * files older than 90 days (retention).
     *
     * The two legacy shapes go unconditionally, today's included: a salt that
     * lives in `.salt-<date>` is one this class no longer reads, so keeping it
     * would be keeping a raw salt around for nothing. The visible cost is that
     * an upgrade mid-day restarts that day's deduplication, so a reader who
     * already visited can be counted once more. Nothing carries across days
     * either way, which is the point of the design.
     */
    private function prune(): void
    {
        $today = date('Y-m-d');
        foreach (glob($this->dir . '/.day-*.json') ?: [] as $file) {
            if (basename($file) !== '.day-' . $today . '.json') {
                @unlink($file);
            }
        }
        // array_merge, not +: both globs are 0-indexed lists, so + would keep only
        // the longer one's overlapping keys and silently leave files behind.
        foreach (array_merge(glob($this->dir . '/.salt-*') ?: [], glob($this->dir . '/.seen-*.json') ?: []) as $file) {
            @unlink($file);
        }
        $cutoff = date('Y-m-d', time() - 90 * 86400);
        foreach (glob($this->dir . '/[0-9]*.json') ?: [] as $file) {
            if (basename($file, '.json') < $cutoff) {
                @unlink($file);
            }
        }
    }
}

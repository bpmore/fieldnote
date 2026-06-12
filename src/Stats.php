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

        $day      = date('Y-m-d');
        $saltFile = $this->dir . '/.salt-' . $day;
        $salt     = is_file($saltFile) ? (string) file_get_contents($saltFile) : '';
        if ($salt === '') {
            $salt = bin2hex(random_bytes(16));
            @file_put_contents($saltFile, $salt, LOCK_EX);
            @chmod($saltFile, 0640);
            $this->prune(); // first view of a new day sweeps old salts/dedup sets
        }

        $visitor  = substr(hash('sha256', $salt . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $ua), 0, 16);
        $seenFile = $this->dir . '/.seen-' . $day . '.json';
        $seen     = is_file($seenFile) ? (array) json_decode((string) file_get_contents($seenFile), true) : [];
        $key      = $visitor . ':' . $slug;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = 1;
        @file_put_contents($seenFile, json_encode($seen), LOCK_EX);
        @chmod($seenFile, 0640);

        $countFile = $this->dir . '/' . $day . '.json';
        $counts    = is_file($countFile) ? (array) json_decode((string) file_get_contents($countFile), true) : [];
        $counts[$slug] = (int) ($counts[$slug] ?? 0) + 1;
        @file_put_contents($countFile, json_encode($counts), LOCK_EX);
        @chmod($countFile, 0640);
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
     * Drop salts and dedup sets from previous days (the privacy guarantee)
     * and aggregate files older than 90 days (retention).
     */
    private function prune(): void
    {
        $today = date('Y-m-d');
        foreach (glob($this->dir . '/.salt-*') ?: [] as $file) {
            if (!str_ends_with($file, $today)) {
                @unlink($file);
            }
        }
        foreach (glob($this->dir . '/.seen-*.json') ?: [] as $file) {
            if (!str_contains($file, $today)) {
                @unlink($file);
            }
        }
        $cutoff = date('Y-m-d', time() - 90 * 86400);
        foreach (glob($this->dir . '/[0-9]*.json') ?: [] as $file) {
            if (basename($file, '.json') < $cutoff) {
                @unlink($file);
            }
        }
    }
}

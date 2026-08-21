<?php

namespace Fieldnote;

/**
 * Optional TOTP second factor for the admin login.
 *
 * State lives in data/totp.php, outside the web root, separate from the main
 * config: the lost-authenticator escape hatch for a self-hoster is simply
 * deleting that file over SSH, which reverts login to password-only.
 *
 * Replay protection: the time-step counter of every accepted code is
 * persisted, and a code is only accepted if its counter is strictly newer —
 * an intercepted code can never be used a second time.
 */
final class TwoFactor
{
    private string $file;

    public function __construct(string $dataDir)
    {
        // JSON, not PHP: this file is rewritten on every accepted code (the
        // replay counter), and a require()'d PHP file can be served stale by
        // OPcache for up to revalidate_freq seconds after a write — which
        // would reopen the replay window the counter exists to close.
        $this->file = rtrim($dataDir, '/') . '/totp.json';
    }

    public function enabled(): bool
    {
        return is_file($this->file);
    }

    /**
     * Turn 2FA on and return the recovery codes to show the admin once.
     *
     * The codes are generated, normalized and hashed here rather than by the
     * caller. The old signature took ready-made hashes and asked a docblock to
     * enforce that they were hashes, of normalized codes, from the same batch
     * just shown to the admin. Nothing checked any of it: hashing an
     * un-normalized code left a recovery code that could never match, and the
     * failure only surfaced years later, on the day someone had lost their
     * phone and reached for it.
     *
     * @return string[]|null plain codes, or null when the write failed
     */
    public function enable(string $secretB32, int $recoveryCount = 8): ?array
    {
        $plain  = self::generateRecoveryCodes($recoveryCount);
        $hashes = array_map(
            static fn (string $code): string => password_hash(self::normalizeRecoveryCode($code), PASSWORD_DEFAULT),
            $plain
        );
        return $this->save([
            'secret'      => $secretB32,
            'lastCounter' => 0,
            'recovery'    => $hashes,
        ]) ? $plain : null;
    }

    public function disable(): bool
    {
        return !$this->enabled() || unlink($this->file);
    }

    /**
     * Accept a current TOTP code or an unspent recovery code, consuming
     * whichever matched.
     *
     * Callers used to compose this themselves as verifyTotp() || useRecovery(),
     * at every call site, with the order load-bearing and stated nowhere: one
     * that checked only half would silently drop the other. Each half also
     * loaded and saved the file independently, so two branches of one logical
     * operation raced over a document that is written whole.
     */
    public function verify(string $code): bool
    {
        return $this->verifyTotp($code) || $this->useRecoveryCode($code);
    }

    /** Replay-protected TOTP check; persists the consumed counter. */
    private function verifyTotp(string $code): bool
    {
        $state = $this->load();
        if ($state === null) {
            return false;
        }
        $counter = Totp::verify((string) $state['secret'], $code);
        if ($counter === null || $counter <= (int) $state['lastCounter']) {
            return false;
        }
        $state['lastCounter'] = $counter;
        return $this->save($state);
    }

    /** Check a one-time recovery code and consume it on success. */
    private function useRecoveryCode(string $code): bool
    {
        $state = $this->load();
        if ($state === null) {
            return false;
        }
        $code = self::normalizeRecoveryCode($code);
        if ($code === '') {
            return false;
        }
        foreach ($state['recovery'] as $i => $hash) {
            if (password_verify($code, (string) $hash)) {
                unset($state['recovery'][$i]);
                $state['recovery'] = array_values($state['recovery']);
                return $this->save($state);
            }
        }
        return false;
    }

    public function recoveryCodesLeft(): int
    {
        return count($this->load()['recovery'] ?? []);
    }

    /** @return string[] plain codes to show the admin exactly once */
    private static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        return $codes;
    }

    private static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $code) ?? '');
    }

    /** @return array{secret:string,lastCounter:int,recovery:string[]}|null */
    private function load(): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->file), true);
        return is_array($data) ? $data : null;
    }

    private function save(array $data): bool
    {
        // _comment is for the admin reading the file over SSH.
        $data = ['_comment' => 'Fieldnote two-factor state. Delete this file to '
            . 'fall back to password-only login (lost-authenticator recovery).'] + $data;

        $tmp = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        return rename($tmp, $this->file);
    }
}

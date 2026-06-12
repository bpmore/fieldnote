# Spec: Passkey (WebAuthn) login

Status: proposed (June 2026)

## Problem

Login is password + optional TOTP. That's solid, but passwords still get
phished, reused, and forgotten — and the modern answer is a passkey: a
device-bound credential that can't be phished (origin-bound), can't be
guessed, and syncs through the platforms users already have (iCloud
Keychain, Google Password Manager, 1Password). "Passkey login on a
flat-file blog you host yourself" extends the security story the same way
TOTP did.

## Goals

- Sign in with a passkey alone (usernameless, discoverable credential) —
  Touch ID / Face ID / security key, no password typed
- Password (+ TOTP) remains a full fallback; passkeys are additive
- Several passkeys per site (one per device), labeled, individually
  removable
- Self-hoster recovery story as simple as TOTP's: delete a file over SSH
- No new heavyweight dependencies; no JS on the public surface (admin
  pages already ship JS, so the WebAuthn calls live there)

## Non-goals

- Multi-account / usernames (single-admin model is unchanged — the
  decision record is the 2026-06-12 discussion: usernames add friction,
  not security, while there is exactly one account)
- Replacing the password entirely (lockout risk for self-hosters)
- Attestation verification (we don't care which vendor made the
  authenticator; `attestation: 'none'`)

## Design

### Library

`lbuchs/webauthn` — zero dependencies, small surface, handles the CBOR/
COSE parsing and signature verification that should not be hand-rolled.
(`web-auth/webauthn-lib` rejected: drags in a Symfony dependency tree
heavier than the rest of Fieldnote combined.)

### Storage: `data/passkeys.json`

```json
{ "credentials": [ { "id": "<base64url>", "publicKey": "<pem>",
    "signCount": 12, "label": "MacBook Touch ID", "createdAt": 1765000000 } ] }
```

Same lifecycle as `data/totp.json`: outside the web root, JSON because it
rewrites on every login (sign-count), and **deleting it over SSH disables
passkey login** — the lost-device escape hatch. A `Passkeys` class mirrors
`TwoFactor`'s shape (enabled / list / add / remove / verify).

### Relying-party binding (important caveat)

RP ID = the site's host, derived per-request. Passkeys are origin-bound:
**changing the site's domain orphans every registered passkey** (they
simply stop matching; password login is unaffected). Settings shows a
warning next to the domain field when passkeys exist, and the README
documents it. This is inherent to WebAuthn, not a Fieldnote choice.

### Routes

```
POST /settings/passkeys/options   -> create-options JSON (auth + CSRF)
POST /settings/passkeys/register  -> verify + store credential (auth + CSRF)
POST /settings/passkeys/delete    -> remove one credential (auth + CSRF)
POST /login/passkey/options       -> request-options JSON (public, CSRF-exempt
                                     GET-equivalent; challenge into session)
POST /login/passkey/verify        -> assertion verification; on success the
                                     same ritual as the other logins:
                                     regenerate(), clearLoginFailures(),
                                     isAuthenticated, stampSessionEpoch()
```

- Challenges: 32 random bytes in `$_SESSION`, single-use, 2-minute TTL.
- `/login/passkey/verify` shares the login throttle (`recordLoginFailure`
  on bad assertions) so signature-guessing is rate-limited like passwords.
- Sign-count regression (cloned authenticator) rejects the assertion and
  records a failure.
- Passkey login bypasses TOTP by design: the authenticator's user
  verification (biometric/PIN) IS the second factor (possession +
  inherence/knowledge).

### UI

- Settings gains a "Passkeys" fieldset (pattern: the 2FA one): list with
  label + created date + remove button, an "Add a passkey" button, and a
  label input. Registration JS in `static/passkeys.js` (CSP `'self'` —
  WebAuthn needs no external resources).
- Login page: a "Sign in with a passkey" button above the password form,
  rendered only when `data/passkeys.json` exists. Progressive: without
  JS or WebAuthn support the password form is untouched.

### Session epoch interplay

Passkey logins stamp the session epoch exactly like password logins, so a
password rotation still kills passkey-minted sessions. Removing the LAST
passkey does not rotate the epoch (the password didn't change).

## Security notes

- Public `options` endpoint reveals that passkeys exist (credential ids in
  `allowCredentials` are not used — discoverable credentials, empty allow
  list — so nothing identifying leaks).
- Origin and RP ID hash are verified by the library against the request
  host; behind a proxy this uses the configured domain when set.
- `data/passkeys.json` is written atomically (TwoFactor::save pattern).

## Acceptance criteria

1. Register a passkey on Safari/Touch ID and Chrome/Android; both appear
   labeled in Settings and either can sign in alone, landing on the
   dashboard with a stamped session epoch
2. Deleting `data/passkeys.json` over SSH removes the login button and
   passkey login attempts fail; password + TOTP login unaffected
3. Bad assertion / replayed challenge / sign-count regression → generic
   failure + throttle hit; five failures lock passkey AND password login
4. Changing the password logs out passkey-minted sessions (epoch)
5. Smoke test: endpoints return well-formed options JSON; verify path
   unit-tested against fixture assertions (full WebAuthn can't be driven
   by curl — use the library's test vectors)
6. Public pages still ship zero JS; `php bin/audit-themes.php` 70/70

## Implementation order

1. `composer require lbuchs/webauthn`; `src/Passkeys.php` storage class
2. Login-side routes + throttle integration (verify with library test vectors)
3. Registration routes + settings UI + `static/passkeys.js`
4. Login button + progressive enhancement
5. Domain-change warning, README, smoke checks

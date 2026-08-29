# Authentication & Tokens

Loxodontu has three separate token systems, all sharing the same underlying mechanism but scoped
to different callers.

| Token | Header | Issued by | Typical caller |
| ----- | ------ | --------- | ---------------- |
| Platform token | `Authorization: Bearer <token>` | `POST /auth/login` | You, managing your own account, projects, and tables |
| Project API key | `Authorization: Bearer <key>` | `POST /projects/{id}/keys` | Your application's backend, calling REST passthrough or edge functions |
| End-user token | `X-User-Token: <token>` | `POST /{project_id}/auth/login` | Your application's own end users |

## How tokens are generated and stored

Every token is `bin2hex(random_bytes(32))` — 64 hex characters of cryptographically random data.
The plaintext value is returned to the caller exactly once, at issuance; only its SHA-256 hash is
stored in the database, and comparisons on every request use `hash_equals()` to avoid timing
attacks. There is no way to retrieve a lost token or key — issue a new one instead.

Platform tokens and end-user tokens expire **30 days** after issuance; there is currently no
refresh mechanism, so callers need to log in again once a token expires. Project API keys have no
forced expiry unless you set one yourself.

## Platform tokens

Obtained from `POST /auth/login` (see [Platform Auth](platform-auth.md)), sent as
`Authorization: Bearer <token>`. Validated on every request: token hash lookup, then an
`expires_at > NOW()` check. A valid token resolves to `{ id, name, email }`, available to
controllers as the authenticated user.

## Project API keys

Created via `POST /projects/{id}/keys` (see [API Keys & RLS](keys-and-rls.md)). The key itself is
shown once, at creation — only its `key_prefix` (the first 8 hex characters, used for fast lookup)
and its SHA-256 hash are persisted.

Each key carries a `permissions` list, a subset of:

- `select`
- `insert`
- `update`
- `delete`
- `function`

A REST passthrough or edge function request is only allowed to do what the key's permissions
cover — there's no implicit "admin" project key. Keys can also carry an optional `expires_at` (must
be a future datetime when set).

## End-user tokens

A project's own end users (separate from platform accounts) register and log in under
`/{project_id}/auth/*` (see [End Users](end-users.md)), then authenticate REST passthrough and
edge function calls by adding `X-User-Token: <token>` alongside the project API key. Omitting it
means an anonymous caller — any RLS condition referencing `$auth.*` never matches for anonymous
requests.

## Password requirements

Both platform accounts and end users require a password of **at least 8 characters** — there is no
enforced complexity (no required mix of case, digits, or symbols). Passwords are hashed with PHP's
`password_hash()` using `PASSWORD_DEFAULT` (bcrypt as of PHP 8.2/8.3).

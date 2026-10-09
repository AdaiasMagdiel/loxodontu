# End users

A project's own app users, separate from platform accounts.

| Method | Route                                          | Auth              | Description                          |
| ------ | ------------------------------------------------| ------------------ | -------------------------------------- |
| POST   | `/{project_id}/auth/register`                   | —                  | Register an end user (`{ email, password }`) |
| POST   | `/{project_id}/auth/login`                      | —                  | Get an end-user token                  |
| POST   | `/{project_id}/auth/logout`                     | end-user token     | Invalidate the current end-user token  |
| POST   | `/{project_id}/auth/magic-link`                 | —                  | Request a magic-link sign-in email (`{ email, redirect_url? }`) |
| POST   | `/{project_id}/auth/magic-link/consume`         | —                  | Exchange a magic-link token for a session (`{ token }`) |
| POST   | `/{project_id}/auth/password/forgot`            | —                  | Request a password reset email (`{ email, redirect_url? }`) |
| POST   | `/{project_id}/auth/password/reset`             | —                  | Set a new password (`{ token, password }`) |
| POST   | `/{project_id}/auth/verify/resend`              | —                  | Resend the email confirmation link (`{ email, redirect_url? }`) |
| POST   | `/{project_id}/auth/verify/confirm`             | —                  | Confirm an email address (`{ token }`) |
| POST   | `/{project_id}/auth/email-change/request`       | end-user token     | Request an email change (`{ new_email, redirect_url? }`) |
| POST   | `/{project_id}/auth/email-change/confirm`       | —                  | Confirm the new email address (`{ token }`) |
| GET    | `/projects/{id}/end-users`                      | platform token     | List a project's end users             |
| PATCH  | `/projects/{id}/end-users/{end_user_id}`        | platform token     | Grant/clear a role (`{ role }`, `null` clears it) |
| DELETE | `/projects/{id}/end-users/{end_user_id}`        | platform token     | Remove an end user                     |

## Magic link, password reset, and email verification

These flows follow a request/consume pattern: the "request" endpoint always
responds `200` whether or not the email is registered, so it can never be
used to enumerate accounts. It issues a single-use, purpose-scoped token and
emails a link built as `{redirect_url}?token={token}` — the developer's own
frontend reads `token` from that link and calls the matching
"confirm"/"reset"/"consume" endpoint.

### Allowed redirect URLs

Because the emailed link carries a live token, `redirect_url` must match the
project's **Site URL** or one of its **allowed redirect URLs**; anything else
is rejected with `422`, before any email is sent. Without this, anyone could
request a password reset for someone else's address with their own site as
the target and collect the token. When `redirect_url` is omitted, the link
uses the Site URL (or `APP_URL` if no Site URL is set).

A requested URL matches an entry when the scheme, host and port are identical
and its path is the entry's path or below it: `https://app.com/auth` allows
`https://app.com/auth/callback`, but not `https://app.com/authx`,
`http://app.com/auth` or `https://app.com.evil.net/auth`. There are no
wildcards. Configure them in the dashboard (**Auth → Settings**) or via:

| Method | Route                              | Auth           | Description |
| ------ | ---------------------------------- | -------------- | ----------- |
| GET    | `/projects/{id}/auth/settings`     | platform token | `{ site_url, redirect_urls }` |
| PUT    | `/projects/{id}/auth/settings`     | platform token | Update either field (`site_url`: URL or `null`; `redirect_urls`: up to 20 absolute http/https URLs) |

Tokens expire quickly: 15 minutes for magic links, 30 minutes for password
resets and email changes, 1 day for email verification.

If a project has no email provider configured yet (see
[Email configuration](./email-auth.md)), these endpoints still succeed and
issue a token — they just have nothing to send it with.

The endpoints that send email (magic link, password reset, resend
verification and email change) are rate limited per project: at most 5
requests per recipient address and 20 per client IP per hour. Every request
counts, registered address or not, so the limit never reveals whether an
account exists. Over the limit they respond `429` with a `Retry-After` header.

## Requiring email confirmation

A project can require end users to confirm their email before they're
allowed to log in — see [Email configuration](./email-auth.md) for the
`require_email_confirmation` setting. With it enabled:

- `register` sends a verification email and returns `{ token: null, user, email_verification_required: true }` instead of an active session.
- `login` returns `403` until `verify/confirm` has been called for that account.

## Changing an end user's email

`email-change/request` requires the current end-user's `X-User-Token` and
sends the confirmation link to the **new** address, not the old one —
clicking it is itself the proof of ownership. `email-change/confirm` then
applies the change and marks the new address verified.

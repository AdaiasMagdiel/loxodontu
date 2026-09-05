# Email configuration & templates

Each project configures its own outbound email — either a generic SMTP server
or the [Resend](https://resend.com) API — plus its own overridable email
templates, used by the [end-user auth flows](end-users.md) (magic link,
password reset, email verification, email change). Everything here is
platform-owner-only; end users never see these routes.

## Provider configuration

| Method | Route                                       | Description                                    |
| ------ | ---------------------------------------------| ------------------------------------------------|
| GET    | `/projects/{id}/auth/email-config`          | Show the current config (secrets never included) |
| PUT    | `/projects/{id}/auth/email-config`          | Create/update the config                        |
| POST   | `/projects/{id}/auth/email-config/test`     | Send a test email through the configured provider (`{ to }`) |

`PUT` body:

```json
{
  "provider": "smtp",
  "from_address": "noreply@yourapp.com",
  "from_name": "Your App",
  "smtp_host": "smtp.yourapp.com",
  "smtp_port": 587,
  "smtp_username": "apikey",
  "smtp_encryption": "tls",
  "smtp_password": "••••••••",
  "require_email_confirmation": false
}
```

For `"provider": "resend"`, send `resend_api_key` instead of the `smtp_*`
fields. `smtp_password` / `resend_api_key` are encrypted at rest (AES-256-GCM,
keyed by `APP_KEY` — see [Configuration](../configuration.md)) and never
echoed back by `GET`/`PUT`; the response instead reports `has_smtp_password`
/ `has_resend_api_key` booleans. Omitting the secret field on an update
**keeps the existing value** — send an empty string to clear it.

`require_email_confirmation` gates end-user login until their email is
verified — see [End users](end-users.md#requiring-email-confirmation).

## Templates

| Method | Route                                             | Description                          |
| ------ | ---------------------------------------------------| --------------------------------------|
| GET    | `/projects/{id}/auth/templates`                   | List all 4 templates (custom or default) |
| GET    | `/projects/{id}/auth/templates/{key}`             | Show one template                     |
| PUT    | `/projects/{id}/auth/templates/{key}`             | Set a custom `{ subject, body }`      |
| DELETE | `/projects/{id}/auth/templates/{key}`             | Reset back to the built-in default    |
| POST   | `/projects/{id}/auth/templates/preview`           | Render `{ subject, body }` with sample data (no send, no save) |

`key` is one of `magic_link`, `password_reset`, `email_verification`,
`email_change`. Every response includes `is_custom: true|false` so a client
can distinguish an override from the built-in default.

### Placeholders

| Placeholder        | Available in                                      |
| --------------------| ----------------------------------------------------|
| `{{link}}`          | all four templates                                  |
| `{{email}}`         | `magic_link`, `password_reset`, `email_verification` |
| `{{new_email}}`     | `email_change`                                      |
| `{{project_name}}`  | all four templates                                  |

An unrecognized placeholder is left untouched; recognized ones are
HTML-escaped before substitution.

## Delivery is best-effort

If a project has no `project_email_configs` row yet, the auth flows in
[End users](end-users.md) still work — a token is still issued — they just
have no provider to actually send through. Configure a provider here (and
send a test email) before relying on these flows in production.

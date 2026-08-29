# Platform auth

| Method | Route            | Auth | Description                        |
| ------ | ---------------- | ---- | ----------------------------------- |
| POST   | `/auth/register` | —    | Create a platform account           |
| POST   | `/auth/login`    | —    | Get a platform token                |
| POST   | `/auth/logout`   | ✓    | Invalidate the current token        |
| GET    | `/auth/me`       | ✓    | Get the authenticated account       |
| PATCH  | `/auth/me`       | ✓    | Update the authenticated account    |
| DELETE | `/auth/me`       | ✓    | Delete the authenticated account    |

Passwords require a minimum of 8 characters — there is no complexity requirement (no
enforced mix of case, digits, or symbols) beyond that. See [Authentication & Tokens](authentication.md)
for how the platform token itself is issued, hashed, and expired.

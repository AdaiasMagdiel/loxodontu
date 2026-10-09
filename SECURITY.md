# Security Policy

Loxodontu is a multi-tenant backend: one installation hosts many projects owned by different
people. Anything that lets one tenant read, modify or execute code against another tenant's data —
or against the platform itself — is a security issue, and we treat it as one.

## Supported versions

Loxodontu has not reached a stable release yet. Security fixes land on `main`; please make sure
you can reproduce the issue on the latest commit before reporting it.

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report them privately through GitHub's
[private vulnerability reporting](https://github.com/AdaiasMagdiel/loxodontu/security/advisories/new),
or by email to **adaiasmagdiell@gmail.com** with `[loxodontu security]` in the subject.

A useful report includes:

- what an attacker can do, and what they need beforehand (a platform account, a project API key, an
  end-user token, nothing at all);
- the steps or requests to reproduce it, against the latest `main`;
- the impact you were able to confirm.

What to expect:

- an acknowledgement within **3 business days**;
- an initial assessment within **10 business days**;
- a fix, or a mitigation plan, coordinated with you before anything is made public. We are glad to
  credit you in the advisory unless you prefer otherwise.

## Scope

In scope — for example:

- crossing tenant boundaries: reading or writing another project's tables, storage, functions,
  cron jobs or users, through REST, Storage, the SQL Editor, RLS/storage policies or edge functions;
- escaping the edge function sandbox (filesystem outside its directory, network access other than
  through `App\Edge\Http`, process execution, environment or credential disclosure);
- server-side request forgery, local file disclosure, authentication or authorization bypasses;
- stored or reflected XSS in the dashboard or in served storage objects.

Out of scope:

- features that are documented as privileged and restricted to platform administrators
  (`command`/`callback` cron jobs, class-based edge function handlers);
- denial of service that requires a platform administrator's cooperation, or volumetric attacks;
- issues that depend on a deployment ignoring the documented configuration (for example serving the
  repository root without the bundled `.htaccess`, or leaving `CRON_ALLOW_COMMANDS=true` on a host
  shared with untrusted administrators).

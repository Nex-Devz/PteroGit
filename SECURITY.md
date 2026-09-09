# Security Policy

PteroGit executes git commands on your panel host, so security matters.
Please read this before reporting anything.

## Supported versions

| Version | Supported |
|---|---|
| `main` (latest) | Yes |

Only the latest release on `main` receives security fixes.

## Reporting a vulnerability

**Do not open a public issue.** Create a private security advisory instead:

1. Go to https://github.com/Nex-Devz/PteroGit/security/advisories/new
2. Describe the vulnerability, including:
   - the affected component (installer, patcher, `GitService`, controllers, sudoers setup),
   - how to reproduce it,
   - the potential impact,
3. If the advisory UI is unavailable, open a GitHub issue titled `[Security]` without
   technical details and request a private channel.

We aim to acknowledge reports within **48 hours** and to issue a patched release or a
mitigation within a reasonable window depending on severity.

## Scope of security expectations

- Git commands always run as the dedicated `pterodactyl` system user via a scoped
  sudoers rule — never as `root` or the web user. Any change that violates this is a
  critical vulnerability.
- Command arguments passed to git are built as argv arrays, never as shell strings.
  A regression to shell-string interpolation is critical.
- Tokens and OAuth secrets are stored using the panel's own encrypted storage.

## Responsible disclosure

Please give us a chance to respond and fix before disclosing publicly. If you believe a
fix is taking too long, disclose responsibly (e.g. via a private issue or security
advisory) rather than posting full exploit details immediately.
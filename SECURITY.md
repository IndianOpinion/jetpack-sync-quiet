# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

Email hello@indianopinion.org with "jetpack-sync-quiet security" in the
subject. Please include the version, what you found, and how to reproduce it.
Do not open a public issue for a security problem.

We aim to acknowledge a report within 3 working days and to send a first
assessment within 10 working days. We will tell you when a fix is released
and credit you if you wish.

## Scope

The plugin makes no HTTP requests, stores no personal data and shows no
tokens or queue contents. The admin page and its one action require
`manage_options` (or `manage_network_options` on the network page) and a
valid nonce. Reports about Jetpack itself belong with its maintainers.

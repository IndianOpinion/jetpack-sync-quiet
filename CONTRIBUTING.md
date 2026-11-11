# Contributing

Thank you for helping. Bug reports, fixes and documentation improvements are
all welcome.

## Setup

You need PHP 8.1 or later and Composer. No WordPress install is needed: the
tests run against small stand-ins for WordPress, WP-CLI and the Jetpack Sync
package in `tests/stubs/`.

```sh
git clone https://github.com/IndianOpinion/jetpack-sync-quiet.git
cd jetpack-sync-quiet
composer install
```

## Tests and checks

```sh
composer test    # PHPUnit 10
composer stan    # PHPStan level 8
composer cs      # phpcs, PSR-12
composer lint    # php -l on every file
composer check   # all of the above
```

CI runs the same on PHP 8.1, 8.2 and 8.3, plus shellcheck on the example
script. Write code that runs on 8.1: no 8.2 or later features.

When you change behaviour, add or change a test. Each acceptance case in
`docs/PRD.md` section 12 has a test; keep it that way.

If you rely on a new fact about Jetpack, link the line in the public
Automattic/jetpack source in your pull request, and update the stand-in in
`tests/stubs/jetpack.php` to match.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file except the entry
  files that must parse on old PHP so they can refuse it politely:
  `jetpack-sync-quiet.php`, `uninstall.php`, `mu-loader/` and
  `src/requirements.php`.
- No error suppression with `@`, no `eval`, no shell calls.
- Escape every value printed in wp-admin. Check a capability and a nonce on
  anything that changes state.
- Prose: short sentences, plain words, British spelling.

## Pull requests

- One topic per pull request. Keep `main` green.
- Describe what changed and why, and how you tested it.
- Update `CHANGELOG.md` under "Unreleased".
- A sign-off line (DCO) is not required.

## Reporting security issues

Please do not open a public issue. See [SECURITY.md](SECURITY.md).

# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0-rc.1] - 2026-09-11

This is a pre-release for testing against full SimpleSAMLphp pre-production
environments; expect breaking changes relative to v1.0.7 (minimum PHP is now
8.3, minimum SimpleSAMLphp is now 2.5.2, and `handleLoginPass()` was renamed).

### Added

- The `sources` option is now wired through to the login page: the controller
  passes a `sources` list (`id`, `label`, `userpass`) to the template, which
  renders one form per source. `authsource` values not in `sources` are
  rejected.
- Tests for the `Multiauthsinglepage` authentication source (constructor
  validation, credential guard, session handling, logout error path).

### Changed

- Require PHP >= 8.3 and SimpleSAMLphp >= 2.5.2.
- `Multiauthsinglepage::authenticate()` now returns `never`, to match
  `saml:SP` in SimpleSAMLphp 2.5.
- Any `core:UserPassBase` source (e.g. `sqlauth:SQL`, `core:AdminPassword`)
  is now authenticated on the single page, not just `ldap:Ldap`. The
  dispatch checks `instanceof UserPassBase`; `handleLoginPass()` was renamed
  to `handleUserPassLogin()` and typed accordingly.
- `handleUserPassLogin()` uses `LdapSinglePage::loginSinglePage()` when
  available and only falls back to reflection otherwise.
- Replaced the deprecated `Request::get()` (Symfony 7.4) with explicit
  query-string / POST-body reads.
- Single code style tool (`phpcs` with the SimpleSAMLphp standard); dropped
  `php-cs-fixer`.
- Loosened the `simplesamlphp-module-ldap` constraint from `2.5.2` to
  `^2.5.2`.
- The test bootstrap defines `SIMPLESAMLPHP_TEST_NOEXIT` so redirect flows
  can be exercised in tests.
- Rewrote the README with a real configuration example.

### Fixed

- The login template read `errorTitle` / `errorDesc`, which the controller
  never set, so errors were never shown; it now uses `errorcode` /
  `errorcodes` / `errorparams`.
- `handleUserPassLogin()` (née `handleLoginPass()`) no longer raises a
  `TypeError` when the username or password is missing; it throws
  `Error\Error(WRONGUSERPASS)` instead.
- The full authentication state is no longer written to the debug log.
- `LICENSE` was an empty file; it now contains the GNU LGPL 2.1 text.
- Moved the `SuccessAuthSource` test fixture out of `src/` into `tests/`,
  fixing its PSR-4 mapping.

For releases up to and including v1.0.7, see the Git history and tags.

[Unreleased]: https://github.com/jmoral/simplesamlphp-module-multiauthsinglepage/compare/v2.0.0-rc.1...HEAD
[2.0.0-rc.1]: https://github.com/jmoral/simplesamlphp-module-multiauthsinglepage/compare/v1.0.7...v2.0.0-rc.1

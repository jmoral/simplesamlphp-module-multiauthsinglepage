# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.0-rc.3] - 2026-09-17

### Added

- A login attempt against a username/password source can now be checked
  against an external webservice *before* the credentials are validated, via
  the new `accessControl` authsource option (`url`, `apiKey`, `verifySsl`,
  `connectTimeout`, `timeout`). Disabled unless a `url` is configured. When
  the webservice responds HTTP 429 ("too many requests"), the attempt is not
  tried against the authentication source; the login page shows a generic
  wait message with a per-username, per-session exponential backoff (2s, 4s,
  8s, ... capped at 300s) instead. Any other failure talking to the
  webservice fails open. This runs on every attempt regardless of outcome,
  so it also covers users stuck in a wrong-password loop, unlike the
  post-hoc `accessLog` reporting. See the new
  `\SimpleSAML\Module\multiauthsinglepage\AccessChecker` and
  `\SimpleSAML\Module\multiauthsinglepage\AccessBackoff`, and the README.
- `SinglepageController` gained protected `makeAccessChecker()` /
  `makeAccessBackoff()` factory methods, overridable in tests the same way
  `setAuthState()` already is.
- Both `AccessChecker` and `AccessLogger` now also send `forwarded` (the
  `X-Forwarded-For` header, if any) and `podName`/`nodeName` (the
  `POD_NAME`/`NODE_NAME` environment variables, if set) to the webservice,
  to help identify the originating client and serving instance in a
  clustered deployment.

### Changed

- `SinglepageController::checkAccessOrGetWaitSeconds()` now skips the
  access-control check (and the backoff-reset session write) entirely when
  no `accessControl.url` is configured, instead of calling the webservice
  gate and resetting the backoff counter on every login attempt regardless.

## [2.1.0-rc.2] - 2026-09-15

### Changed

- `AccessLogger`'s default `connectTimeout`/`timeout` lowered from 10s/20s to
  2s/3s. The webservice call is still synchronous (it runs inline in
  `handleUserPassLogin()`), so a slow or unreachable webservice must not add
  more than a couple of seconds to a failed login.

## [2.1.0-rc.1] - 2026-09-14

Pre-release for testing against a full pre-production SimpleSAMLphp
environment.

### Added

- Failed login attempts against a username/password source can now be
  reported to an external webservice, via the new `accessLog` authsource
  option (`url`, `apiKey`, `sistemaAutenticacion`, `idpExterno`,
  `verifySsl`, `connectTimeout`, `timeout`). Disabled unless a `url` is
  configured; a failure talking to the webservice is only logged and never
  affects the login flow. See the new `\SimpleSAML\Module\multiauthsinglepage\AccessLogger`
  and the README.
- `ext-curl` added to `composer.json` (used by `AccessLogger`).

## [2.0.0] - 2026-09-14

Verified against a full pre-production SimpleSAMLphp environment (as
v2.0.0-rc.1/rc.2). Breaking changes relative to v1.0.7: minimum PHP is now
8.3, minimum SimpleSAMLphp is now 2.5.2, and `handleLoginPass()` was renamed
to `handleUserPassLogin()` and retyped.

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
- Any `core:UserPassBase` source (e.g. `ldap:Ldap`, `sqlauth:SQL`,
  `core:AdminPassword`) is now authenticated on the single page, not just
  `ldap:Ldap`. The dispatch checks `instanceof UserPassBase`;
  `handleLoginPass()` was renamed to `handleUserPassLogin()`, typed
  accordingly, and reaches the source's (always `protected`) `login()` via
  reflection.
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
- `handleUserPassLogin()` no longer raises a `TypeError` when the username or
  password is missing; it throws `Error\Error(WRONGUSERPASS)` instead.
- The full authentication state is no longer written to the debug log.
- `LICENSE` was an empty file; it now contains the GNU LGPL 2.1 text.
- Moved the `SuccessAuthSource` test fixture out of `src/` into `tests/`,
  fixing its PSR-4 mapping.
- The "skipping source" log for a source that fails to load is now a
  `warning` rather than `debug`, so a misconfiguration is visible without
  turning on debug logging.

### Removed

- `LdapSinglePage`. It could never construct: an `ldap:Ldap` subclass
  configured under any authsource type other than `ldap:Ldap` fails, because
  `SimpleSAML\Module\ldap\ConnectorFactory::fromAuthSource()` asserts the
  authsource's configured type is literally `"ldap:Ldap"` — regardless of the
  PHP class hierarchy. Configure LDAP sources as plain `ldap:Ldap`.

For releases up to and including v1.0.7, see the Git history and tags.

[Unreleased]: https://github.com/jmoral/simplesamlphp-module-multiauthsinglepage/compare/v2.1.0-rc.3...HEAD
[2.1.0-rc.3]: https://github.com/jmoral/simplesamlphp-module-multiauthsinglepage/compare/v2.1.0-rc.2...v2.1.0-rc.3
[2.1.0-rc.2]: https://github.com/jmoral/simplesamlphp-module-multiauthsinglepage/compare/v2.1.0-rc.1...v2.1.0-rc.2
[2.1.0-rc.1]: https://github.com/jmoral/simplesamlphp-module-multiauthsinglepage/compare/v2.0.0...v2.1.0-rc.1
[2.0.0]: https://github.com/jmoral/simplesamlphp-module-multiauthsinglepage/compare/v1.0.7...v2.0.0

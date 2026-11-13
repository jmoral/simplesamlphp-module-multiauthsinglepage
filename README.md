# SimpleSAMLphp Module multiauthsinglepage

This module lets a user authenticate against one of several authentication
sources from a single login page, instead of first choosing a source and then
being taken to a separate form.

## Requirements

* PHP >= 8.3
* SimpleSAMLphp >= 2.5.2

## Installation

Install with Composer:

```bash
composer require jmoral/simplesamlphp-module-multiauthsinglepage
```

Then enable the module in `config/config.php`:

```php
'module.enable' => [
    'multiauthsinglepage' => true,
],
```

## Configuration

Add an authentication source of type `multiauthsinglepage:Multiauthsinglepage`
to `config/authsources.php`. It extends `saml:SP`, so it also needs an
`entityID`. The `sources` option lists the authentication sources the user is
allowed to pick from on the single page.

```php
$config = [

    'single-page' => [
        'multiauthsinglepage:Multiauthsinglepage',

        'entityID' => 'https://example.org/saml/sp/multiauthsinglepage',

        'sources' => [
            'ldap',
            'example-idp',
        ],
    ],

    // Any username/password source (ldap:Ldap, sqlauth:SQL, core:AdminPassword, ...)
    // works and is authenticated on the single page. Configure it exactly as you
    // would outside this module: ldap:Ldap, not a custom subclass, since
    // SimpleSAML\Module\ldap\ConnectorFactory requires the type to literally be
    // "ldap:Ldap".
    'ldap' => [
        'ldap:Ldap',
        'connection_string' => 'ldap://ldap.example.org',
        'search.base' => 'ou=people,dc=example,dc=org',
        // ... the usual ldap: options
    ],

    // Any other source, e.g. a remote SAML IdP.
    'example-idp' => [
        'saml:SP',
        'entityID' => 'https://example.org/saml/sp',
        'idp' => 'https://idp.example.org/',
    ],
];
```

## How it works

1. A service requests authentication with the `single-page` source.
2. The module redirects to `module.php/multiauthsinglepage/login`, carrying the
   `AuthState` parameter.
3. That page (`templates/multiauthonepage.twig`) presents the configured
   sources. When the user submits it with an `authsource` value:
   * for a username/password source (any `core:UserPassBase` subclass, e.g.
     `ldap:Ldap`, `sqlauth:SQL`) the module authenticates directly with the
     submitted `username` and `password`;
   * for any other source the module hands over to that source's own
     `authenticate()` (e.g. a redirect to a remote IdP).
4. On logout the module logs out from whichever source was used, which it
   stored in the session.

A specific source can be pre-selected by adding `?authsource=<id>` to the
initial request.

## Customising the login page

`templates/multiauthonepage.twig` renders one form per configured source out
of the box; override it in your theme to restyle it. The controller passes:

* `sources` — one entry per configured source: `{ id, label, userpass }`,
  where `label` is the source's `name` option (or its id) and `userpass` is
  `true` for sources whose credentials are collected on this page
* `stateParams` — hidden fields to repost (`AuthState`)
* `errorcode` — error code of the last failed attempt, or `null`
* `errorcodes` — `{ title: {...}, descr: {...} }` message maps for `errorcode`
* `errorparams` — parameters for the error message translation
* `recaptcha` — `{ siteKey, action }` when reCAPTCHA is configured (see
  below), otherwise `null`. Never contains the secret key.
* `recaptchaProblem` — for a `MULTIAUTHCAPTCHA` error, what the browser
  reported (`no-script`, `timeout`, `error`), otherwise `null`
* `username` — after a failed attempt, the username that was submitted, so
  the form can be shown again with it filled in; otherwise `null`

Each form must submit `authsource` (and `username` / `password` when
`userpass` is `true`, plus `g-recaptcha-response` when `recaptcha` is set)
back to the same URL together with `stateParams`. Only
`authsource` values listed in the source's `sources` option are accepted.

## Registering failed login attempts

When a username/password source rejects the submitted credentials, the module
can report the attempt to an external webservice. It is disabled unless a
`url` is configured; a failure talking to the webservice is only logged, it
never affects the login flow.

```php
'single-page' => [
    'multiauthsinglepage:Multiauthsinglepage',
    'entityID' => 'https://example.org/saml/sp/multiauthsinglepage',
    'sources' => ['ldap', 'example-idp'],

    'accessLog' => [
        'url' => 'https://webservice.example.org/endpoint',
        'apiKey' => 'xxxxxxxx',            // optional, sent as an "apiKey" header
        'sistemaAutenticacion' => 'LDAP-UJA', // optional, default "contraseña"
        'idpExterno' => 'no aplica',          // optional, default "no aplica"
        'verifySsl' => true,                  // optional, default true
        'connectTimeout' => 2,                 // optional, seconds, default 2
        'timeout' => 3,                        // optional, seconds, default 3
    ],
],
```

The defaults are deliberately short: this call must never make a failed login
noticeably slower for the user, so it fails fast rather than waiting like a
normal request would.

For each rejected attempt the module POSTs (as `application/x-www-form-urlencoded`)
`destino` (the submitted username, or `"desconocido"`), `a` (always
`registraAcceso1faFallido`), `origen` (the client's IP address),
`serviceProvider` (the requesting SP's entityID, from `$state['core:SP']`),
`sistemaAutenticacion` and `idpExterno` (the two config values above),
`forwarded` (the `X-Forwarded-For` header, if any), and `podName` /
`nodeName` (the `POD_NAME` / `NODE_NAME` environment variables, if set —
useful to tell which instance handled the request in a clustered
deployment).

This only covers username/password sources rejecting a login attempt (e.g. a
wrong LDAP password); it does not cover a redirect-style source (`saml:SP`)
failing on its own side.

## Blocking abusive login attempts (exponential backoff)

Independently of `accessLog`, the module can ask an external webservice
whether a username/password login attempt is allowed to proceed *before*
the credentials are checked against the authentication source. This runs on
every attempt, successful or not, so a wrong-password loop is subject to the
same backoff as anyone else once the webservice starts rejecting the
attempts.

```php
'single-page' => [
    'multiauthsinglepage:Multiauthsinglepage',
    'entityID' => 'https://example.org/saml/sp/multiauthsinglepage',
    'sources' => ['ldap', 'example-idp'],

    'accessControl' => [
        'url' => 'https://webservice.example.org/endpoint',
        'apiKey' => 'xxxxxxxx',  // optional, sent as an "apiKey" header
        'verifySsl' => true,     // optional, default true
        'connectTimeout' => 2,   // optional, seconds, default 2
        'timeout' => 3,          // optional, seconds, default 3
    ],
],
```

Disabled unless a `url` is configured. Before dispatching a username/password
attempt, the module POSTs `destino` (the submitted username, or
`"desconocido"`), `a` (always `compruebaCondicionesAcceso`), `origen` (the
client's IP address), `serviceProvider` (`$state['core:SP']`), `forwarded`
(the `X-Forwarded-For` header, if any), and `podName` / `nodeName` (the
`POD_NAME` / `NODE_NAME` environment variables, if set) to that `url`.

* If the webservice responds with any status in the 2xx range, the attempt
  proceeds as usual.
* If it responds **HTTP 429**, the attempt is not tried against the
  authentication source at all, and the login page shows the exact same
  generic "wrong username or password" error as any other rejected attempt.
  The block is deliberately never surfaced to the user: an attacker must not
  be able to tell a backed-off attempt apart from a plain wrong password.
  The wait time — 2s, 4s, 8s, 16s, ... capped at 300s, growing on every
  further attempt that is still rejected, tracked per browser session and
  per username, and reset as soon as the webservice allows an attempt again
  — is only written to the server-side log (`AccessBackoff`, at `error`
  level), for an administrator to act on.
* Any other outcome (timeout, connection error, unexpected status) **fails
  open**: the attempt proceeds as if it had been allowed. An unreachable
  webservice must never lock every user out.

See `\SimpleSAML\Module\multiauthsinglepage\AccessChecker` and
`\SimpleSAML\Module\multiauthsinglepage\AccessBackoff`.

### Local throttling, independent of `accessControl`

Regardless of whether `accessControl` is configured, the module always
enforces its own exponential backoff after too many consecutive failed
username/password attempts for the same username, tracked per browser
session: the first 3 failures are free, and from the 4th one onward a
further attempt is only let through once the wait — 2s, 4s, 8s, ... capped
at 300s, counted from the last failure — has elapsed. A successful login
resets the counter.

Unlike an `accessControl`-triggered block, a throttled attempt here **is**
shown to the user: a generic "please wait a moment" message with tips
(double-check the username and password, watch out for Caps Lock or the
wrong keyboard layout), without an exact countdown or attempt count. This
is safe to reveal because, unlike `accessControl`, this throttle does not
depend on whether the username corresponds to a real account (so it cannot
be used to enumerate valid usernames), and it doesn't expose anything about
an external system's detection logic — a user can already tell locally
that they've retried several times. See
`\SimpleSAML\Module\multiauthsinglepage\LoginThrottle` and
`LoginThrottle::ERROR_CODE`.

Because the counter lives in the browser session, it does not survive the
user starting a fresh session (e.g. a new browser or private window), so it
complements rather than replaces `accessControl` for a determined attacker.

`LoginThrottle::ERROR_CODE` (`MULTIAUTHTHROTTLED`) is registered with a
title and description via
`\SimpleSAML\Module\multiauthsinglepage\Error\ErrorCodes` (see its
`getCustomTitles()`/`getCustomDescriptions()`), on top of the special-cased
message in `templates/multiauthonepage.twig`. This matters if you override
that template from a theme: an overridden copy that does not (yet) special-case
`MULTIAUTHTHROTTLED` and just does `errorcodes['title'][errorcode]`
generically will still render a sensible message instead of a
`Twig\Error\RuntimeError` ("Key ... does not exist"). Update your theme's
copy to pick up the friendlier message without a generic "error" styling.

## Google reCAPTCHA v3

Username/password attempts can additionally be checked with
[reCAPTCHA v3](https://developers.google.com/recaptcha/docs/v3), which scores
how likely an interaction is to come from a bot, without showing the user a
challenge.

```php
'single-page' => [
    'multiauthsinglepage:Multiauthsinglepage',
    'entityID' => 'https://example.org/saml/sp/multiauthsinglepage',
    'sources' => ['ldap', 'example-idp'],

    'recaptcha' => [
        'siteKey' => 'xxxxxxxx',    // required to enable
        'secretKey' => 'xxxxxxxx',  // required to enable
        'mode' => 'log',            // optional, 'log' (default) or 'enforce'
        'minScore' => 0.5,          // optional, 0.0-1.0, default 0.5
        'action' => 'login',        // optional, default 'login'
        'hostnames' => [],          // optional, accepted hostnames; default: any
        'failOpen' => true,         // optional, default true
        'sendRemoteIp' => 'public', // optional, 'public' (default), 'always' or 'never'
        'exemptUsers' => [],        // optional, usernames never checked; default: none
        'connectTimeout' => 2,      // optional, seconds, default 2
        'timeout' => 3,             // optional, seconds, default 3
    ],
],
```

Disabled unless both `siteKey` and `secretKey` are configured. Only
username/password sources are checked; redirect-style sources (a remote IdP,
...) are not, since they authenticate the user themselves.

**On the page.** When enabled, the controller passes `recaptcha` to the
template, which loads `https://www.google.com/recaptcha/api.js?render=<siteKey>`
and the module's `js/recaptcha.js`. A form opts in with
`data-recaptcha-sitekey`, `data-recaptcha-action` and a hidden
`<input name="g-recaptcha-response">`: on submit the script requests a fresh
token (they expire after two minutes), puts it in that field and submits the
form. Its listener sits on `window`, so it runs after any listener on the form
itself — a theme's own validation that cancels the submission is respected.
If reCAPTCHA cannot be loaded or does not answer within 5 seconds, the form
is submitted without a token and the server decides.

If the form also has a hidden `<input name="g-recaptcha-diag">`, the script
fills it with what happened in the browser, as `<outcome>:<milliseconds>`:
`ok`, `no-script` (Google's script never became available: blocked by the
browser, an extension or the network), `timeout` (no token in time) or
`error`. The server logs it (`client=`) and, for a rejected attempt, passes
the outcome to the template as `recaptchaProblem`, so the page can tell the
user that something is blocking Google. Anything not in that shape is logged
as `invalid`: the field is never trusted.

The default SimpleSAMLphp `Content-Security-Policy` blocks Google's script:
add `https://www.google.com https://www.gstatic.com` to `script-src` and
`https://www.google.com` to `frame-src` and `connect-src` (see
`headers.security` in `config.php`). Google's terms also require telling the
user the page is protected by reCAPTCHA: the default floating badge does so;
if a theme hides it, it must show the equivalent text.

**On the server.** Before the `accessControl` webservice or the authentication
source are asked, the token is sent to Google's `siteverify` endpoint
(together with the client's IP address, see `sendRemoteIp`). The attempt passes when Google
reports `success`, the token was issued for the configured `action` (and one
of the `hostnames`, if given), and its `score` is at least `minScore`. Every
verification is logged on one line, e.g.:

```text
Multiauthsinglepage - recaptcha user=alice result=rejected reason=score score=0.3 action=login
hostname=idp.example.org mode=enforce allowed=no client=ok:240 ip=203.0.113.42
```

with `result` `ok` (`info` level), `rejected` (`warning`), `unavailable`
(`error`) or `exempt` (`info`), so the threshold can be tuned from real traffic.

* In `log` mode (the default) nothing is ever rejected: start here, watch the
  scores, then switch to `enforce`.
* In `enforce` mode a rejected attempt is never tried against the source, and
  the page shows the `MULTIAUTHCAPTCHA` error (`RecaptchaVerifier::ERROR_CODE`,
  registered in `Error\ErrorCodes`), asking the user to try again. A missing
  token counts as rejected.
* If Google cannot be reached, or rejects the `secretKey` (a configuration
  problem, not the user's), the attempt is `unavailable` and, with
  `failOpen` (the default), let through: an outage must never lock every user
  out.
* `sendRemoteIp` (`public` by default) sends the client's IP address as
  `remoteip` only when it is a public one. A private or reserved address
  (192.168.x.x, 10.x.x.x, a VPN, ...) means the client is behind a NAT, so
  Google saw the browser come from another, public address: sending the
  private one adds nothing, and users on an internal network were seen to
  score lower with it. `always` sends it anyway, `never` never sends it.
* A username listed in `exemptUsers` (compared case-insensitively) is never
  checked: Google is not asked, and the attempt is logged as `result=exempt
  reason=exempt-user`. Meant for accounts used by automated tests, which a
  headless browser makes look like a bot. Such an account has no reCAPTCHA
  protection at all: keep the list short, use test accounts only, and watch
  the `exempt` lines.

See `\SimpleSAML\Module\multiauthsinglepage\RecaptchaVerifier`.

## License

LGPL-2.1-or-later. See [LICENSE](LICENSE).

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

Each form must submit `authsource` (and `username` / `password` when
`userpass` is `true`) back to the same URL together with `stateParams`. Only
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
resets the counter. As with `accessControl`, a throttled attempt is
indistinguishable from a plain wrong password to the user; the block is
only visible in the server-side log (`error` level). See
`\SimpleSAML\Module\multiauthsinglepage\LoginThrottle`.

Because the counter lives in the browser session, it does not survive the
user starting a fresh session (e.g. a new browser or private window), so it
complements rather than replaces `accessControl` for a determined attacker.

## License

LGPL-2.1-or-later. See [LICENSE](LICENSE).

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

    // A username/password source. Configure it as multiauthsinglepage:LdapSinglePage
    // rather than ldap:Ldap so the module can authenticate against it without
    // reflection.
    'ldap' => [
        'multiauthsinglepage:LdapSinglePage',
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
   * for a username/password source (an `ldap:Ldap` / `LdapSinglePage`
     instance) the module binds directly with the submitted `username` and
     `password`;
   * for any other source the module hands over to that source's own
     `authenticate()` (e.g. a redirect to a remote IdP).
4. On logout the module logs out from whichever source was used, which it
   stored in the session.

A specific source can be pre-selected by adding `?authsource=<id>` to the
initial request.

## Customising the login page

`templates/multiauthonepage.twig` shipped with the module is a starting point
and is expected to be adapted to your theme. The controller passes it:

* `stateParams` — hidden fields to repost (`AuthState`)
* `errorcode` — error code of the last failed attempt, or `null`
* `errorcodes` — `{ title: {...}, descr: {...} }` message maps for `errorcode`
* `errorparams` — parameters for the error message translation

The form must submit `authsource` (and `username` / `password` for a
username/password source) back to the same URL together with `stateParams`.

## License

LGPL-2.1-or-later. See [LICENSE](LICENSE).

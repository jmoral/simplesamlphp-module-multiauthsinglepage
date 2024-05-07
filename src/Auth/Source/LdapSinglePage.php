<?php

declare(strict_types=1);

namespace SimpleSAML\Module\multiauthsinglepage\Auth\Source;

use SimpleSAML\Logger;
use SimpleSAML\Module\ldap\Auth\Source\Ldap;

class LdapSinglePage extends Ldap
{
    public function loginSinglePage(string $username, string $password): array
    {
        return $this->login($username, $password);
    }
}
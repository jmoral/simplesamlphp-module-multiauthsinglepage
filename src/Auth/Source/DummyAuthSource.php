<?php

declare(strict_types=1);

namespace SimpleSAML\Module\multiauthsinglepage\Auth\Source;

use SimpleSAML\Auth;

class DummyAuthSource extends Auth\Source
{
    /**
     * @param array<mixed> $state
     */
    public function authenticate(array &$state): void
    {
    }
}

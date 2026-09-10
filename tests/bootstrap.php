<?php

declare(strict_types=1);

// A bootstrap file both defines a constant and has side effects; that is expected here.
// phpcs:disable PSR1.Files.SideEffects

// Stop SimpleSAMLphp redirects from calling exit(), so flows that redirect
// (e.g. an auth source's authenticate()) can be exercised in tests.
define('SIMPLESAMLPHP_TEST_NOEXIT', true);

$projectRoot = dirname(__DIR__);
require_once($projectRoot . '/vendor/autoload.php');

// Symlink module into ssp vendor lib so that templates and urls can resolve correctly
$linkPath = $projectRoot . '/vendor/simplesamlphp/simplesamlphp/modules/multiauthsinglepage';
if (file_exists($linkPath) === false) {
    echo "Linking '$linkPath' to '$projectRoot'\n";
    symlink($projectRoot, $linkPath);
}

<?php

declare(strict_types=1);

namespace SimpleSAML\Module\multiauthsinglepage;

use SimpleSAML\Logger;
use SimpleSAML\Session;

/**
 * Tracks, per browser session, how many consecutive times the access-control
 * webservice (see AccessChecker) has reported "too many requests" for a
 * username, and computes how long the login page should make the user wait
 * (exponential backoff) before letting them try again.
 */
class AccessBackoff
{
    private const string SESSION_DATATYPE = 'multiauthsinglepage:accessBackoff';

    private const int BASE_SECONDS = 2;

    private const int MAX_SECONDS = 300;

    /**
     * If no new block happens for a username within this many seconds, its
     * counter is forgotten, so the backoff starts from scratch again.
     */
    private const int COUNTER_TIMEOUT_SECONDS = 1800;


    /**
     * Record a new block for $username and return how many seconds the
     * login page should make them wait before trying again.
     */
    public function registerBlock(string $username): int
    {
        $session = Session::getSessionFromRequest();
        $key = self::key($username);
        $attempts = $session->getData(self::SESSION_DATATYPE, $key);
        $attempts = is_null($attempts) ? 1 : $attempts + 1;
        $session->setData(self::SESSION_DATATYPE, $key, $attempts, self::COUNTER_TIMEOUT_SECONDS);

        $wait = (int) min(self::BASE_SECONDS * (2 ** ($attempts - 1)), self::MAX_SECONDS);
        Logger::error(
            "Multiauthsinglepage - access blocked for user '$username', attempt #$attempts, waiting {$wait}s",
        );

        return $wait;
    }


    /**
     * Forget any accumulated blocks for $username, so that the next block
     * (if any) starts the backoff from scratch again.
     */
    public function reset(string $username): void
    {
        $session = Session::getSessionFromRequest();
        $session->deleteData(self::SESSION_DATATYPE, self::key($username));
    }


    private static function key(string $username): string
    {
        return 'attempts:' . $username;
    }
}

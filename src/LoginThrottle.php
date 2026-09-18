<?php

declare(strict_types=1);

namespace SimpleSAML\Module\multiauthsinglepage;

use SimpleSAML\Logger;
use SimpleSAML\Session;

/**
 * Enforces an exponential backoff wait on a username/password login after too
 * many consecutive failed attempts, tracked per browser session and per
 * username. This is a built-in protection, always active, independent of the
 * external "accessControl" webservice (see AccessChecker/AccessBackoff).
 *
 * The first self::FREE_ATTEMPTS failures are never penalized. From the next
 * one onward, a further attempt is only let through once the backoff wait
 * (2s, 4s, 8s, ... capped at self::MAX_SECONDS) has elapsed since the last
 * failure.
 */
class LoginThrottle
{
    private const string SESSION_DATATYPE = 'multiauthsinglepage:loginThrottle';

    /**
     * How many failed attempts are allowed before the backoff wait kicks in.
     */
    private const int FREE_ATTEMPTS = 3;

    private const int BASE_SECONDS = 2;

    private const int MAX_SECONDS = 300;

    /**
     * If no new failure happens for a username within this many seconds, its
     * counter is forgotten, so the backoff starts from scratch again.
     */
    private const int COUNTER_TIMEOUT_SECONDS = 1800;


    /**
     * Whether a login attempt for $username must currently be refused
     * without even checking the credentials, because too many attempts have
     * already failed in a row and the backoff wait since the last one has
     * not elapsed yet.
     */
    public function isBlocked(string $username): bool
    {
        return $this->secondsRemaining($username) > 0;
    }


    /**
     * Record that a login attempt for $username has just failed. Once the
     * free attempts are exhausted, this logs (at `error` level) the attempt
     * count and the resulting wait.
     */
    public function registerFailedAttempt(string $username): void
    {
        $session = Session::getSessionFromRequest();
        $key = self::key($username);
        /** @var array{count: int, lastFailure: int}|null $data */
        $data = $session->getData(self::SESSION_DATATYPE, $key);
        $count = ($data['count'] ?? 0) + 1;
        $session->setData(
            self::SESSION_DATATYPE,
            $key,
            ['count' => $count, 'lastFailure' => time()],
            self::COUNTER_TIMEOUT_SECONDS,
        );

        if ($count > self::FREE_ATTEMPTS) {
            $wait = self::waitFor($count);
            Logger::error(
                "Multiauthsinglepage - throttling user '$username' after $count consecutive failed " .
                "login attempts, waiting {$wait}s",
            );
        }
    }


    /**
     * Forget any accumulated failures for $username, e.g. after a successful
     * login, so that the next failure (if any) starts the backoff from
     * scratch again.
     */
    public function reset(string $username): void
    {
        $session = Session::getSessionFromRequest();
        $session->deleteData(self::SESSION_DATATYPE, self::key($username));
    }


    private function secondsRemaining(string $username): int
    {
        $session = Session::getSessionFromRequest();
        /** @var array{count: int, lastFailure: int}|null $data */
        $data = $session->getData(self::SESSION_DATATYPE, self::key($username));
        if ($data === null || $data['count'] <= self::FREE_ATTEMPTS) {
            return 0;
        }

        $elapsed = time() - $data['lastFailure'];

        return max(0, self::waitFor($data['count']) - $elapsed);
    }


    private static function waitFor(int $count): int
    {
        $n = $count - self::FREE_ATTEMPTS;

        return (int) min(self::BASE_SECONDS * (2 ** ($n - 1)), self::MAX_SECONDS);
    }


    private static function key(string $username): string
    {
        return 'attempts:' . $username;
    }
}

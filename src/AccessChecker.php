<?php

declare(strict_types=1);

namespace SimpleSAML\Module\multiauthsinglepage;

use SimpleSAML\Logger;

/**
 * Asks an external access-control webservice whether a login attempt is
 * allowed to proceed, before the credentials are checked against the
 * authentication source.
 *
 * Configured through the "accessControl" option of the
 * multiauthsinglepage:Multiauthsinglepage authsource; disabled unless a
 * "url" is given. A login attempt is only ever blocked by an explicit
 * HTTP 429 response: any other failure talking to the webservice (timeout,
 * connection error, unexpected status) fails open, so an unreachable
 * webservice never locks every user out.
 */
class AccessChecker
{
    /**
     * The action value the webservice expects to check access conditions.
     */
    public const string ACTION_CHECK = 'compruebaCondicionesAcceso';

    /**
     * The HTTP status the webservice uses to report "too many requests".
     */
    public const int STATUS_TOO_MANY_REQUESTS = 429;

    /**
     * Default "connectTimeout": this call runs inline before every login
     * attempt, so it must never make the login page noticeably slower.
     */
    public const int DEFAULT_CONNECT_TIMEOUT = 2;

    /**
     * Default "timeout" (total, connect included). See DEFAULT_CONNECT_TIMEOUT.
     */
    public const int DEFAULT_TIMEOUT = 3;


    /**
     * @var callable(string $url, array<string, string> $params, array<string, mixed> $config): int
     */
    private $transport;


    /**
     * @param array<string, mixed> $config The "accessControl" authsource config option:
     *      - url (string, required to enable)
     *      - apiKey (string, optional)
     *      - verifySsl (bool, optional, default true)
     *      - connectTimeout (int, optional, seconds, default self::DEFAULT_CONNECT_TIMEOUT)
     *      - timeout (int, optional, seconds, default self::DEFAULT_TIMEOUT)
     * @param (callable(string, array<string, string>, array<string, mixed>): int)|null $transport
     *      How to actually perform the HTTP request and return the HTTP status code;
     *      defaults to a cURL POST. Overridable for tests.
     */
    public function __construct(
        private readonly array $config,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? self::curlPost(...);
    }


    /**
     * Whether an "url" was configured; when it was not, this class is a no-op
     * and every attempt is allowed.
     */
    public function isEnabled(): bool
    {
        return !empty($this->config['url']);
    }


    /**
     * Whether a login attempt for $username is currently allowed to proceed.
     *
     * @param string|null $username The username entered by the user, if any.
     * @param string|null $serviceProvider The entityID of the service the user is
     *      trying to reach ($state['core:SP']), if known.
     */
    public function isAllowed(?string $username, ?string $serviceProvider): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $params = [
            'destino' => $username ?? 'desconocido',
            'a' => self::ACTION_CHECK,
            'origen' => $_SERVER['REMOTE_ADDR'] ?? '',
            'serviceProvider' => $serviceProvider ?? '',
            'forwarded' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            'podName' => (string) (getenv('POD_NAME') ?: ''),
            'nodeName' => (string) (getenv('NODE_NAME') ?: ''),
        ];

        try {
            $status = ($this->transport)((string) $this->config['url'], $params, $this->config);
        } catch (\Throwable $e) {
            Logger::warning('Multiauthsinglepage - could not check access conditions: ' . $e->getMessage());
            return true;
        }

        if ($status === self::STATUS_TOO_MANY_REQUESTS) {
            return false;
        }
        if ($status < 200 || $status >= 300) {
            Logger::warning('Multiauthsinglepage - access check returned unexpected HTTP status ' . $status);
        }

        return true;
    }


    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $config
     */
    private static function curlPost(string $url, array $params, array $config): int
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init() failed');
        }

        try {
            $headers = [];
            if (!empty($config['apiKey'])) {
                $headers[] = 'apiKey: ' . $config['apiKey'];
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $connectTimeout = (int) ($config['connectTimeout'] ?? self::DEFAULT_CONNECT_TIMEOUT);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT));
            if (($config['verifySsl'] ?? true) === false) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }

            curl_exec($ch);

            if (curl_errno($ch) !== 0) {
                throw new \RuntimeException('curl error: ' . curl_error($ch));
            }

            return (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($ch);
        }
    }
}

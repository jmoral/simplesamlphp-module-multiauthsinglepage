<?php

declare(strict_types=1);

namespace SimpleSAML\Module\multiauthsinglepage;

use SimpleSAML\Logger;

/**
 * Reports failed 1FA login attempts to an external access-control webservice.
 *
 * Configured through the "accessLog" option of the multiauthsinglepage:Multiauthsinglepage
 * authsource; disabled unless a "url" is given. Never lets a failure talking to the
 * webservice propagate: it is a side channel, not part of the authentication flow.
 */
class AccessLogger
{
    /**
     * The action value the webservice expects for a failed 1FA login attempt.
     */
    public const string ACTION_FAILED_ATTEMPT = 'registraAcceso1faFallido';


    /**
     * @var callable(string $url, array<string, string> $params, array<string, mixed> $config): void
     */
    private $transport;


    /**
     * @param array<string, mixed> $config The "accessLog" authsource config option:
     *      - url (string, required to enable)
     *      - apiKey (string, optional)
     *      - sistemaAutenticacion (string, optional, default "contraseña")
     *      - idpExterno (string, optional, default "no aplica")
     *      - verifySsl (bool, optional, default true)
     *      - connectTimeout (int, optional, default 10)
     *      - timeout (int, optional, default 20)
     * @param (callable(string, array<string, string>, array<string, mixed>): void)|null $transport
     *      How to actually perform the HTTP request; defaults to a cURL POST. Overridable
     *      for tests.
     */
    public function __construct(
        private readonly array $config,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? self::curlPost(...);
    }


    /**
     * Whether an "url" was configured; when it was not, this class is a no-op.
     */
    public function isEnabled(): bool
    {
        return !empty($this->config['url']);
    }


    /**
     * Register that $username failed to authenticate.
     *
     * @param string|null $username The username entered by the user, if any.
     * @param string|null $serviceProvider The entityID of the service the user was trying
     *      to reach ($state['core:SP']), if known.
     */
    public function registerFailedAttempt(?string $username, ?string $serviceProvider): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $params = [
            'destino' => $username ?? 'desconocido',
            'a' => self::ACTION_FAILED_ATTEMPT,
            'origen' => $_SERVER['REMOTE_ADDR'] ?? '',
            'serviceProvider' => $serviceProvider ?? '',
            'sistemaAutenticacion' => (string) ($this->config['sistemaAutenticacion'] ?? 'contraseña'),
            'idpExterno' => (string) ($this->config['idpExterno'] ?? 'no aplica'),
        ];

        try {
            ($this->transport)((string) $this->config['url'], $params, $this->config);
        } catch (\Throwable $e) {
            Logger::warning('Multiauthsinglepage - could not register the failed login attempt: ' . $e->getMessage());
        }
    }


    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $config
     */
    private static function curlPost(string $url, array $params, array $config): void
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
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) ($config['connectTimeout'] ?? 10));
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($config['timeout'] ?? 20));
            if (($config['verifySsl'] ?? true) === false) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }

            curl_exec($ch);

            if (curl_errno($ch) !== 0) {
                throw new \RuntimeException('curl error: ' . curl_error($ch));
            }

            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('unexpected HTTP status ' . $status);
            }
        } finally {
            curl_close($ch);
        }
    }
}

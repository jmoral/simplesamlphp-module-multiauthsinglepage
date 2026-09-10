<?php

declare(strict_types=1);

namespace SimpleSAML\Module\multiauthsinglepage\Controller;

use SimpleSAML\Auth;
use SimpleSAML\Auth\Source;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Logger;
use SimpleSAML\Module\core\Auth\UserPassBase;
use SimpleSAML\Module\multiauthsinglepage\Auth\Source\Multiauthsinglepage as SourceMultiauthsinglepage;
use SimpleSAML\Session;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller class for the multiauthsinglepage module.
 *
 * This class serves the different views available in the module.
 */
class SinglepageController
{
    /**
     * Controller constructor.
     *
     * It initializes the global configuration and session for the controllers implemented here.
     *
     * @param \SimpleSAML\Configuration $config The configuration to use by the controllers.
     * @param \SimpleSAML\Session $session The session to use by the controllers.
     *
     * @throws \Exception
     */
    public function __construct(
        protected Configuration $config,
        protected Session $session,
    ) {
    }


    /**
     * @var \SimpleSAML\Auth\State|string
     *
     * @psalm-var \SimpleSAML\Auth\State|class-string
     */
    protected $authState = Auth\State::class;


    /**
     * Inject the \SimpleSAML\Auth\State dependency.
     *
     * @param \SimpleSAML\Auth\State $authState
     */
    public function setAuthState(Auth\State $authState): void
    {
        $this->authState = $authState;
    }


    /**
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     * @return \SimpleSAML\XHTML\Template
     */
    public function main(Request $request): Template
    {
        if (!$request->request->has('AuthState') && !$request->query->has('AuthState')) {
            throw new Error\BadRequest('Missing AuthState parameter.');
        }
        $stateId = self::getParam($request, 'AuthState') ?? '';
        $state = $this->authState::loadState($stateId, SourceMultiauthsinglepage::STAGEID);

        /** @var string[] $sources */
        $sources = $state[SourceMultiauthsinglepage::SOURCESID] ?? [];

        $t = new Template($this->config, 'multiauthsinglepage:multiauthonepage.twig');
        $authsourceId = self::getParam($request, 'authsource');
        $errorCode = null;
        $errorParams = null;
        if ($authsourceId !== null) {
            // attempt to log in
            try {
                if ($sources !== [] && !in_array($authsourceId, $sources, true)) {
                    throw new Error\BadRequest('wrong authsource parameter.');
                }
                $as = Source::getById($authsourceId);
                if (is_null($as)) {
                    throw new Error\BadRequest('wrong authsource parameter.');
                }
                if ($as instanceof UserPassBase) {
                    // Username/password source: collect the credentials on this page.
                    $username = self::getParam($request, 'username');
                    $pass = self::getParam($request, 'password');
                    SourceMultiauthsinglepage::handleUserPassLogin($as, $state, $username, $pass);
                } else {
                    // Redirect-style source (SP, CAS, ...): hand over to it.
                    SourceMultiauthsinglepage::handleLogin($as, $state);
                }
            } catch (\SimpleSAML\Error\Error $e) {
                $errorCode = $e->getErrorCode();
                $errorParams = $e->getParameters();
            }
        }
        $t->data['sources'] = $this->describeSources($sources);
        $t->data['errorcode'] = $errorCode;
        $t->data['errorcodes'] = (new Error\ErrorCodes())->getAllMessages();
        $t->data['errorparams'] = $errorParams;
        $t->data['stateParams'] = ['AuthState' => $stateId];
        return $t;
    }


    /**
     * Read a request parameter from the query string, falling back to the POST body.
     *
     * Replaces the deprecated \Symfony\Component\HttpFoundation\Request::get().
     */
    private static function getParam(Request $request, string $key): ?string
    {
        return $request->query->get($key) ?? $request->request->get($key);
    }


    /**
     * Turn the configured list of authsource ids into the data the login page needs:
     * an id, a display label and whether the source takes a username/password inline.
     *
     * @param string[] $sources
     *
     * @return list<array{id: string, label: string, userpass: bool}>
     */
    private function describeSources(array $sources): array
    {
        $described = [];
        foreach ($sources as $id) {
            if (!is_string($id)) {
                continue;
            }
            try {
                $as = Source::getById($id);
            } catch (\Exception $e) {
                Logger::debug('Multiauthsinglepage - skipping source ' . $id . ': ' . $e->getMessage());
                continue;
            }
            if ($as === null) {
                continue;
            }
            $described[] = [
                'id' => $id,
                'label' => self::sourceLabel($id),
                // Username/password sources are prompted inline; other sources redirect.
                // Kept in sync with the dispatch in main().
                'userpass' => $as instanceof UserPassBase,
            ];
        }

        return $described;
    }


    /**
     * Best-effort display label for an authsource: its "name" option, otherwise its id.
     */
    private static function sourceLabel(string $id): string
    {
        try {
            $name = Configuration::getConfig('authsources.php')->getArray($id, [])['name'] ?? null;
        } catch (\Exception $e) {
            return $id;
        }
        if (is_string($name) && $name !== '') {
            return $name;
        }
        if (is_array($name) && $name !== []) {
            $first = reset($name);

            return is_string($first) && $first !== '' ? $first : $id;
        }

        return $id;
    }
}

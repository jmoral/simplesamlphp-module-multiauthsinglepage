<?php

declare(strict_types=1);

namespace SimpleSAML\Module\multiauthsinglepage\Controller;

use SimpleSAML\Auth;
use SimpleSAML\Auth\Source;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Module\ldap\Auth\Source\Ldap;
use SimpleSAML\Module\multiauthsinglepage\Auth\Source\Multiauthsinglepage as SourceMultiauthsinglepage;
use SimpleSAML\Session;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller class for the multiauthsinglepage module.
 *
 * This class serves the different views available in the module.
 *
 * @package simplesamlphp/simplesamlphp-module-multiauthsinglepage
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
        protected Session $session
    ) {
    }

    /**
     * @var \SimpleSAML\Auth\State|string
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
        $stateId = $request->get('AuthState');
        $state = $this->authState::loadState($stateId, SourceMultiauthsinglepage::STAGEID);
        $t = new Template($this->config, 'multiauthsinglepage:multiauthonepage.twig');
        $authsourceId = $request->get('authsource');
        $errorCode = null;
        $errorParams = null;
        if ($authsourceId !== null) {
            // attempt to log in
            try {
                $as = Source::getById($authsourceId);
                if (is_null($as)) {
                    throw new Error\BadRequest('wrong authsource parameter.');
                }
                if ($as instanceof Ldap) {
                    $username = $request->get('username');
                    $pass = $request->get('password');
                    SourceMultiauthsinglepage::handleLoginPass($as, $state, $username, $pass);
                } else {
                    SourceMultiauthsinglepage::handleLogin($as, $state);
                }
            } catch (\SimpleSAML\Error\Error $e) {
                $errorCode = $e->getErrorCode();
                $errorParams = $e->getParameters();
            }
        }
        $t->data['errorcode'] = $errorCode;
        $t->data['errorcodes'] = (new Error\ErrorCodes())->getAllMessages();
        $t->data['errorparams'] = $errorParams;
        $t->data['stateParams'] = ['AuthState' => $stateId];
        return $t;
    }
}

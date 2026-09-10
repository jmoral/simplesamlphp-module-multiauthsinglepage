<?php

declare(strict_types=1);

namespace SimpleSAML\Module\multiauthsinglepage\Auth\Source;

use SimpleSAML\Assert\Assert;
use SimpleSAML\Auth;
use SimpleSAML\Error;
use SimpleSAML\Logger;
use SimpleSAML\Module;
use SimpleSAML\Module\ldap\Auth\Source\Ldap;
use SimpleSAML\Module\saml\Auth\Source\SP;
use SimpleSAML\Session;
use SimpleSAML\Utils\HTTP;
use Symfony\Component\HttpFoundation\Request;

class Multiauthsinglepage extends SP
{
    /**
     * The key of the AuthId field in the state.
     */
    public const string AUTHID = '\SimpleSAML\Module\multiauthsinglepage\Auth\Source\MultiAuth.AuthId';

    /**
     * The string used to identify our states.
     */
    public const string STAGEID = '\SimpleSAML\Module\multiauthsinglepage\Auth\Source\MultiAuth.StageId';

    /**
     * The key where the sources is saved in the state.
     */
    public const string SOURCESID = '\SimpleSAML\Module\multiauthsinglepage\Auth\Source\MultiAuth.SourceId';

    /**
     * The key where the selected source is saved in the session.
     */
    public const string SESSION_SOURCE = 'multiauth:selectedSource';


    /**
     * @var string[] $sources
     */
    private array $sources;


    /**
     * @param array<mixed> $info
     * @param array<mixed> $config
     */
    public function __construct(array $info, array $config)
    {
        parent::__construct($info, $config);

        Assert::keyExists(
            $config,
            'sources',
            'The required "sources" config option was not found',
            Error\Exception::class,
        );

        $this->sources = $config['sources'];
    }


    /**
     * Prompt the user with a list of authentication sources.
     *
     * @param array &$state Information about the current authentication.
     */
    public function authenticate(array &$state): never
    {
        Logger::debug("Multiauthsinglepage - authenticate");
        // We are going to need the authId in order to retrieve this authentication source later
        $state[self::AUTHID] = $this->authId;
        // The configured sources, so the login page knows what to offer.
        $state[self::SOURCESID] = $this->sources;

        $id = Auth\State::saveState($state, self::STAGEID);
        $url = Module::getModuleURL('multiauthsinglepage/login');

        // in case user wants a specific authsource
        $request = Request::createFromGlobals();
        $directAuthSource = $request->query->get('authsource') ?? $request->request->get('authsource');

        $httpUtils = new HTTP();
        $httpUtils->redirectTrustedURL($url, ['AuthState' => $id, 'authsource' => $directAuthSource]);
    }


    /**
     * Handle login request.
     *
     * This function hands the request over to the selected authentication source. On success
     * it does not return (the source redirects and completes the login). Failures are thrown
     * as exceptions.
     *
     * @param \SimpleSAML\Auth\Source $source The selected authentication source.
     * @param array $state                    Information about the current authentication.
     */
    public static function handleLogin(Auth\Source $source, array $state): void
    {
        Logger::debug("Multiauthsinglepage - handleLogin");

        self::setSessionSource($source, $state);
        $source->authenticate($state);
        Auth\Source::completeAuth($state);

        throw new \LogicException('Auth\Source::completeAuth() should never return.');
    }


    /**
     * Authenticate directly against an LDAP source with the given credentials.
     *
     * @param \SimpleSAML\Module\ldap\Auth\Source\Ldap $source The LDAP authentication source.
     * @param array $state                                     Information about the current authentication.
     * @param string|null $username                            The username entered by the user.
     * @param string|null $pass                                The password entered by the user.
     */
    public static function handleLoginPass(Ldap $source, array $state, ?string $username, ?string $pass): void
    {
        Logger::debug("Multiauthsinglepage - handleLoginPass $username login attempt");
        if ($username === null || $pass === null) {
            throw new Error\Error(Error\ErrorCodes::WRONGUSERPASS);
        }
        try {
            self::setSessionSource($source, $state);
            if ($source instanceof LdapSinglePage) {
                $result = $source->loginSinglePage($username, $pass);
            } else {
                // Plain "ldap:Ldap" source: Ldap::login() is protected. Configuring the
                // source as "multiauthsinglepage:LdapSinglePage" avoids this reflection.
                $result = (new \ReflectionMethod($source, 'login'))->invoke($source, $username, $pass);
            }
            Logger::stats("Multiauthsinglepage - handleLoginPass $username login success");
        } catch (Error\Exception $e) {
            $msg = "Multiauthsinglepage - handleLoginPass $username unsuccessful login attempt.";
            Logger::debug($msg . $e->getMessage());
            Logger::stats($msg);
            throw $e;
        }
        $state['Attributes'] = $result;
        Auth\Source::completeAuth($state);
    }


    public static function loginCompleted(array $state): void
    {
        Logger::debug("Multiauthsinglepage - loginCompleted");
        parent::loginCompleted($state);
    }


    public static function setSessionSource(Auth\Source $source, array $state)
    {
        // Save the selected authentication source for the logout process.
        $session = Session::getSessionFromRequest();
        Logger::debug("Multiauthsinglepage - session source " . $state[self::AUTHID] . " " . $source->getAuthId());
        $session->setData(
            self::SESSION_SOURCE,
            $state[self::AUTHID],
            $source->getAuthId(),
            Session::DATA_TIMEOUT_SESSION_END,
        );
    }


    /**
     * Log out from this authentication source.
     *
     * This method retrieves the authentication source used for this
     * session and then call the logout method on it.
     *
     * @param array &$state Information about the current logout operation.
     */
    public function logout(array &$state): void
    {
        // Get the source that was used to authenticate
        $session = Session::getSessionFromRequest();
        $authId = $session->getData(self::SESSION_SOURCE, $this->authId);
        Logger::debug("Multiauthsinglepage - logout " . $authId);
        $source = Auth\Source::getById($authId);
        if ($source === null) {
            throw new Error\Exception('Invalid authentication source during logout: ' . $authId);
        }

        // Then, do the logout on it
        $source->logout($state);
    }
}

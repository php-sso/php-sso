<?php

declare(strict_types=1);

use PhpSso\Account\AccountController;
use PhpSso\Admin\AdminController;
use PhpSso\Admin\RelationPicker;
use PhpSso\Admin\AdminTwigExtension;
use PhpSso\Auth\Actions\AcceptConsentsAction;
use PhpSso\Auth\Actions\ChangePasswordAction;
use PhpSso\Auth\Actions\RequiredActions;
use PhpSso\Auth\Identifier\IdentifierResolverFactory;
use PhpSso\Auth\LoginController;
use PhpSso\Auth\Mfa\Mfa;
use PhpSso\Auth\Mfa\MfaChallenge;
use PhpSso\Auth\Mfa\SecretBox;
use PhpSso\Auth\MfaLoginController;
use PhpSso\Auth\RequiredActionController;
use PhpSso\Integration\Saml\StoreConnectionRepository;
use PhpSso\Integration\Saml\StoreRequestStore;
use PhpSso\Saml\ServiceProvider\SignIn;
use PhpSso\Auth\SamlController;
use PhpSso\Auth\Social\AccountMatcher;
use PhpSso\Auth\Social\HttpJson;
use PhpSso\Integration\Scim\StoreClientAuthenticator;
use PhpSso\Integration\Scim\StoreEventLog;
use PhpSso\Integration\Scim\StoreGroupDirectory;
use PhpSso\Integration\Scim\StoreManagedGroups;
use PhpSso\Integration\Scim\StoreManagedUsers;
use PhpSso\Integration\Scim\UserAccounts;
use PhpSso\ScimServer\ScimController;
use PhpSso\ScimServer\ScimGroupService;
use PhpSso\ScimServer\ScimService;
use PhpSso\Auth\Social\SocialLogin;
use PhpSso\Auth\AccountPolicy;
use PhpSso\Auth\Social\SocialProviders;
use PhpSso\Auth\SocialController;
use PhpSso\Auth\UserRules;
use PhpSso\Auth\LoginFlow;
use PhpSso\Auth\LoginThrottle;
use PhpSso\Claims;
use PhpSso\Database;
use PhpSso\Keys;
use PhpSso\OAuth\AuthorizationCodes;
use PhpSso\OAuth\BrowserErrors;
use PhpSso\OAuth\ClientAuthThrottle;
use PhpSso\OAuth\DevicePage;
use PhpSso\OAuth\DeviceRequests;
use PhpSso\OAuth\LogoutScreens;
use PhpSso\OAuth\RevokedAccessTokens;
use PhpSso\OAuth\SessionLinks;
use PhpSso\OAuth\AuthorizationScreens;
use PhpSso\OAuth\ClientAccess;
use PhpSso\OAuth\RefreshTokens;
use PhpSso\OAuth\RegisteredClients;
use PhpSso\Pages;
use PhpSso\Seeder;
use PhpSso\AccessTester;
use PhpSso\IpPolicy;
use PhpSso\Net\ClientIp;
use PhpSso\Net\StreamHttpClient;
use PhpSso\Store\Clients;
use PhpSso\Store\Consents;
use PhpSso\Store\IpRules;
use PhpSso\Store\Groups;
use PhpSso\Store\Relations;
use PhpSso\Store\SamlConnections;
use PhpSso\Store\SamlRequests;
use PhpSso\Store\ScimClients;
use PhpSso\Store\ScimEvents;
use PhpSso\Store\ScimGroups;
use PhpSso\Store\ScimUsers;
use PhpSso\Store\Roles;
use PhpSso\Store\Scopes;
use PhpSso\Store\HttpCache;
use PhpSso\Store\Identities;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\MfaFactors;
use PhpSso\Store\Sessions;
use PhpSso\Store\Settings;
use PhpSso\Store\Users;
use PhpSso\Sso;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use PhpSso\OAuthServer\AuthorizationServer;
use PhpSso\OAuthServer\Config\ServerConfig;
use PhpSso\OAuthServer\Endpoint\Endpoints;
use PhpSso\OAuthServer\Clock\SystemClock;
use PhpSso\OAuthServer\Device\DeviceVerifier;
use PhpSso\OAuthServer\Logout\LogoutSupport;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';

$db = new Database($config['database']);
$keys = new Keys($config['keys_dir']);

$users = new Users($db);
$userRules = new UserRules($users, $config['login']['identifier']);
$groups = new Groups($db);
$clients = new Clients($db);
$roles = new Roles($db);
$scopes = new Scopes($db, array_keys($config['scopes']));
$refreshTokens = new RefreshTokens($db);
$sessions = new Sessions($db, $config['session_idle_seconds']);
$attempts = new LoginAttempts($db);
$ipRules = new IpRules($db);
$ipPolicy = new IpPolicy($ipRules);
$clientIp = new ClientIp($config['trusted_proxies']);
$db->enforceUniqueEmail(IdentifierResolverFactory::usesEmail($config['login']['identifier']));
(new Seeder($users, $groups, $clients, $roles))->seed($config['seed']);

$twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
    'cache' => $config['twig_cache'],
    'auto_reload' => true,
    'strict_variables' => true,
    'autoescape' => 'html',
]);
$twig->addExtension(new AdminTwigExtension(__DIR__));
$pages = new Pages(dirname(__DIR__) . '/templates/pages', $config['theme_dir'], dirname($config['twig_cache']) . '/pages', __DIR__, ['app_name' => $config['app_name']], $config['debug']);

$claims = new Claims($users, $roles, $config['scopes']);

$sessionLinks = new SessionLinks($sessions);
$oauth = new AuthorizationServer(
    config: new ServerConfig(
        issuer: $config['issuer'],
        scopes: $scopes->names(),
        authorizationCodeTtl: $config['auth_code_ttl'],
        accessTokenTtl: $config['access_token_ttl'],
        idTokenTtl: $config['access_token_ttl'],
        refreshTokenTtl: $config['refresh_token_ttl'],
        requirePkceForConfidentialClients: $config['require_pkce_for_confidential_clients'],
    ),
    endpoints: new Endpoints(), // the library's default paths are ours
    clients: new RegisteredClients($clients, $scopes->builtinNames()),
    claims: $claims,
    policy: new ClientAccess($clients),
    ui: new AuthorizationScreens($pages, $users),
    keys: $keys->provider,
    codes: new AuthorizationCodes($db),
    refreshTokens: $refreshTokens,
    devices: new DeviceRequests($db),
    revokedAccessTokens: new RevokedAccessTokens($db),
    logout: new LogoutSupport($sessionLinks, $sessionLinks, new LogoutScreens($pages, $users), new StreamHttpClient(), new RequestFactory()),
    responseFactory: new ResponseFactory(),
    streamFactory: new StreamFactory(),
    claimNames: ['sub', 'iss', 'aud', 'exp', 'iat', 'nonce', 'auth_time', 'amr', 'name', 'email', 'email_verified', 'groups', 'roles'],
    browserErrors: new BrowserErrors($pages),
);

// Whenever a login session ends (sign-out, admin, password change): revoke what it issued, tell the apps.
$sessions->onEnded(static function (array $ended) use ($oauth): void {
    foreach ($ended as $session) {
        $oauth->logoutService()->sessionEnded($session['id'], $session['user_sub']);
    }
});

$consents = new Consents($db);
$requiredActions = new RequiredActions([new AcceptConsentsAction($consents), new ChangePasswordAction($users, $sessions)]);
$loginFlow = new LoginFlow($sessions, $attempts, $requiredActions);
$impersonation = new \PhpSso\Auth\Impersonation($sessions, $users);
$settings = new Settings($db);
$throttle = new LoginThrottle($attempts, $settings, $config['login']['throttle']);
$mfa = new Mfa(new MfaFactors($db), new SecretBox($config['keys_dir'] . '/mfa.key'), $settings, $config['app_name'], $config['login']['mfa']['policy']);
$mfaChallenge = new MfaChallenge($mfa, $throttle, $attempts);
$socialProviders = SocialProviders::fromConfig($config['login']['social']['providers'], new HttpJson(new StreamHttpClient(5.0), new RequestFactory(), new StreamFactory()), new HttpCache($db));
$identities = new Identities($db);
$samlConnections = new SamlConnections($db);
$scimClients = new ScimClients($db);
$scimEvents = new ScimEvents($db);
$scimUsers = new ScimUsers($db);
$scimGroups = new ScimGroups($db);
$samlService = new SignIn(new StoreConnectionRepository($samlConnections), new StoreRequestStore(new SamlRequests($db)), $config['issuer']);
$accountMatcher = new AccountMatcher($identities, $users);
$socialPolicy = new AccountPolicy($settings, $config['login']['social']);
$social = new SocialLogin($socialProviders, $identities, $users, $socialPolicy, $config['issuer'], $accountMatcher, $samlConnections);
$verifier = new DeviceVerifier(new DeviceRequests($db), new RegisteredClients($clients, $scopes->builtinNames()), new ClientAccess($clients), new SystemClock());
$sso = new Sso($config, $oauth, $users, new AdminController($twig, $users, $groups, $clients, $roles, $sessions, $attempts, $ipRules, $ipPolicy, $clientIp, new AccessTester($ipPolicy, $clients, $users), new RelationPicker($twig, new Relations($db), $users, $groups, $clients, $roles), $userRules, $throttle, $mfa, $social, $consents, $samlConnections, $samlService, $scimClients, $scimEvents, $scimUsers, $scimGroups, $config['issuer'], $impersonation, $scopes), $sessions, $attempts, $clients, $pages, new AccountController($pages, $users, $sessions, $userRules, $mfa, $mfaChallenge, $clientIp, $social), new DevicePage($pages, $verifier, $db), $clientIp, $ipPolicy, $loginFlow, new LoginController($pages, $loginFlow, IdentifierResolverFactory::create($config['login']['identifier'], $users), $users, $attempts, $clientIp, $throttle, $mfa, $socialProviders, $samlConnections), new MfaLoginController($pages, $loginFlow, $mfa, $mfaChallenge, $users, $clientIp), new SocialController($social, $loginFlow, $mfa, $clientIp), new SamlController($samlService, $samlConnections, $accountMatcher, $socialPolicy, $loginFlow, $mfa, $clientIp), new RequiredActionController($pages, $loginFlow, $requiredActions, $users, $clientIp), $requiredActions, new ClientAuthThrottle($db, $config['client_auth_throttle']), $impersonation);

// Provisioning clients call the SCIM endpoints with a bearer token: no cookie, no session.
$request = ServerRequestFactory::fromGlobals();
if (str_starts_with($request->getUri()->getPath(), '/scim/')) {
    $managedUsers = new StoreManagedUsers($scimUsers);
    $managedGroups = new StoreManagedGroups($scimGroups);
    $accounts = new UserAccounts($users, $sessions, $userRules, $socialPolicy);
    $scim = new ScimController(
        new StoreClientAuthenticator($scimClients),
        new ScimService($managedUsers, $accounts, $config['issuer']),
        new ScimGroupService(new StoreGroupDirectory($groups), $managedGroups, $managedUsers, $accounts, $config['issuer']),
        $managedUsers,
        $managedGroups,
        new StoreEventLog($scimEvents),
        new ResponseFactory(),
        new StreamFactory(),
        $config['issuer'],
    );
    (new SapiEmitter())->emit($scim->handle($request));
    exit;
}

ini_set('session.gc_maxlifetime', (string) $config['session_idle_seconds']);
session_name('sso_session');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

(new SapiEmitter())->emit($sso->handle($request));

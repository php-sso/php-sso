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
use PhpSso\Keys;
use PhpSso\OAuth\BrowserErrors;
use PhpSso\OAuth\ClientAuthThrottle;
use PhpSso\OAuth\DevicePage;
use PhpSso\OAuth\LogoutScreens;
use PhpSso\OAuth\SessionLinks;
use PhpSso\OAuth\AuthorizationScreens;
use PhpSso\OAuth\ClientAccess;
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
use PhpSso\Store\StorageFactory;
use PhpSso\Store\StorageOptions;
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
use PhpSso\Audit\Audit;
use PhpSso\Http\SecurityHeaders;
use PhpSso\Startup\Checks;
use PhpSso\Store\SchemaTooNew;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\TextResponse;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';

$secure = str_starts_with($config['issuer'], 'https://');
$headers = new SecurityHeaders($config['csp'], $secure);
$emitter = new SapiEmitter();
$request = ServerRequestFactory::fromGlobals();

// A setup that would weaken the server stops it, and says why, instead of serving anyone.
$refuse = static function (array $problems) use ($emitter, $headers): never {
    error_log('SSO: refusing to start: ' . implode(' ', $problems));
    $emitter->emit($headers->apply(new TextResponse("php-sso is not set up safely:\n\n- " . implode("\n- ", $problems) . "\n", 500)));
    exit;
};
$problems = Checks::problems($config['issuer'], $config['demo'], $config['allow_insecure_http']);
if ($problems !== []) {
    $refuse($problems);
}

try {
    $storage = StorageFactory::create($config['storage'], StorageOptions::fromConfig($config));
} catch (SchemaTooNew $e) { // a database a newer version has upgraded: nothing was changed, and nothing is served
    $refuse([$e->getMessage()]);
}

// With SSO_AUTO_MIGRATE off the schema is changed by `bin/migrate up` only: until then nothing is served, /healthz included.
if (!$config['storage']['auto_migrate']) {
    $problems = [...Checks::unknownMigrations($storage->maintenance()->unknownMigrations()), ...Checks::pendingMigrations($storage->maintenance()->pendingMigrations())];
    if ($problems !== []) {
        $refuse($problems);
    }
}

// For a load balancer or a container health check: does the server answer, and does the database? Nothing else is said.
if ($request->getUri()->getPath() === '/healthz' && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
    $healthy = $storage->maintenance()->isHealthy();
    $emitter->emit($headers->apply(new JsonResponse(['status' => $healthy ? 'ok' : 'unavailable'], $healthy ? 200 : 503)));
    exit;
}

$keys = new Keys($config['keys_dir'], $config['key_retention_seconds']);

$users = $storage->users();
$userRules = new UserRules($users, $config['login']['identifier']);
$groups = $storage->groups();
$clients = $storage->clients();
$roles = $storage->roles();
$scopes = $storage->scopes();
$refreshTokens = $storage->refreshTokens();
$sessions = $storage->sessions();
$attempts = $storage->loginAttempts();
$ipRules = $storage->ipRules();
$ipPolicy = new IpPolicy($ipRules);
$audit = new Audit($storage->auditLog(), $config['audit']['stream']);
$clientIp = new ClientIp($config['trusted_proxies']);
$storage->enforceUniqueEmail(IdentifierResolverFactory::usesEmail($config['login']['identifier']));
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
    codes: $storage->authorizationCodes(),
    refreshTokens: $refreshTokens,
    devices: $storage->deviceRequests(),
    revokedAccessTokens: $storage->revokedAccessTokens(),
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

$consents = $storage->consents();
$requiredActions = new RequiredActions([new AcceptConsentsAction($consents), new ChangePasswordAction($users, $sessions)]);
$loginFlow = new LoginFlow($sessions, $attempts, $requiredActions);
$impersonation = new \PhpSso\Auth\Impersonation($sessions, $users, $audit);
$settings = $storage->settings();
$throttle = new LoginThrottle($attempts, $settings, $config['login']['throttle']);
$mfa = new Mfa($storage->mfaFactors(), new SecretBox($config['keys_dir'] . '/mfa.key'), $settings, $config['app_name'], $config['login']['mfa']['policy']);
$mfaChallenge = new MfaChallenge($mfa, $throttle, $attempts);
$socialProviders = SocialProviders::fromConfig($config['login']['social']['providers'], new HttpJson(new StreamHttpClient(5.0), new RequestFactory(), new StreamFactory()), $storage->httpCache());
$identities = $storage->identities();
$samlConnections = $storage->samlConnections();
$scimClients = $storage->scimClients();
$scimEvents = $storage->scimEvents();
$scimUsers = $storage->scimUsers();
$scimGroups = $storage->scimGroups();
$samlService = new SignIn(new StoreConnectionRepository($samlConnections), new StoreRequestStore($storage->samlRequests()), $config['issuer']);
$accountMatcher = new AccountMatcher($identities, $users);
$socialPolicy = new AccountPolicy($settings, $config['login']['social']);
$social = new SocialLogin($socialProviders, $identities, $users, $socialPolicy, $config['issuer'], $accountMatcher, $samlConnections);
$verifier = new DeviceVerifier($storage->deviceRequests(), new RegisteredClients($clients, $scopes->builtinNames()), new ClientAccess($clients), new SystemClock());
$sso = new Sso($config, $oauth, $users, new AdminController($twig, $users, $groups, $clients, $roles, $sessions, $attempts, $ipRules, $ipPolicy, $clientIp, new AccessTester($ipPolicy, $clients, $users), new RelationPicker($twig, $storage->relations(), $users, $groups, $clients, $roles), $userRules, $throttle, $mfa, $social, $consents, $samlConnections, $samlService, $scimClients, $scimEvents, $scimUsers, $scimGroups, $config['issuer'], $impersonation, $scopes, $audit, $storage->auditLog()), $sessions, $attempts, $clients, $pages, new AccountController($pages, $users, $sessions, $userRules, $mfa, $mfaChallenge, $clientIp, $social, $audit), new DevicePage($pages, $verifier, $storage->deviceFailures()), $clientIp, $ipPolicy, $loginFlow, new LoginController($pages, $loginFlow, IdentifierResolverFactory::create($config['login']['identifier'], $users), $users, $attempts, $clientIp, $throttle, $mfa, $socialProviders, $samlConnections), new MfaLoginController($pages, $loginFlow, $mfa, $mfaChallenge, $users, $clientIp), new SocialController($social, $loginFlow, $mfa, $clientIp), new SamlController($samlService, $samlConnections, $accountMatcher, $socialPolicy, $loginFlow, $mfa, $clientIp), new RequiredActionController($pages, $loginFlow, $requiredActions, $users, $clientIp), $requiredActions, new ClientAuthThrottle($storage->clientAuthFailures(), $config['client_auth_throttle']), $impersonation);

// Provisioning clients call the SCIM endpoints with a bearer token: no cookie, no session.
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
    $emitter->emit($headers->apply($scim->handle($request)));
    exit;
}

// The browser session: its files in our own folder (not the shared temporary one, which other programs read and clean), only ever
// from a cookie, an id the server did not make is not accepted, and over HTTPS the cookie is bound to this host (`__Host-`) and never sent in the clear.
if (!is_dir($config['session_dir'])) {
    mkdir($config['session_dir'], 0700, true);
}
ini_set('session.save_path', $config['session_dir']);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string) $config['session_idle_seconds']);
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');
session_name($secure ? '__Host-sso_session' : 'sso_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]);
session_start();

// Housekeeping rides along with about one request in a hundred, after the response is sent; `bin/maintenance` does it on a schedule.
if (random_int(1, 100) === 1) {
    register_shutdown_function(static fn () => $storage->maintenance()->purgeExpired());
}

$emitter->emit($headers->apply($sso->handle($request)));

<?php

declare(strict_types=1);

namespace PhpSso;

use PhpSso\Auth\PasswordResetController;
use PhpSso\Admin\AdminController;
use PhpSso\Account\AccountController;
use PhpSso\Auth\Actions\RequiredActions;
use PhpSso\Auth\Impersonation;
use PhpSso\Auth\LoginController;
use PhpSso\Auth\LoginFlow;
use PhpSso\Auth\MfaLoginController;
use PhpSso\Auth\RequiredActionController;
use PhpSso\Auth\SamlController;
use PhpSso\Auth\SocialController;
use PhpSso\OAuth\ClientAuthThrottle;
use PhpSso\OAuth\DevicePage;
use PhpSso\Net\ClientIp;
use PhpSso\Store\Clients;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\Sessions;
use PhpSso\Store\Users;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Response\TextResponse;
use PhpSso\OAuthServer\AuthorizationServer;
use PhpSso\OAuthServer\Endpoint\AuthorizationHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PhpSso\Store\UserState;

/**
 * HTTP layer of the OpenID Provider. The protocol itself (codes, PKCE, tokens, errors)
 * is handled by php-sso/oauth-server; this class wires it to our endpoints and the login session.
 */
final class Sso
{
    /** The endpoints where an app proves who it is with its client secret. */
    private const CLIENT_AUTH_PATHS = ['/token', '/introspect', '/revoke', '/device_authorization'];

    public function __construct(
        private array $config,
        private AuthorizationServer $oauth,
        private Users $users,
        private AdminController $admin,
        private Sessions $sessions,
        private LoginAttempts $attempts,
        private Clients $clients,
        private Pages $pages,
        private AccountController $account,
        private DevicePage $device,
        private ClientIp $clientIp,
        private IpPolicy $ipPolicy,
        private LoginFlow $loginFlow,
        private LoginController $login,
        private MfaLoginController $mfaLogin,
        private SocialController $social,
        private SamlController $saml,
        private RequiredActionController $requiredAction,
        private RequiredActions $requiredActions,
        private ClientAuthThrottle $clientAuthThrottle,
        private Impersonation $impersonation,
        private ?PasswordResetController $passwordReset = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $this->impersonation->keepAlive();
        $this->pages->share('impersonation', $this->impersonationBanner());
        if ($request->getMethod() === 'POST' && $path === '/impersonate/stop') {
            return $this->stopImpersonating($request);
        }
        // Browser pages: the person's address must be allowed. The endpoints that hand out tokens to apps' servers
        // (/token, /device_authorization) get the same check against the caller's address, global and per client.
        if ($this->isBrowserPage($path) && !$this->browserAddressAllowed($request, $path)) {
            return $this->blocked($request, $this->clientIp->of($request));
        }
        if ($request->getMethod() === 'POST' && in_array($path, ['/token', '/device_authorization'], true)
            && ($denied = $this->tokenRequestDenied($request)) !== null) {
            return $denied;
        }
        if ($request->getMethod() === 'POST' && in_array($path, self::CLIENT_AUTH_PATHS, true)) {
            return $this->clientEndpoint($request, $path);
        }
        if ($this->passwordReset !== null && ($response = $this->passwordReset->handle($request, $path)) !== null) {
            return $response;
        }
        if (str_starts_with($path, '/admin')) {
            return $this->adminArea($request);
        }
        if ($path === '/account' || str_starts_with($path, '/account/')) {
            return $this->accountArea($request);
        }
        if (str_starts_with($path, '/theme/assets/') && $request->getMethod() === 'GET') {
            return $this->themeAsset(substr($path, strlen('/theme/assets/')));
        }

        if ($request->getMethod() === 'GET' && preg_match('#^/login/saml/(\d+)$#', $path, $company) === 1) {
            return $this->saml->start((int) $company[1]);
        }
        if (preg_match('#^/saml/(\d+)/(acs|metadata)$#', $path, $company) === 1) {
            if ($company[2] === 'acs' && $request->getMethod() === 'POST') {
                return $this->saml->acs($request, (int) $company[1]);
            }
            if ($company[2] === 'metadata' && $request->getMethod() === 'GET') {
                return $this->saml->metadata((int) $company[1]);
            }
        }

        if ($request->getMethod() === 'GET' && preg_match('#^/login/social/([a-z0-9_-]+)(/callback)?$#', $path, $social) === 1) {
            return isset($social[2]) ? $this->social->callback($request, $social[1]) : $this->social->start($social[1]);
        }

        return match ([$request->getMethod(), $request->getUri()->getPath()]) {
            ['GET', '/'] => $this->home(),
            ['GET', '/.well-known/openid-configuration'], ['GET', '/.well-known/oauth-authorization-server'] => $this->oauth->discoveryHandler()->handle($request),
            ['GET', '/end-session'], ['POST', '/end-session'] => $this->endSession($request),
            ['GET', '/device'], ['POST', '/device'] => $this->devicePage($request),
            ['GET', '/jwks'] => $this->oauth->jwksHandler()->handle($request),
            ['GET', '/authorize'], ['POST', '/authorize'] => $this->authorize($request), // OpenID Connect Core 3.1.2.1: both methods
            ['GET', '/login'] => $this->login->identifyForm(),
            ['POST', '/login'] => $this->login->identify($request),
            ['GET', '/login/password'] => $this->login->passwordForm(),
            ['POST', '/login/password'] => $this->login->password($request),
            ['GET', '/login/mfa'] => $this->mfaLogin->challengeForm(),
            ['POST', '/login/mfa'] => $this->mfaLogin->challenge($request),
            ['GET', '/login/mfa/setup'] => $this->mfaLogin->setupForm(),
            ['POST', '/login/mfa/setup'] => $this->mfaLogin->setup($request),
            ['POST', '/login/mfa/continue'] => $this->mfaLogin->finish($request),
            ['GET', '/login/required'] => $this->requiredAction->form(request: $request),
            ['POST', '/login/required'] => $this->requiredAction->submit($request),
            ['GET', '/userinfo'], ['POST', '/userinfo'] => $this->oauth->bearerTokenMiddleware(['openid'])->process($request, $this->oauth->userInfoHandler()),
            ['GET', '/logout'] => $this->logout($request),
            default => $this->pages->render('error', ['title' => 'Page not found', 'message' => 'There is nothing at this address.', 'user' => null, 'csrf' => ''], 404),
        };
    }

    /**
     * The endpoints apps call with their client secret. An address that keeps getting the secret wrong is answered with
     * 429 before the OAuth server looks at the credentials, so a secret cannot be guessed at full speed.
     */
    private function clientEndpoint(ServerRequestInterface $request, string $path): ResponseInterface
    {
        $ip = $this->clientIp->of($request);
        $clientId = $this->requestedClientId($request);

        $wait = $this->clientAuthThrottle->lockedFor($clientId, $ip);
        if ($wait !== null) {
            return new JsonResponse(
                ['error' => 'temporarily_unavailable', 'error_description' => 'Too many failed client authentications. Try again later.'],
                429,
                ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Retry-After' => (string) $wait],
            );
        }

        $response = match ($path) {
            '/token' => $this->oauth->tokenHandler()->handle($request),
            '/introspect' => $this->oauth->introspectionHandler()->handle($request),
            '/revoke' => $this->oauth->revocationHandler()->handle($request),
            default => $this->oauth->deviceAuthorizationHandler()->handle($request),
        };

        if ($response->getStatusCode() === 401 && $this->isInvalidClient($response)) {
            $this->clientAuthThrottle->recordFailure($clientId, $ip);
        }

        return $response;
    }

    private function isInvalidClient(ResponseInterface $response): bool
    {
        $body = $response->getBody();
        $text = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $data = json_decode($text, true);

        return is_array($data) && ($data['error'] ?? null) === 'invalid_client';
    }

    private function isBrowserPage(string $path): bool
    {
        return in_array($path, ['/authorize', '/logout', '/end-session', '/device'], true) || $path === '/login' || str_starts_with($path, '/login/') || preg_match('#^/saml/\d+/acs$#', $path) === 1
            || $path === '/admin' || str_starts_with($path, '/admin/')
            || $path === '/account' || str_starts_with($path, '/account/')
            || str_starts_with($path, '/password/');
    }

    /**
     * The general rules decide, except for an app that ignores them: its /authorize request, and the sign-in page the
     * person is sent to for it, are judged by that app's rules (/authorize checks them in full, see authorize()).
     */
    private function browserAddressAllowed(ServerRequestInterface $request, string $path): bool
    {
        $ip = $this->clientIp->of($request);
        if ($this->ipPolicy->allows($ip)) {
            return true;
        }
        $clientId = match ($path) {
            '/authorize' => $this->authorizationClientId($request),
            '/login', '/login/password', '/login/mfa', '/login/mfa/setup', '/login/mfa/continue', '/login/required' => $_SESSION['ip_exempt_client'] ?? null,
            default => null,
        };

        return is_string($clientId) && $this->ipPolicy->ignoresGeneral($clientId) && $this->ipPolicy->allowsClient($ip, $clientId);
    }

    /** Refuses a browser request from an address the IP rules do not allow. Refused sign-ins are kept in the history. */
    private function blocked(ServerRequestInterface $request, string $ip): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            $username = match ($request->getUri()->getPath()) {
                '/login' => (string) (((array) $request->getParsedBody())['username'] ?? ''),
                '/login/password' => $this->loginFlow->pending()['identifier'] ?? '',
                default => null,
            };
            if ($username !== null) {
                $this->attempts->record($username, null, false, $ip, $request->getHeaderLine('User-Agent'), true);
            }
        }

        return $this->pages->render('error', ['title' => 'Access not allowed', 'message' => 'Signing in is not allowed from your network address.', 'user' => null, 'csrf' => ''], 403);
    }

    /**
     * Refuses a request for tokens from an address the IP rules do not allow, the global rules and those of the client
     * it names (Basic header or form body). Nothing is checked about the credentials here: that stays with the OAuth server.
     */
    private function tokenRequestDenied(ServerRequestInterface $request): ?ResponseInterface
    {
        $ip = $this->clientIp->of($request);
        $clientId = $this->requestedClientId($request);
        if ($clientId === null ? $this->ipPolicy->allows($ip) : $this->ipPolicy->allowsClient($ip, $clientId)) {
            return null;
        }

        return new JsonResponse(
            ['error' => 'access_denied', 'error_description' => 'Requesting tokens is not allowed from this network address.'],
            403,
            ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
        );
    }

    private function requestedClientId(ServerRequestInterface $request): ?string
    {
        if (preg_match('/^Basic\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $match) === 1) {
            $decoded = base64_decode($match[1], true);
            $separator = $decoded === false ? false : strpos($decoded, ':');
            if ($decoded !== false && $separator !== false) {
                return urldecode(substr($decoded, 0, $separator));
            }
        }
        $id = ((array) $request->getParsedBody())['client_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** The signed-in user's record, or null (also when the account was deleted since login). */
    private function currentUser(): ?array
    {
        $sub = $_SESSION['user'] ?? null;
        $sid = $_SESSION['sid'] ?? null;
        if (!is_string($sub) || !is_string($sid) || !$this->sessions->touch($sid, $sub)) {
            return null; // not signed in, expired, or ended by an administrator
        }

        $user = $this->users->find($sub);

        return $user === null || UserState::isLocked($user) ? null : $user; // a locked account is signed out at once
    }

    private function adminArea(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->currentUser();
        if ($user === null) {
            $_SESSION['return_to'] = $request->getUri()->getPath();

            return new RedirectResponse('/login');
        }
        if (($held = $this->heldForRequiredActions($user, $request)) !== null) {
            return $held;
        }
        if (!$user['is_admin']) {
            return $this->pages->render('error', $this->pageContext($user) + ['title' => 'Administrators only', 'message' => 'Your account does not have access to the admin panel.'], 403);
        }

        return $this->admin->handle($request, $user);
    }

    private function accountArea(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->currentUser();
        if ($user === null) {
            $_SESSION['return_to'] = '/account';

            return new RedirectResponse('/login');
        }
        if (($held = $this->heldForRequiredActions($user, $request)) !== null) {
            return $held;
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
        if ($request->getMethod() !== 'GET' && $this->impersonation->active() !== null) {
            return $this->pages->render('error', $this->pageContext($user) + ['title' => 'Not while browsing as someone else', 'message' => 'Changing the account is switched off while you are signed in as this person. Stop first, then change it as yourself.'], 403);
        }

        return $this->account->handle($request, $user);
    }

    /**
     * Someone who is already signed in but has since been made to do something first (an administrator required a new
     * password) is sent through that step before the page they asked for. They sign in again at the end of it, which
     * replaces their session.
     */
    private function heldForRequiredActions(array $user, ServerRequestInterface $request): ?ResponseInterface
    {
        // Someone browsing as this person must not be sent to change their password, or sign in again over the session.
        if ($this->requiredActions->next($user) === null || $this->impersonation->active() !== null) {
            return null;
        }
        if ($request->getMethod() === 'GET') {
            $query = $request->getUri()->getQuery();
            $_SESSION['return_to'] = $request->getUri()->getPath() . ($query === '' ? '' : '?' . $query);
        } elseif ($request->getUri()->getPath() === '/authorize') {
            $_SESSION['return_to'] = '/authorize?' . http_build_query((array) $request->getParsedBody());
        }
        $this->loginFlow->beginActions($user['sub'], $user['username'], $this->sessions->amr((string) ($_SESSION['sid'] ?? '')) ?? []); // they keep how they signed in

        return new RedirectResponse('/login/required', 303);
    }

    private function themeAsset(string $path): ResponseInterface
    {
        $asset = $this->pages->themeAsset($path);
        if ($asset === null) {
            return new TextResponse('Not found', 404);
        }

        $headers = ['Content-Type' => $asset['type'], 'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff'];
        if ($asset['type'] === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox"; // no scripts if opened directly
        }

        return new TextResponse((string) file_get_contents($asset['file']), 200, $headers);
    }

    /** Variables every page receives. */
    private function pageContext(?array $user): array
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));

        return [
            'csrf' => $_SESSION['csrf'],
            'user' => $user === null ? null : ['name' => $user['name'], 'username' => $user['username'], 'email' => $user['email'], 'is_admin' => (bool) $user['is_admin']],
        ];
    }

    private function home(): ResponseInterface
    {
        return new RedirectResponse($this->currentUser() ? '/account' : '/login');
    }

    /**
     * The OAuth server checks the request (client, redirect URI, scopes, PKCE) and issues the code. All it needs
     * from us is who is signed in, as request attributes; the sign-in page and the "no access" page come back
     * through AuthorizationScreens. First-party apps only: no consent screen.
     */
    private function authorize(ServerRequestInterface $request): ResponseInterface
    {
        // Rules of the client the person is heading to, with the general ones unless the client ignores them.
        $clientId = $this->authorizationClientId($request);
        if (is_string($clientId) && !$this->ipPolicy->allowsClient($this->clientIp->of($request), $clientId)) {
            $client = $this->clients->find($clientId);

            return $this->pages->render('error', ['title' => 'Access not allowed', 'message' => 'Signing in to ' . ($client['name'] ?? 'this app') . ' is not allowed from your network address.', 'user' => null, 'csrf' => ''], 403);
        }

        // The sign-in page this leads to is not tied to a client, so remember which app the person is heading to.
        if (is_string($clientId) && $this->ipPolicy->ignoresGeneral($clientId)) {
            $_SESSION['ip_exempt_client'] = $clientId;
        }

        $user = $this->currentUser();
        if ($user !== null && ($held = $this->heldForRequiredActions($user, $request)) !== null) {
            return $held;
        }

        return $this->oauth->authorizationHandler()->handle($this->withSignedInPerson($request, $user));
    }

    /** The `client_id` of an authorization request: in the query for a GET, in the form body for a POST. */
    private function authorizationClientId(ServerRequestInterface $request): mixed
    {
        $params = $request->getMethod() === 'POST' ? (array) $request->getParsedBody() : $request->getQueryParams();

        return $params['client_id'] ?? null;
    }

    /**
     * Tells the OAuth server who is signed in: the person, the login session, and when they last typed their
     * password (a session starts with a login, so that is when it began). `max_age` and `auth_time` need the last.
     */
    private function withSignedInPerson(ServerRequestInterface $request, ?array $user): ServerRequestInterface
    {
        if ($user === null) {
            return $request;
        }
        $sid = (string) $_SESSION['sid'];

        return $request
            ->withAttribute(AuthorizationHandler::USER_ID, $user['sub'])
            ->withAttribute(AuthorizationHandler::SESSION_ID, $sid)
            ->withAttribute(AuthorizationHandler::AUTH_TIME, $this->sessions->authTime($sid))
            ->withAttribute(AuthorizationHandler::AMR, $this->sessions->amr($sid));
    }

    /**
     * RP-initiated logout. The OAuth server ends the session, revokes its tokens and notifies the apps; the
     * confirmation form it may show is ours, so its POST is checked against the CSRF token here.
     */
    private function endSession(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->currentUser();
        if ($request->getMethod() === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', (string) (((array) $request->getParsedBody())['_csrf'] ?? ''))) {
            return $this->pages->render('error', $this->pageContext($user) + ['title' => 'Page expired', 'message' => 'Go back, reload the page and try again.'], 400);
        }

        return $this->oauth->endSessionHandler()->handle($this->withSignedInPerson($request, $user));
    }

    /** The page where a person approves a device that asked to sign in (RFC 8628). */
    private function devicePage(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->currentUser();
        if ($user !== null && ($held = $this->heldForRequiredActions($user, $request)) !== null) {
            return $held;
        }

        if ($user !== null && $request->getMethod() !== 'GET' && $this->impersonation->active() !== null) {
            return $this->pages->render('error', $this->pageContext($user) + ['title' => 'Not while browsing as someone else', 'message' => 'Approving a device is switched off while you are signed in as this person. Stop first, then approve it as yourself.'], 403);
        }

        return $user === null ? $this->device->loginRedirect($request) : $this->device->handle($request, $user);
    }

    /** What the banner on top of the pages shows while an administrator browses as someone else, or null. */
    private function impersonationBanner(): ?array
    {
        $held = $this->impersonation->active();
        $user = is_string($_SESSION['user'] ?? null) ? $this->users->find($_SESSION['user']) : null;
        $admin = $held === null ? null : $this->users->find($held['sub']);
        if ($held === null || $user === null || $admin === null) {
            return null;
        }

        return ['name' => $user['name'], 'by' => $admin['name'], 'csrf' => $_SESSION['csrf'] ??= bin2hex(random_bytes(16))];
    }

    /** The banner's button: back to being the administrator. */
    private function stopImpersonating(ServerRequestInterface $request): ResponseInterface
    {
        if (!hash_equals($_SESSION['csrf'] ?? '', (string) (((array) $request->getParsedBody())['_csrf'] ?? ''))) {
            return $this->pages->render('error', ['title' => 'Page expired', 'message' => 'Go back, reload the page and try again.', 'user' => null, 'csrf' => ''], 400);
        }

        return new RedirectResponse($this->impersonation->stop($this->clientIp->of($request)) ? '/admin/access-test' : '/login', 303);
    }

    private function logout(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->impersonation->active() !== null) { // leaving an impersonation, not signing the administrator out
            return new RedirectResponse($this->impersonation->stop($this->clientIp->of($request)) ? '/admin/access-test' : '/login');
        }
        $this->signOut();

        return new RedirectResponse('/login');
    }

    /** Ends this browser's login session (revoking its tokens and notifying apps) and clears the PHP session. */
    private function signOut(): void
    {
        if (isset($_SESSION['sid'])) {
            $this->sessions->end((string) $_SESSION['sid']);
        }
        $_SESSION = [];
        session_regenerate_id(true);
    }
}

<?php

declare(strict_types=1);

namespace PhpSso\Admin;

use PhpSso\Auth\PasswordReset;
use PhpSso\Mail\MailFailed;
use PhpSso\Audit\AdminActions;
use PhpSso\Audit\Audit;
use PhpSso\Store\AuditLog;
use PhpSso\Auth\Impersonation;
use PhpSso\Auth\LoginThrottle;
use PhpSso\Auth\Mfa\Mfa;
use PhpSso\Saml\ServiceProvider\SignIn;
use PhpSso\Auth\Social\SocialLogin;
use PhpSso\Auth\AccountPolicy;
use PhpSso\Auth\UserRules;
use PhpSso\AccessTester;
use PhpSso\IpPolicy;
use PhpSso\Net\Cidr;
use PhpSso\Net\ClientIp;
use PhpSso\Store\Clients;
use PhpSso\Store\Consents;
use PhpSso\Store\Groups;
use PhpSso\Store\IpRules;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\Roles;
use PhpSso\Store\Scopes;
use PhpSso\Store\SamlConnections;
use PhpSso\Store\ScimClients;
use PhpSso\Store\ScimEvents;
use PhpSso\Store\ScimGroups;
use PhpSso\Store\ScimUsers;
use PhpSso\Store\Sessions;
use PhpSso\Store\Users;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;
use PhpSso\Store\ScopeName;

/**
 * Admin panel for users, groups and clients. The caller has already checked that the
 * signed-in user is an administrator; this class adds CSRF protection, validation and the
 * "don't lock yourself out" rules.
 */
final class AdminController
{
    private const JSON_SAFE = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

    /** Grant types a client can be given. The password grant and the implicit flow do not exist here on purpose. */
    private const GRANTS = ['authorization_code', 'refresh_token', 'client_credentials', 'urn:ietf:params:oauth:grant-type:device_code'];

    /** What a new client gets: the normal browser sign-in, with refreshing. */
    private const DEFAULT_GRANTS = ['authorization_code', 'refresh_token'];

    /** @var array{username: string, sub: string, is_admin: int|bool} */
    private array $admin;

    public function __construct(
        private Environment $twig,
        private Users $users,
        private Groups $groups,
        private Clients $clients,
        private Roles $roles,
        private Sessions $sessions,
        private LoginAttempts $attempts,
        private IpRules $ipRules,
        private IpPolicy $ipPolicy,
        private ClientIp $clientIp,
        private AccessTester $tester,
        private RelationPicker $picker,
        private UserRules $rules,
        private LoginThrottle $throttle,
        private Mfa $mfa,
        private SocialLogin $social,
        private Consents $consents,
        private SamlConnections $samlConnections,
        private SignIn $samlService,
        private ScimClients $scimClients,
        private ScimEvents $scimEvents,
        private ScimUsers $scimUsers,
        private ScimGroups $scimGroups,
        private string $publicUrl,
        private Impersonation $impersonation,
        private Scopes $scopes,
        private Audit $audit,
        private AuditLog $auditLog,
        private ?PasswordReset $passwordReset = null,
    ) {
    }

    public function handle(ServerRequestInterface $request, array $admin): ResponseInterface
    {
        $this->admin = $admin;
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));

        $path = substr($request->getUri()->getPath(), strlen('/admin')) ?: '/';
        $path = $path === '/' ? $path : rtrim($path, '/');

        if ($request->getMethod() === 'POST' && !$this->csrfValid($request)) {
            return new TextResponse('Invalid CSRF token. Go back, reload the page and try again.', 400);
        }

        foreach ($this->routes() as [$method, $pattern, $handler]) {
            if ($method === $request->getMethod() && preg_match($pattern, $path, $m)) {
                $args = array_map('urldecode', array_slice($m, 1));
                // Every change an administrator makes is recorded: planned before it runs (so the name of what it deletes is still there), written once it succeeded.
                $plan = $method === 'POST' ? AdminActions::plan($handler, $args, (array) $request->getParsedBody(), fn (string $type, string $id): ?array => $this->auditFind($type, $id)) : null;
                $flashBefore = $_SESSION['flash'] ?? null;
                $response = $this->$handler($request, ...$args);
                if ($plan !== null) {
                    $this->recordAudit($plan, $request, $response, ($_SESSION['flash'] ?? null) !== $flashBefore ? ($_SESSION['flash']['type'] ?? null) : null);
                }

                return $response;
            }
        }

        return $this->render('error.html.twig', ['message' => 'Page not found'], 404);
    }

    /** @param array{action: string, type: string|null, target: string|null, label: string|null, detail: array<string, mixed>} $plan */
    private function recordAudit(array $plan, ServerRequestInterface $request, ResponseInterface $response, ?string $flashType): void
    {
        if (!AdminActions::succeeded($response->getStatusCode(), $flashType)) {
            return;
        }

        $type = $plan['type'];
        $target = $plan['target'];
        if ($target === null && $response->hasHeader('Location') && ($made = AdminActions::createdBy($response->getHeaderLine('Location'))) !== null) {
            [$type, $target] = $made; // a create: the id of what it made is in the address it sent the browser to
        }
        $this->audit->record($plan['action'], $this->admin, $this->clientIp->of($request), $type, $target, $plan['label'], $plan['detail']);
    }

    /** @return array<string, mixed>|null the row of a kind of thing, for the name the audit log copies; never throws, because a lookup must not stop a change */
    private function auditFind(string $type, string $id): ?array
    {
        try {
            return match ($type) {
                'user' => $this->users->find($id),
                'client' => $this->clients->find($id),
                'group' => ctype_digit($id) ? $this->groups->find((int) $id) : null,
                'role' => ctype_digit($id) ? $this->roles->find((int) $id) : null,
                'scim_client' => ctype_digit($id) ? $this->scimClients->find((int) $id) : null,
                'saml_connection' => ctype_digit($id) ? $this->samlConnections->find((int) $id) : null,
                'consent' => ctype_digit($id) ? $this->consents->find((int) $id) : null,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, array{string, string, string}> */
    private function routes(): array
    {
        return [
            ['GET', '#^/$#', 'dashboard'],

            ['GET', '#^/audit$#', 'auditList'],
            ['POST', '#^/sessions/([0-9a-f]{32})/end$#', 'sessionEnd'],

            ['GET', '#^/scopes$#', 'scopeList'],
            ['GET', '#^/scopes/new$#', 'scopeNew'],
            ['POST', '#^/scopes$#', 'scopeCreate'],
            ['GET', '#^/scopes/([^/]+)/edit$#', 'scopeEdit'],
            ['GET', '#^/scopes/([^/]+)/danger$#', 'scopeDanger'],
            ['POST', '#^/scopes/([^/]+)$#', 'scopeUpdate'],
            ['POST', '#^/scopes/([^/]+)/delete$#', 'scopeDelete'],

            ['GET', '#^/ip-rules$#', 'ipRuleList'],
            ['POST', '#^/ip-rules$#', 'ipRuleCreate'],
            ['POST', '#^/ip-rules/(\d+)/delete$#', 'ipRuleDelete'],
            ['GET', '#^/clients/([^/]+)/ip-rules$#', 'clientIpRules'],
            ['GET', '#^/clients/([^/]+)/network$#', 'clientNetworkMoved'],
            ['POST', '#^/clients/([^/]+)/ip-rules$#', 'clientIpRuleCreate'],
            ['POST', '#^/clients/([^/]+)/ip-rules/scope$#', 'clientIpRuleScope'],
            ['POST', '#^/clients/([^/]+)/ip-rules/(\d+)/delete$#', 'clientIpRuleDelete'],

            ['GET', '#^/access-test$#', 'accessTest'],
            ['POST', '#^/access-test/impersonate$#', 'impersonate'],
            ['POST', '#^/users/([^/]+)/impersonate$#', 'userImpersonate'],

            ['GET', '#^/security$#', 'securityPage'],
            ['GET', '#^/security/limits$#', 'securityLimitsPage'],
            ['GET', '#^/security/mfa$#', 'securityMfaPage'],
            ['GET', '#^/security/social$#', 'securitySocialPage'],
            ['GET', '#^/security/saml$#', 'samlList'],
            ['GET', '#^/security/scim$#', 'scimList'],
            ['GET', '#^/scim/new$#', 'scimNew'],
            ['POST', '#^/scim$#', 'scimCreate'],
            ['GET', '#^/scim/(\d+)/edit$#', 'scimEdit'],
            ['POST', '#^/scim/(\d+)$#', 'scimUpdate'],
            ['POST', '#^/scim/(\d+)/token$#', 'scimRotate'],
            ['POST', '#^/scim/(\d+)/delete$#', 'scimDelete'],
            ['GET', '#^/security/accounts$#', 'securityAccountsPage'],
            ['POST', '#^/security/accounts$#', 'securityAccounts'],
            ['GET', '#^/saml/new$#', 'samlNew'],
            ['POST', '#^/saml$#', 'samlCreate'],
            ['GET', '#^/saml/(\d+)/edit$#', 'samlEdit'],
            ['POST', '#^/saml/(\d+)$#', 'samlUpdate'],
            ['POST', '#^/saml/(\d+)/delete$#', 'samlDelete'],
            ['GET', '#^/security/consents$#', 'consentList'],
            ['GET', '#^/consents/new$#', 'consentNew'],
            ['POST', '#^/consents$#', 'consentCreate'],
            ['GET', '#^/consents/(\d+)/edit$#', 'consentEdit'],
            ['POST', '#^/consents/(\d+)$#', 'consentUpdate'],
            ['POST', '#^/consents/(\d+)/bump$#', 'consentBump'],
            ['POST', '#^/consents/(\d+)/delete$#', 'consentDelete'],
            ['POST', '#^/security/settings$#', 'securitySettings'],
            ['POST', '#^/security/reset$#', 'securityReset'],
            ['POST', '#^/security/mfa$#', 'securityMfa'],
            ['POST', '#^/security/social$#', 'securitySocial'],

            ['GET', '#^/search$#', 'search'],
            ['GET', '#^/rel/([a-z-]+)/([^/]+)/(candidates)$#', 'relation'],
            ['POST', '#^/rel/([a-z-]+)/([^/]+)/(add|remove|remove-all)$#', 'relation'],

            ['GET', '#^/users$#', 'userList'],
            ['GET', '#^/users/new$#', 'userNew'],
            ['POST', '#^/users$#', 'userCreate'],
            ['GET', '#^/users/([^/]+)/edit$#', 'userEdit'],
            ['GET', '#^/users/([^/]+)/groups$#', 'userGroups'],
            ['GET', '#^/users/([^/]+)/notices$#', 'userNotices'],
            ['GET', '#^/users/([^/]+)/danger$#', 'userDanger'],
            ['POST', '#^/users/([^/]+)/reset-lock$#', 'userResetLock'],
            ['POST', '#^/users/([^/]+)/lock$#', 'userLock'],
            ['POST', '#^/users/([^/]+)/unlock$#', 'userUnlock'],
            ['POST', '#^/users/([^/]+)/reset-mfa$#', 'userResetMfa'],
            ['POST', '#^/users/([^/]+)/send-reset$#', 'userSendReset'],
            ['POST', '#^/users/([^/]+)/consents/(\d+)/ask-again$#', 'userAskConsentAgain'],
            ['POST', '#^/users/([^/]+)/connections/([a-z0-9_-]+)/unlink$#', 'userUnlinkConnection'],
            ['POST', '#^/users/([^/]+)$#', 'userUpdate'],
            ['POST', '#^/users/([^/]+)/delete$#', 'userDelete'],

            ['GET', '#^/groups$#', 'groupList'],
            ['GET', '#^/groups/new$#', 'groupNew'],
            ['POST', '#^/groups$#', 'groupCreate'],
            ['GET', '#^/groups/(\d+)/edit$#', 'groupEdit'],
            ['GET', '#^/groups/(\d+)/members$#', 'groupMembers'],
            ['GET', '#^/groups/(\d+)/danger$#', 'groupDanger'],
            ['POST', '#^/groups/(\d+)$#', 'groupUpdate'],
            ['POST', '#^/groups/(\d+)/delete$#', 'groupDelete'],

            ['GET', '#^/roles$#', 'roleList'],
            ['GET', '#^/roles/new$#', 'roleNew'],
            ['POST', '#^/roles$#', 'roleCreate'],
            ['GET', '#^/roles/(\d+)/edit$#', 'roleEdit'],
            ['GET', '#^/roles/(\d+)/groups$#', 'roleGroups'],
            ['GET', '#^/roles/(\d+)/users$#', 'roleUsers'],
            ['GET', '#^/roles/(\d+)/danger$#', 'roleDanger'],
            ['POST', '#^/roles/(\d+)$#', 'roleUpdate'],
            ['POST', '#^/roles/(\d+)/delete$#', 'roleDelete'],

            ['GET', '#^/clients$#', 'clientList'],
            ['GET', '#^/clients/new$#', 'clientNew'],
            ['POST', '#^/clients$#', 'clientCreate'],
            ['GET', '#^/clients/([^/]+)/edit$#', 'clientEdit'],
            ['GET', '#^/clients/([^/]+)/access$#', 'clientAccessPage'],
            ['GET', '#^/clients/([^/]+)/danger$#', 'clientDanger'],
            ['GET', '#^/clients/([^/]+)/advanced$#', 'clientDanger'], // the tab's old name, for bookmarks
            ['GET', '#^/clients/([^/]+)/roles$#', 'clientRoles'],
            ['POST', '#^/clients/([^/]+)$#', 'clientUpdate'],
            ['POST', '#^/clients/([^/]+)/access$#', 'clientAccess'],
            ['POST', '#^/clients/([^/]+)/secret$#', 'clientRotateSecret'],
            ['POST', '#^/clients/([^/]+)/delete$#', 'clientDelete'],
        ];
    }

    // ---------------------------------------------------------------- dashboard

    private function dashboard(): ResponseInterface
    {
        $attempts = $this->attempts->recent(15);

        return $this->render('dashboard.html.twig', [
            'sessions' => $this->sessions->active(),
            'attempts' => $attempts,
            'failed_24h' => $this->attempts->failedSince(time() - 86400),
            'locked_now' => $this->throttle->lockedCount(),
            'current_sid' => $_SESSION['sid'] ?? '',
            'groups' => $this->groups->page('', 8, 0),
            'clients' => $this->clients->page('', 5, 0),
            'admins' => $this->users->admins(),
        ]);
    }

    private const AUDIT_PAGE_SIZE = 50;

    private function auditList(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $filter = [];
        foreach (['action', 'actor', 'target'] as $key) {
            $value = trim(is_string($query[$key] ?? null) ? $query[$key] : '');
            if ($value !== '') {
                $filter[$key] = mb_substr($value, 0, 100);
            }
        }
        $from = self::day($query['from'] ?? null, false);
        $to = self::day($query['to'] ?? null, true);
        $search = $filter + ($from !== null ? ['since' => $from] : []) + ($to !== null ? ['until' => $to] : []);

        $pager = new Pager($this->auditLog->count($search), self::AUDIT_PAGE_SIZE, $query['page'] ?? 1);
        $keep = array_filter($filter + ['from' => $from !== null ? gmdate('Y-m-d', $from) : '', 'to' => $to !== null ? gmdate('Y-m-d', $to) : '']);

        return $this->render('audit.html.twig', [
            'rows' => $this->auditLog->search($search, $pager->perPage, $pager->offset),
            'pager' => $pager,
            'filter' => $filter,
            'from' => $from !== null ? gmdate('Y-m-d', $from) : '',
            'to' => $to !== null ? gmdate('Y-m-d', $to) : '',
            'groups' => Audit::GROUPS,
            'base' => '/admin/audit?' . ($keep === [] ? '' : http_build_query($keep) . '&'),
        ]);
    }

    /** A date as typed in a date field (YYYY-MM-DD, taken as UTC): its first second, or its last. */
    private static function day(mixed $value, bool $endOfDay): ?int
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ($time = strtotime($value . ' 00:00:00 UTC')) === false) {
            return null;
        }

        return $endOfDay ? $time + 86399 : $time;
    }

    private function sessionEnd(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $this->sessions->end($id);

        return $this->done('/admin', $id === ($_SESSION['sid'] ?? '') ? 'You ended your own session.' : 'Session ended. That browser is signed out.');
    }

    // -------------------------------------------------------------------- users

    private const LIST_PAGE_SIZE = 25;

    /** The text typed in the search box of a list page: trimmed, and not endless. */
    private static function listText(ServerRequestInterface $request): string
    {
        $q = $request->getQueryParams()['q'] ?? '';

        return is_string($q) ? mb_substr(trim($q), 0, 100) : '';
    }

    /** The address of a list page up to its page number, with the search text kept. */
    private static function listBase(string $path, string $q): string
    {
        return $path . '?' . ($q !== '' ? 'q=' . rawurlencode($q) . '&' : '');
    }

    private function userList(ServerRequestInterface $request): ResponseInterface
    {
        $q = self::listText($request);
        $pager = new Pager($this->users->countMatching($q), self::LIST_PAGE_SIZE, $request->getQueryParams()['page'] ?? 1);

        return $this->render('users/list.html.twig', [
            'users' => $this->users->page($q, $pager->perPage, $pager->offset), 'pager' => $pager, 'q' => $q, 'base' => self::listBase('/admin/users', $q),
        ]);
    }

    private function userNew(): ResponseInterface
    {
        return $this->userForm(null, ['username' => '', 'name' => '', 'email' => '', 'email_verified' => false, 'is_admin' => false, 'must_change_password' => true]);
    }

    private function userEdit(ServerRequestInterface $request, string $sub): ResponseInterface
    {
        $user = $this->users->find($sub);
        if ($user === null) {
            return $this->notFound('User');
        }

        return $this->userForm($user, $user);
    }

    private function userGroups(ServerRequestInterface $request, string $sub): ResponseInterface
    {
        $user = $this->users->find($sub);
        if ($user === null) {
            return $this->notFound('User');
        }

        return $this->userForm($user, $user, [], 'groups');
    }

    private function userNotices(ServerRequestInterface $request, string $sub): ResponseInterface
    {
        $user = $this->users->find($sub);

        return $user === null ? $this->notFound('User') : $this->userForm($user, $user, [], 'notices');
    }

    private function userDanger(ServerRequestInterface $request, string $sub): ResponseInterface
    {
        $user = $this->users->find($sub);

        return $user === null ? $this->notFound('User') : $this->userForm($user, $user, [], 'danger');
    }

    private function userCreate(ServerRequestInterface $request): ResponseInterface
    {
        [$input, $errors] = $this->validateUser($request, null);
        if ($errors) {
            return $this->userForm(null, $input, $errors);
        }

        $sub = $this->users->create($input);

        return $this->done('/admin/users/' . rawurlencode($sub) . '/groups', "User {$input['username']} created. Add them to groups below.");
    }

    private function userUpdate(ServerRequestInterface $request, string $sub): ResponseInterface
    {
        $user = $this->users->find($sub);
        if ($user === null) {
            return $this->notFound('User');
        }

        [$input, $errors] = $this->validateUser($request, $user);
        if ($errors) {
            return $this->userForm($user, $input, $errors);
        }

        $this->users->update($sub, $input);
        if ($input['password'] !== '') {
            // A new password signs the person out everywhere (except the administrator's own current browser).
            $this->sessions->endOthers($sub, $sub === $this->admin['sub'] ? (string) ($_SESSION['sid'] ?? '') : '');
        }

        return $this->done('/admin/users', "User {$input['username']} updated.");
    }

    private function userDelete(ServerRequestInterface $request, string $sub): ResponseInterface
    {
        $user = $this->users->find($sub);
        if ($user === null) {
            return $this->notFound('User');
        }
        if ($sub === $this->admin['sub']) {
            return $this->done('/admin/users', 'You cannot delete your own account.', 'error');
        }
        if ($user['is_admin'] && $this->users->adminCount() <= 1) {
            return $this->done('/admin/users', 'You cannot delete the last administrator.', 'error');
        }

        $this->sessions->endAllForUser($sub); // revokes their tokens and notifies apps before the account disappears
        $this->users->delete($sub);

        return $this->done('/admin/users', "User {$user['username']} deleted.");
    }

    /** @return array{0: array, 1: array<string, string>} cleaned input, errors */
    private function validateUser(ServerRequestInterface $request, ?array $existing): array
    {
        $body = (array) $request->getParsedBody();
        $input = [
            'username' => $this->text($body, 'username'),
            'name' => $this->text($body, 'name'),
            'email' => $this->text($body, 'email'),
            'password' => (string) ($body['password'] ?? ''),
            'email_verified' => isset($body['email_verified']),
            'is_admin' => isset($body['is_admin']),
            'must_change_password' => isset($body['must_change_password']),
        ];
        $errors = [];

        if (!preg_match('/^[A-Za-z0-9_.@-]{2,64}$/', $input['username'])) {
            $errors['username'] = 'Use 2-64 letters, digits or _ . @ -';
        } elseif (($other = $this->users->findByUsername($input['username'])) && $other['sub'] !== ($existing['sub'] ?? null)) {
            $errors['username'] = 'This username is already taken.';
        } elseif ($input['username'] !== ($existing['username'] ?? null) && ($problem = $this->rules->usernameError($input['username'])) !== null) {
            $errors['username'] = $problem;
        }
        if ($input['name'] === '' || mb_strlen($input['name']) > 100) {
            $errors['name'] = 'Name is required (max 100 characters).';
        }
        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (($taken = $this->rules->emailError($input['email'], $existing['sub'] ?? null)) !== null) {
            $errors['email'] = $taken;
        }
        if ($existing === null && $input['password'] === '') {
            $errors['password'] = 'A password is required.';
        } elseif ($input['password'] !== '' && mb_strlen($input['password']) < 8) {
            $errors['password'] = 'Use at least 8 characters.';
        }
        if ($existing !== null && $existing['is_admin'] && !$input['is_admin']) {
            if ($existing['sub'] === $this->admin['sub']) {
                $errors['is_admin'] = 'You cannot remove your own administrator rights.';
            } elseif ($this->users->adminCount() <= 1) {
                $errors['is_admin'] = 'There must be at least one administrator.';
            }
        }

        return [$input, $errors];
    }

    private function userForm(?array $user, array $item, array $errors = [], string $tab = 'details'): ResponseInterface
    {
        return $this->render('users/form.html.twig', [
            'tab' => $tab,
            'impersonation_refusal' => $user ? $this->impersonation->refusal($this->admin, $user) : 'new',
            'lock' => $user && $tab === 'details' ? $this->throttle->accountStatus($user['sub']) : null,
            'limits' => $this->throttle->limits(),
            'username_hint' => $this->rules->usernameHint(),
            'connections' => $user && $tab === 'details' ? $this->social->connectionsOf($user['sub']) : [],
            'scim' => $user && $tab === 'details' ? $this->scimUsers->managedBy($user['sub']) : null,
            'consent_status' => $user && $tab === 'notices' ? $this->consents->statusFor($user['sub']) : [],
            'mfa' => $user && $tab === 'details' ? ['enrolled' => $this->mfa->isEnrolled($user['sub']), 'codes_left' => $this->mfa->recoveryCodesLeft($user['sub'])] : null,
            'reset_available' => $user !== null && $tab === 'details' && $this->passwordReset !== null,
            'reset_refusal' => $user !== null && $this->passwordReset !== null ? $this->passwordReset->refusal($user) : null,
            'user' => $user,
            'item' => $item,
            'errors' => $errors,
            'rel' => $user && $tab === 'groups' ? $this->picker->view('user-groups', $user['sub'], $_SESSION['csrf']) : null,
        ], $errors ? 422 : 200);
    }

    private function userAskConsentAgain(ServerRequestInterface $request, string $sub, string $id): ResponseInterface
    {
        $user = $this->users->find($sub);
        $consent = $this->consents->find((int) $id);
        if ($user === null || $consent === null) {
            return $this->notFound('User');
        }
        $this->consents->withdraw($sub, (int) $id);

        return $this->done('/admin/users/' . rawurlencode($sub) . '/notices', "{$user['name']} will be asked to accept \"{$consent['name']}\" again at their next visit.");
    }

    // --------------------------------------------------------------- consents

    private function consentList(): ResponseInterface
    {
        return $this->render('consents.html.twig', ['tab' => 'consents', 'consents' => $this->consents->all(), 'users_total' => $this->users->count()]);
    }

    private function consentNew(): ResponseInterface
    {
        return $this->consentForm(null, ['name' => '', 'message' => '', 'url' => '', 'enabled' => true]);
    }

    private function consentEdit(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $consent = $this->consents->find((int) $id);

        return $consent === null ? $this->notFound('Consent') : $this->consentForm($consent, $consent);
    }

    private function consentCreate(ServerRequestInterface $request): ResponseInterface
    {
        [$input, $errors] = $this->validateConsent($request);
        if ($errors) {
            return $this->consentForm(null, $input, $errors);
        }
        $this->consents->create($input['name'], $input['message'], $input['url'], $input['enabled']);

        return $this->done('/admin/security/consents', $input['enabled']
            ? "\"{$input['name']}\" added. Everyone is asked to accept it at their next visit."
            : "\"{$input['name']}\" added, switched off for now.");
    }

    private function consentUpdate(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $consent = $this->consents->find((int) $id);
        if ($consent === null) {
            return $this->notFound('Consent');
        }
        [$input, $errors] = $this->validateConsent($request);
        if ($errors) {
            return $this->consentForm($consent, $input, $errors);
        }
        $this->consents->update((int) $id, $input['name'], $input['message'], $input['url'], $input['enabled']);

        return $this->done('/admin/security/consents', "\"{$input['name']}\" saved. Nobody is asked again unless you choose that.");
    }

    private function consentBump(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $consent = $this->consents->find((int) $id);
        if ($consent === null) {
            return $this->notFound('Consent');
        }
        $version = $this->consents->bump((int) $id);

        return $this->done('/admin/security/consents', "Everyone is asked to accept \"{$consent['name']}\" again (version {$version}).");
    }

    private function consentDelete(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $consent = $this->consents->find((int) $id);
        if ($consent === null) {
            return $this->notFound('Consent');
        }
        if (!$this->consents->delete((int) $id)) {
            return $this->done('/admin/security/consents', "\"{$consent['name']}\" has been accepted by people, so it is kept as a record. Switch it off instead.", 'error');
        }

        return $this->done('/admin/security/consents', "\"{$consent['name']}\" deleted.");
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} cleaned input, errors */
    private function validateConsent(ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();
        $url = $this->text($body, 'url');
        $input = [
            'name' => $this->text($body, 'name'),
            'message' => $this->text($body, 'message'),
            'url' => $url === '' ? null : $url,
            'enabled' => isset($body['enabled']),
        ];
        $errors = [];
        if ($input['name'] === '' || mb_strlen($input['name']) > 100) {
            $errors['name'] = 'Name is required (max 100 characters).';
        }
        if (mb_strlen($input['message']) > 2000) {
            $errors['message'] = 'Keep the message under 2000 characters. The full text belongs behind the link.';
        }
        if ($input['url'] !== null && (mb_strlen($input['url']) > 500 || !filter_var($input['url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $input['url']))) {
            $errors['url'] = 'Enter a full web address starting with https:// (or http://).';
        }
        if (!isset($errors['message']) && !isset($errors['url']) && $input['message'] === '' && $input['url'] === null) {
            $errors['message'] = 'Give a message, a link, or both. Otherwise the person sees nothing to accept.';
        }

        return [$input, $errors];
    }

    private function consentForm(?array $consent, array $item, array $errors = []): ResponseInterface
    {
        return $this->render('consents/form.html.twig', ['tab' => 'consents', 'consent' => $consent, 'item' => $item, 'errors' => $errors], $errors ? 422 : 200);
    }

    // ------------------------------------------------------------------- groups

    private function groupList(ServerRequestInterface $request): ResponseInterface
    {
        $q = self::listText($request);
        $pager = new Pager($this->groups->countMatching($q), self::LIST_PAGE_SIZE, $request->getQueryParams()['page'] ?? 1);

        return $this->render('groups/list.html.twig', [
            'groups' => $this->groups->page($q, $pager->perPage, $pager->offset), 'pager' => $pager, 'q' => $q, 'base' => self::listBase('/admin/groups', $q),
        ]);
    }

    private function groupNew(): ResponseInterface
    {
        return $this->groupForm(null, ['name' => '', 'description' => '']);
    }

    private function groupEdit(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $group = $this->groups->find((int) $id);

        return $group === null ? $this->notFound('Group') : $this->groupForm($group, $group);
    }

    private function groupDanger(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $group = $this->groups->find((int) $id);

        return $group === null ? $this->notFound('Group') : $this->groupForm($group, $group, [], 'danger');
    }

    private function groupMembers(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $group = $this->groups->find((int) $id);

        return $group === null ? $this->notFound('Group') : $this->groupForm($group, $group, [], 'members');
    }

    private function groupCreate(ServerRequestInterface $request): ResponseInterface
    {
        [$input, $errors] = $this->validateGroup($request, null);
        if ($errors) {
            return $this->groupForm(null, $input, $errors);
        }
        $id = $this->groups->create($input['name'], $input['description']);

        return $this->done('/admin/groups/' . $id . '/members', "Group {$input['name']} created. Add its members below.");
    }

    private function groupUpdate(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $group = $this->groups->find((int) $id);
        if ($group === null) {
            return $this->notFound('Group');
        }

        [$input, $errors] = $this->validateGroup($request, $group);
        if ($errors) {
            return $this->groupForm($group, $input, $errors);
        }
        $this->groups->update($group['id'], $input['name'], $input['description']);

        return $this->done('/admin/groups', "Group {$input['name']} updated.");
    }

    private function groupDelete(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $group = $this->groups->find((int) $id);
        if ($group === null) {
            return $this->notFound('Group');
        }
        $this->groups->delete($group['id']);

        return $this->done('/admin/groups', "Group {$group['name']} deleted.");
    }

    /** Actions of the relation picker (search, add, remove, remove all); see RelationPicker. */
    private function relation(ServerRequestInterface $request, string $name, string $owner, string $action): ResponseInterface
    {
        return $this->picker->handle($request, $name, $owner, $action);
    }

    private function validateGroup(ServerRequestInterface $request, ?array $existing): array
    {
        $body = (array) $request->getParsedBody();
        $input = ['name' => $this->text($body, 'name'), 'description' => $this->text($body, 'description')];
        $errors = [];

        // The name ends up as a value of the `groups` claim, so keep it simple and predictable.
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $input['name'])) {
            $errors['name'] = 'Use 1-64 letters, digits or _ . : -';
        } elseif (($other = $this->groups->findByName($input['name'])) && $other['id'] !== ($existing['id'] ?? null)) {
            $errors['name'] = 'A group with this name already exists.';
        }
        if (mb_strlen($input['description']) > 255) {
            $errors['description'] = 'Max 255 characters.';
        }

        return [$input, $errors];
    }

    private function groupForm(?array $group, array $item, array $errors = [], string $tab = 'details'): ResponseInterface
    {
        $rel = $group ? $this->picker->view('group-users', $group['id'], $_SESSION['csrf']) : null;

        return $this->render('groups/form.html.twig', [
            'tab' => $tab,
            'group' => $group,
            'item' => $item,
            'errors' => $errors,
            'rel' => $rel,
            'scim' => $group ? $this->scimGroups->managedBy((int) $group['id']) : null,
            'member_total' => $rel['total'] ?? 0,
        ], $errors ? 422 : 200);
    }

    // ------------------------------------------------------------------ clients

    private function clientList(ServerRequestInterface $request): ResponseInterface
    {
        $q = self::listText($request);
        $pager = new Pager($this->clients->countMatching($q), self::LIST_PAGE_SIZE, $request->getQueryParams()['page'] ?? 1);
        $clients = $this->clients->page($q, $pager->perPage, $pager->offset);

        return $this->render('clients/list.html.twig', [
            'clients' => $clients, 'access' => $this->clients->accessSummary(array_map(static fn (array $c): string => (string) $c['client_id'], $clients)),
            'pager' => $pager, 'q' => $q, 'base' => self::listBase('/admin/clients', $q),
        ]);
    }

    private function clientNew(): ResponseInterface
    {
        return $this->clientForm(null, [
            'client_id' => '', 'name' => '', 'confidential' => true, 'redirect_uris_text' => '', 'grants' => self::DEFAULT_GRANTS, 'scopes' => $this->scopes->builtinNames(), 'restricted' => false, 'backchannel_logout_uri' => null, 'post_logout_redirect_uris_text' => '',
        ]);
    }

    private function clientEdit(ServerRequestInterface $request, string $clientId): ResponseInterface
    {
        $client = $this->clients->find($clientId);
        if ($client === null) {
            return $this->notFound('Client');
        }

        $client['scopes'] ??= $this->scopes->builtinNames();

        return $this->clientForm($client, $client + ['redirect_uris_text' => implode("\n", $client['redirect_uris']), 'post_logout_redirect_uris_text' => implode("\n", $client['post_logout_redirect_uris'])]);
    }

    private function clientAccessPage(ServerRequestInterface $request, string $clientId): ResponseInterface
    {
        return $this->clientTab($clientId, 'access');
    }

    private function clientDanger(ServerRequestInterface $request, string $clientId): ResponseInterface
    {
        return $this->clientTab($clientId, 'danger');
    }

    private function clientRoles(ServerRequestInterface $request, string $clientId): ResponseInterface
    {
        return $this->clientTab($clientId, 'roles');
    }

    private function clientTab(string $clientId, string $tab): ResponseInterface
    {
        $client = $this->clients->find($clientId);
        if ($client === null) {
            return $this->notFound('Client');
        }

        return $this->clientForm($client, $client, [], $tab);
    }

    private function clientCreate(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        [$input, $errors] = $this->validateClient($request, null);

        $input['client_id'] = $this->text($body, 'client_id');
        $input['confidential'] = ($body['type'] ?? 'confidential') !== 'public';
        $input['restricted'] = false;

        if ($error = $this->clientIdError($input['client_id'], null)) {
            $errors['client_id'] = $error;
        }
        if ($errors) {
            return $this->clientForm(null, $input, $errors);
        }

        $secret = $input['confidential'] ? bin2hex(random_bytes(24)) : null;
        $this->clients->insert([
            'client_id' => $input['client_id'],
            'name' => $input['name'],
            'secret_hash' => $secret === null ? null : password_hash($secret, PASSWORD_DEFAULT),
            'confidential' => $input['confidential'],
            'redirect_uris' => $input['redirect_uris'],
            'grants' => $input['grants'],
            'backchannel_logout_uri' => $input['backchannel_logout_uri'],
            'post_logout_redirect_uris' => $input['post_logout_redirect_uris'],
            'restricted' => $input['restricted'],
            'scopes' => $input['scopes'],
        ]);

        return $this->done('/admin/clients/' . rawurlencode($input['client_id']) . '/edit', "Client {$input['name']} created. Add its redirect URIs under Sign-in before apps can use it.", secret: $secret, clientId: $input['client_id']);
    }

    private function clientUpdate(ServerRequestInterface $request, string $clientId): ResponseInterface
    {
        $client = $this->clients->find($clientId);
        if ($client === null) {
            return $this->notFound('Client');
        }

        $body = (array) $request->getParsedBody();
        [$input, $errors] = $this->validateClient($request, $client);

        $input['client_id'] = $this->text($body, 'client_id');
        $input['confidential'] = match ($body['type'] ?? null) {
            'public' => false,
            'confidential' => true,
            default => $client['confidential'],
        };
        if ($error = $this->clientIdError($input['client_id'], $clientId)) {
            $errors['client_id'] = $error;
        }
        if ($errors) {
            return $this->clientForm($client, $input, $errors);
        }

        $this->clients->update($clientId, $input['name'], $input['redirect_uris'], $input['grants'], $input['backchannel_logout_uri'], $input['post_logout_redirect_uris'], $input['scopes']);

        // A client that becomes confidential needs a secret; it is shown once, like when it is created.
        $secret = $input['confidential'] && !$client['confidential'] ? bin2hex(random_bytes(24)) : null;
        $secretHash = $secret !== null ? password_hash($secret, PASSWORD_DEFAULT) : $client['secret_hash'];
        if ($input['client_id'] !== $clientId || $input['confidential'] !== $client['confidential']) {
            $this->clients->change($clientId, $input['client_id'], $input['confidential'], $secretHash);
        }

        if ($secret !== null) {
            return $this->done('/admin/clients/' . rawurlencode($input['client_id']) . '/edit', "Client {$input['name']} updated. It is now confidential.", secret: $secret, clientId: $input['client_id']);
        }

        return $this->done('/admin/clients', "Client {$input['name']} updated.");
    }

    /** Why this ID cannot be used, or null. `$current` is the ID the client has now, when it is being edited. */
    private function clientIdError(string $clientId, ?string $current): ?string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $clientId)) {
            return 'Use 2-64 lowercase letters, digits, - or _.';
        }
        if ($clientId !== $current && $this->clients->find($clientId) !== null) {
            return 'This client ID already exists.';
        }

        return null;
    }

    /** Switches a client between "everyone" and "only the groups and people linked to it". */
    private function clientAccess(ServerRequestInterface $request, string $clientId): ResponseInterface
    {
        $client = $this->clients->find($clientId);
        if ($client === null) {
            return $this->notFound('Client');
        }
        $restricted = ((array) $request->getParsedBody())['access'] ?? '' ;
        $restricted = $restricted === 'restricted';
        $this->clients->setRestricted($clientId, $restricted);

        return $this->done('/admin/clients/' . rawurlencode($clientId) . '/access', $restricted
            ? 'Only the groups and people listed below can sign in now.'
            : 'Everyone with an account can sign in to this app now.');
    }

    private function clientRotateSecret(ServerRequestInterface $request, string $clientId): ResponseInterface
    {
        $client = $this->clients->find($clientId);
        if ($client === null) {
            return $this->notFound('Client');
        }
        if (!$client['confidential']) {
            return $this->done('/admin/clients', 'Public clients have no secret.', 'error');
        }

        $secret = bin2hex(random_bytes(24));
        $this->clients->updateSecretHash($clientId, password_hash($secret, PASSWORD_DEFAULT));

        return $this->done('/admin/clients/' . rawurlencode($clientId) . '/danger', 'New secret generated. The old one no longer works.', secret: $secret, clientId: $clientId);
    }

    private function clientDelete(ServerRequestInterface $request, string $clientId): ResponseInterface
    {
        $client = $this->clients->find($clientId);
        if ($client === null) {
            return $this->notFound('Client');
        }
        $this->clients->delete($clientId);

        return $this->done('/admin/clients', "Client {$client['name']} deleted.");
    }

    // -------------------------------------------------------------------- roles

    private function roleList(): ResponseInterface
    {
        return $this->render('roles/list.html.twig', ['roles' => $this->roles->all()]);
    }

    private function roleNew(): ResponseInterface
    {
        return $this->roleForm(null, ['name' => '', 'description' => '', 'all_clients' => true, 'client_ids' => []]);
    }

    private function roleEdit(ServerRequestInterface $request, string $id): ResponseInterface
    {
        return $this->roleTab($id, 'details');
    }

    private function roleGroups(ServerRequestInterface $request, string $id): ResponseInterface
    {
        return $this->roleTab($id, 'groups');
    }

    private function roleUsers(ServerRequestInterface $request, string $id): ResponseInterface
    {
        return $this->roleTab($id, 'users');
    }

    private function roleDanger(ServerRequestInterface $request, string $id): ResponseInterface
    {
        return $this->roleTab($id, 'danger');
    }

    private function roleTab(string $id, string $tab): ResponseInterface
    {
        $role = $this->roles->find((int) $id);

        return $role === null ? $this->notFound('Role') : $this->roleForm($role, $role + ['client_ids' => $this->roles->clientIds($role['id'])], [], $tab);
    }

    private function roleCreate(ServerRequestInterface $request): ResponseInterface
    {
        [$input, $errors] = $this->validateRole($request, null);
        if ($errors) {
            return $this->roleForm(null, $input, $errors);
        }
        $id = $this->roles->create($input['name'], $input['description'], $input['all_clients'], $input['client_ids']);

        return $this->done('/admin/roles/' . $id . '/groups', "Role {$input['name']} created. Give it to groups and users below.");
    }

    private function roleUpdate(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $role = $this->roles->find((int) $id);
        if ($role === null) {
            return $this->notFound('Role');
        }

        [$input, $errors] = $this->validateRole($request, $role);
        if ($errors) {
            return $this->roleForm($role, $input, $errors);
        }
        $this->roles->update($role['id'], $input['name'], $input['description']);
        $this->roles->setClients($role['id'], $input['all_clients'], $input['client_ids']);

        return $this->done('/admin/roles', "Role {$input['name']} updated.");
    }

    private function roleDelete(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $role = $this->roles->find((int) $id);
        if ($role === null) {
            return $this->notFound('Role');
        }
        $this->roles->delete($role['id']);

        return $this->done('/admin/roles', "Role {$role['name']} deleted.");
    }

    /** @return array{array{name: string, description: string, all_clients: bool, client_ids: list<string>}, array<string, string>} */
    private function validateRole(ServerRequestInterface $request, ?array $existing): array
    {
        $body = (array) $request->getParsedBody();
        $known = array_map(static fn (array $c): string => $c['client_id'], $this->clients->all());
        $input = [
            'name' => $this->text($body, 'name'),
            'description' => $this->text($body, 'description'),
            'all_clients' => ($body['availability'] ?? 'all') !== 'selected',
            'client_ids' => array_values(array_intersect($known, array_map('strval', (array) ($body['clients'] ?? [])))),
        ];
        $errors = [];

        // The name ends up as a value of the `roles` claim, so keep it simple and predictable.
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $input['name'])) {
            $errors['name'] = 'Use 1-64 letters, digits or _ . : -';
        } elseif (($other = $this->roles->findByName($input['name'])) && $other['id'] !== ($existing['id'] ?? null)) {
            $errors['name'] = 'A role with this name already exists.';
        }
        if (mb_strlen($input['description']) > 255) {
            $errors['description'] = 'Max 255 characters.';
        }
        if (!$input['all_clients'] && $input['client_ids'] === []) {
            $errors['clients'] = 'Choose at least one client, or make the role available in every client.';
        }

        return [$input, $errors];
    }

    private function roleForm(?array $role, array $item, array $errors = [], string $tab = 'details'): ResponseInterface
    {
        return $this->render('roles/form.html.twig', [
            'tab' => $tab,
            'role' => $role,
            'item' => $item,
            'errors' => $errors,
            'all_clients' => $this->clients->all(),
            'rel_groups' => $role && $tab === 'groups' ? $this->picker->view('role-groups', $role['id'], $_SESSION['csrf']) : null,
            'rel_users' => $role && $tab === 'users' ? $this->picker->view('role-users', $role['id'], $_SESSION['csrf']) : null,
        ], $errors ? 422 : 200);
    }

    /** Validates the fields shared by create and update. */
    private function validateClient(ServerRequestInterface $request, ?array $existing): array
    {
        $body = (array) $request->getParsedBody();
        $text = (string) ($body['redirect_uris'] ?? '');
        $uris = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []))));
        $logoutText = (string) ($body['post_logout_redirect_uris'] ?? '');
        $logoutUris = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $logoutText) ?: []))));
        $backchannel = $this->text($body, 'backchannel_logout_uri');
        $input = [
            'name' => $this->text($body, 'name'),
            'redirect_uris' => $uris,
            'redirect_uris_text' => $text,
            'post_logout_redirect_uris' => $logoutUris,
            'post_logout_redirect_uris_text' => $logoutText,
            'backchannel_logout_uri' => $backchannel === '' ? null : $backchannel,
            'grants' => $existing === null ? self::DEFAULT_GRANTS : array_values(array_intersect(self::GRANTS, (array) ($body['grants'] ?? []))),
            'scopes' => $existing === null ? $this->scopes->builtinNames() : array_values(array_intersect($this->scopes->names(), (array) ($body['scopes'] ?? []))),
        ];
        $errors = [];

        if ($input['name'] === '' || mb_strlen($input['name']) > 100) {
            $errors['name'] = 'Name is required (max 100 characters).';
        }
        // A new client only needs an identity; where it signs people in is filled in on its Settings tab.
        if ($uris === [] && $existing !== null && in_array('authorization_code', $input['grants'], true)) {
            $errors['redirect_uris'] = 'Enter at least one redirect URI.';
        }
        foreach ($uris as $uri) {
            $parts = parse_url($uri);
            if (!filter_var($uri, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['fragment'])) {
                $errors['redirect_uris'] = "Not a valid http(s) URL without fragment: {$uri}";
                break;
            }
        }
        foreach ($logoutUris as $uri) {
            if (!$this->isHttpUrl($uri)) {
                $errors['post_logout_redirect_uris'] = "Not a valid http(s) URL without fragment: {$uri}";
                break;
            }
        }
        if ($input['backchannel_logout_uri'] !== null && !$this->isHttpUrl($input['backchannel_logout_uri'])) {
            $errors['backchannel_logout_uri'] = 'Enter a valid http(s) URL.';
        }
        if ($input['grants'] === []) {
            $errors['grants'] = 'Enable at least one grant, otherwise the client can do nothing.';
        } elseif (in_array('client_credentials', $input['grants'], true) && $existing !== null && !$this->willBeConfidential($body, $existing)) {
            $errors['grants'] = 'The client credentials grant needs a confidential client: only a client that keeps a secret can use it.';
        }
        if ($input['scopes'] === []) {
            $errors['scopes'] = 'Select at least one scope.';
        }

        return [$input, $errors];
    }

    private function isHttpUrl(string $uri): bool
    {
        $parts = parse_url($uri);

        return filter_var($uri, FILTER_VALIDATE_URL) !== false && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && !isset($parts['fragment']);
    }

    /** @param array<string, mixed> $body */
    private function willBeConfidential(array $body, array $existing): bool
    {
        return match ($body['type'] ?? null) {
            'public' => false,
            'confidential' => true,
            default => (bool) $existing['confidential'],
        };
    }

    private function clientForm(?array $client, array $item, array $errors = [], string $tab = 'settings'): ResponseInterface
    {
        $new = $_SESSION['new_secret'] ?? null;
        unset($_SESSION['new_secret']);

        return $this->render('clients/form.html.twig', [
            'tab' => $tab,
            'client' => $client,
            'item' => $item,
            'errors' => $errors,
            'roles' => $client && $tab === 'roles' ? $this->roles->forClient($client['client_id']) : [],
            'grants' => self::GRANTS,
            'scope_names' => $this->scopes->names(), 'scope_info' => $this->scopeInfo(), 'scope_builtin' => $this->scopes->builtinNames(),
            'rel_groups' => $client && $tab === 'access' ? $this->picker->view('client-groups', $client['client_id'], $_SESSION['csrf']) : null,
            'rel_users' => $client && $tab === 'access' ? $this->picker->view('client-users', $client['client_id'], $_SESSION['csrf']) : null,
            // Shown exactly once, right after it was generated.
            'new_secret' => $client && $new && $new['client_id'] === $client['client_id'] ? $new['secret'] : null,
        ], $errors ? 422 : 200);
    }

    // ----------------------------------------------------------------- IP rules

    // ------------------------------------------------------- sign-in protection

    private function securityPage(): ResponseInterface
    {
        return $this->securityView('activity', $this->throttle->form(), []);
    }

    private function securityLimitsPage(): ResponseInterface
    {
        return $this->securityView('limits', $this->throttle->form(), []);
    }

    private function securityMfaPage(): ResponseInterface
    {
        return $this->securityView('mfa', $this->throttle->form(), []);
    }

    private function securitySocialPage(): ResponseInterface
    {
        return $this->securityView('social', $this->throttle->form(), []);
    }

    /**
     * @param string $tab activity, limits, mfa or social
     * @param array<string, mixed> $form the settings as the form shows them
     * @param array<string, string> $errors by field
     * @param array<string, mixed>|null $socialForm the social settings as typed, when they have to be shown again
     */
    private function securityView(string $tab, array $form, array $errors, int $status = 200, ?array $socialForm = null): ResponseInterface
    {
        $rows = array_map(
            fn (array $row): array => $row + ['person' => $row['sub'] === null ? null : $this->users->find($row['sub'])],
            $this->throttle->failing(),
        );

        return $this->render('security.html.twig', [
            'tab' => $tab,
            'form' => $form,
            'errors' => $errors,
            'rows' => $rows,
            'refused' => $this->attempts->recentThrottled(15),
            'limits' => $this->throttle->limits(),
            'mfa_policy' => $this->mfa->policy(),
            'social_providers' => $this->social->providerStatus(),
            'social_form' => $socialForm ?? $this->social->policy()->form(),
            'admin_enrolled' => $this->mfa->isEnrolled($this->admin['sub']),
        ], $errors ? 422 : $status);
    }

    private function securitySettings(ServerRequestInterface $request): ResponseInterface
    {
        [$form, $errors] = LoginThrottle::parseForm((array) $request->getParsedBody());
        if ($errors !== []) {
            return $this->securityView('limits', $form, $errors);
        }
        $this->throttle->save($form);

        return $this->done('/admin/security/limits', $form['enabled'] ? 'Sign-in protection saved.' : 'Sign-in protection is now off.');
    }

    /** Lets an account, an identifier or an address try again: their failed attempts stop counting (they stay in the history). */
    private function securityReset(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $value = (string) ($body['value'] ?? '');

        switch ($body['type'] ?? '') {
            case 'account':
                $user = $this->users->find($value);
                if ($user === null) {
                    return $this->done('/admin/security', 'That user no longer exists.', 'error');
                }
                $this->throttle->resetAccount($user['sub'], $user['username'], $user['email']);
                $who = $user['name'];
                break;
            case 'identifier':
                if ($value === '') {
                    return $this->done('/admin/security', 'Nothing to reset.', 'error');
                }
                $this->throttle->resetIdentifier($value);
                $who = $value;
                break;
            case 'ip':
                if (filter_var($value, FILTER_VALIDATE_IP) === false) {
                    return $this->done('/admin/security', 'That is not an IP address.', 'error');
                }
                $this->throttle->resetIp($value);
                $who = $value;
                break;
            default:
                return $this->done('/admin/security', 'Nothing to reset.', 'error');
        }

        return $this->done('/admin/security', "Failed attempts for {$who} no longer count.");
    }

    private function securityMfa(ServerRequestInterface $request): ResponseInterface
    {
        $policy = (string) (((array) $request->getParsedBody())['policy'] ?? '');
        if (!$this->mfa->savePolicy($policy)) {
            return $this->done('/admin/security/mfa', 'Choose one of the options.', 'error');
        }

        return $this->done('/admin/security/mfa', match ($policy) {
            'everyone' => 'Everyone now has to set up two-step verification at their next sign-in.',
            'admins' => 'Administrators now have to set up two-step verification at their next sign-in.',
            default => 'Two-step verification is now optional. People who set it up are still asked for a code.',
        });
    }

    /** For someone who lost their phone: removes the app and the recovery codes, and signs them out everywhere. */
    /** Saves the two social switches. The allowed domains live on the Accounts tab and are kept as they are. */
    private function securitySocial(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $this->social->policy()->save([
            'link_by_verified_email' => isset($body['link_by_verified_email']),
            'create_users' => isset($body['create_users']),
            'allowed_domains' => $this->social->policy()->form()['allowed_domains'],
        ]);

        return $this->done('/admin/security/social', 'Social sign-in settings saved.');
    }

    private function securityAccountsPage(): ResponseInterface
    {
        return $this->securityView('accounts', $this->throttle->form(), []);
    }

    /** Saves the allowed email domains for accounts made or connected automatically. The social switches are kept as they are. */
    private function securityAccounts(ServerRequestInterface $request): ResponseInterface
    {
        [$form, $errors] = AccountPolicy::parseForm(['allowed_domains' => (string) (((array) $request->getParsedBody())['allowed_domains'] ?? '')]);
        if ($errors !== []) {
            return $this->securityView('accounts', $this->throttle->form(), $errors, 422, $form);
        }
        $current = $this->social->policy()->form();
        $this->social->policy()->save(['link_by_verified_email' => $current['link_by_verified_email'], 'create_users' => $current['create_users'], 'allowed_domains' => $form['allowed_domains']]);

        return $this->done('/admin/security/accounts', 'Allowed email domains saved.');
    }

    // --------------------------------------------------------------------- scim

    private function scimList(): ResponseInterface
    {
        return $this->render('scim.html.twig', ['tab' => 'scim', 'clients' => $this->scimClients->all(), 'url' => rtrim($this->publicUrl, '/') . '/scim/v2']);
    }

    private function scimNew(): ResponseInterface
    {
        return $this->scimForm(null, ['name' => '', 'domains' => '', 'enabled' => true, 'on_delete' => 'deactivate', 'adopt_existing' => false, 'group_prefix' => '', 'adopt_groups' => false]);
    }

    private function scimEdit(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $client = $this->scimClients->find((int) $id);

        return $client === null ? $this->notFound('Client') : $this->scimForm($client, [
            'name' => $client['name'], 'domains' => implode("\n", $client['domains']), 'enabled' => (bool) $client['enabled'],
            'on_delete' => $client['on_delete'], 'adopt_existing' => (bool) $client['adopt_existing'],
            'group_prefix' => $client['group_prefix'], 'adopt_groups' => (bool) $client['adopt_groups'],
        ]);
    }

    private function scimCreate(ServerRequestInterface $request): ResponseInterface
    {
        [$input, $domains, $errors] = $this->validateScim($request);
        if ($errors) {
            return $this->scimForm(null, $input, $errors);
        }
        $created = $this->scimClients->create($input['name'], $domains, $input['enabled'], $input['on_delete'], $input['adopt_existing'], $input['group_prefix'], $input['adopt_groups']);
        $_SESSION['scim_token'] = ['id' => $created['id'], 'token' => $created['token']];

        return $this->done('/admin/scim/' . $created['id'] . '/edit', "{$input['name']} added. Copy its token now: it is shown once.");
    }

    private function scimUpdate(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $client = $this->scimClients->find((int) $id);
        if ($client === null) {
            return $this->notFound('Client');
        }
        [$input, $domains, $errors] = $this->validateScim($request);
        if ($errors) {
            return $this->scimForm($client, $input, $errors);
        }
        $this->scimClients->update((int) $id, $input['name'], $domains, $input['enabled'], $input['on_delete'], $input['adopt_existing'], $input['group_prefix'], $input['adopt_groups']);

        return $this->done('/admin/security/scim', "{$input['name']} saved.");
    }

    private function scimRotate(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $client = $this->scimClients->find((int) $id);
        if ($client === null) {
            return $this->notFound('Client');
        }
        $_SESSION['scim_token'] = ['id' => (int) $id, 'token' => $this->scimClients->rotate((int) $id)];

        return $this->done('/admin/scim/' . (int) $id . '/edit', "{$client['name']} has a new token. The old one no longer works. Copy the new one now: it is shown once.");
    }

    private function scimDelete(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $client = $this->scimClients->find((int) $id);
        if ($client === null) {
            return $this->notFound('Client');
        }
        $this->scimClients->delete((int) $id);

        return $this->done('/admin/security/scim', "{$client['name']} deleted. The people it created stay, and nobody manages them any more.");
    }

    /** @return array{0: array<string, mixed>, 1: list<string>, 2: array<string, string>} */
    private function validateScim(ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();
        $input = [
            'name' => $this->text($body, 'name'),
            'domains' => (string) ($body['domains'] ?? ''),
            'enabled' => isset($body['enabled']),
            'on_delete' => in_array($body['on_delete'] ?? '', ScimClients::ON_DELETE, true) ? (string) $body['on_delete'] : 'deactivate',
            'adopt_existing' => isset($body['adopt_existing']),
            'group_prefix' => $this->text($body, 'group_prefix'),
            'adopt_groups' => isset($body['adopt_groups']),
        ];
        $errors = [];
        if ($input['name'] === '' || mb_strlen($input['name']) > 100) {
            $errors['name'] = 'Name is required (max 100 characters).';
        }
        if (preg_match('/^[A-Za-z0-9_.:-]{0,30}$/', $input['group_prefix']) !== 1) {
            $errors['group_prefix'] = 'Use up to 30 letters, digits or _ . : - (for example acme:).';
        }
        $domains = AccountPolicy::domainsFrom($input['domains']);
        foreach ($domains as $domain) {
            if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain) !== 1) {
                $errors['domains'] = "\"{$domain}\" is not a domain name. Use one per line, such as acme.com.";
                break;
            }
        }
        if ($domains === [] && !isset($errors['domains'])) {
            $errors['domains'] = 'Give at least one email domain, such as acme.com. This token can only manage people at these.';
        }

        return [$input, $domains, $errors];
    }

    private function scimForm(?array $client, array $item, array $errors = []): ResponseInterface
    {
        $token = $_SESSION['scim_token'] ?? null;
        $showToken = $client !== null && is_array($token) && (int) ($token['id'] ?? 0) === (int) $client['id'] ? (string) $token['token'] : null;
        if ($showToken !== null) {
            unset($_SESSION['scim_token']); // shown once
        }

        return $this->render('scim/form.html.twig', [
            'tab' => 'scim', 'client' => $client, 'item' => $item, 'errors' => $errors,
            'url' => rtrim($this->publicUrl, '/') . '/scim/v2',
            'new_token' => $showToken,
            'events' => $client === null ? [] : $this->scimEvents->recent((int) $client['id']),
        ], $errors ? 422 : 200);
    }

    // --------------------------------------------------------------------- saml

    private function samlList(): ResponseInterface
    {
        $connections = array_map(fn (array $c): array => $c + ['people' => $this->samlConnections->peopleCount((int) $c['id'])], $this->samlConnections->all());

        return $this->render('saml.html.twig', ['tab' => 'saml', 'connections' => $connections]);
    }

    private function samlNew(): ResponseInterface
    {
        return $this->samlForm(null, ['name' => '', 'idp_entity_id' => '', 'idp_sso_url' => '', 'idp_certificate' => '', 'domains' => '', 'enabled' => true, 'create_users' => false, 'link_existing' => false]);
    }

    private function samlEdit(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $connection = $this->samlConnections->find((int) $id);

        return $connection === null ? $this->notFound('Connection') : $this->samlForm($connection, [
            'name' => $connection['name'], 'idp_entity_id' => $connection['idp_entity_id'], 'idp_sso_url' => $connection['idp_sso_url'],
            'idp_certificate' => $connection['idp_certificate'], 'domains' => implode("\n", $connection['domains']),
            'enabled' => (bool) $connection['enabled'], 'create_users' => (bool) $connection['create_users'], 'link_existing' => (bool) $connection['link_existing'],
        ]);
    }

    private function samlCreate(ServerRequestInterface $request): ResponseInterface
    {
        [$input, $domains, $errors] = $this->validateSaml($request, null);
        if ($errors) {
            return $this->samlForm(null, $input, $errors);
        }
        $id = $this->samlConnections->create($input, $domains);

        return $this->done('/admin/saml/' . $id . '/edit', "{$input['name']} added. Give the details below to their administrator.");
    }

    private function samlUpdate(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $connection = $this->samlConnections->find((int) $id);
        if ($connection === null) {
            return $this->notFound('Connection');
        }
        [$input, $domains, $errors] = $this->validateSaml($request, (int) $id);
        if ($errors) {
            return $this->samlForm($connection, $input, $errors);
        }
        $this->samlConnections->update((int) $id, $input, $domains);

        return $this->done('/admin/security/saml', "{$input['name']} saved.");
    }

    private function samlDelete(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $connection = $this->samlConnections->find((int) $id);
        if ($connection === null) {
            return $this->notFound('Connection');
        }
        $this->samlConnections->delete((int) $id);

        return $this->done('/admin/security/saml', "{$connection['name']} deleted. Its people keep their accounts but can no longer sign in through it.");
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>, 2: array<string, string>} the input as typed (domains as text), the domains, errors
     */
    private function validateSaml(ServerRequestInterface $request, ?int $id): array
    {
        $body = (array) $request->getParsedBody();
        $domainsText = (string) ($body['domains'] ?? '');
        $input = [
            'name' => $this->text($body, 'name'),
            'idp_entity_id' => $this->text($body, 'idp_entity_id'),
            'idp_sso_url' => $this->text($body, 'idp_sso_url'),
            'idp_certificate' => trim((string) ($body['idp_certificate'] ?? '')),
            'domains' => $domainsText,
            'enabled' => isset($body['enabled']),
            'create_users' => isset($body['create_users']),
            'link_existing' => isset($body['link_existing']),
        ];
        $errors = [];
        if ($input['name'] === '' || mb_strlen($input['name']) > 100) {
            $errors['name'] = 'Name is required (max 100 characters).';
        }
        if ($input['idp_entity_id'] === '' || mb_strlen($input['idp_entity_id']) > 500) {
            $errors['idp_entity_id'] = 'Enter the entity ID (issuer) of their identity provider.';
        }
        if (!filter_var($input['idp_sso_url'], FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $input['idp_sso_url']) || mb_strlen($input['idp_sso_url']) > 1000) {
            $errors['idp_sso_url'] = 'Enter the sign-in (SSO) address of their identity provider, starting with https://.';
        }
        $certificate = self::normalizeCertificate($input['idp_certificate']);
        if ($certificate === null) {
            $errors['idp_certificate'] = 'That is not a certificate. Paste the X.509 signing certificate of their identity provider (PEM, with or without the BEGIN and END lines).';
        } else {
            $input['idp_certificate'] = $certificate;
        }

        $domains = AccountPolicy::domainsFrom($domainsText);
        foreach ($domains as $domain) {
            if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain) !== 1) {
                $errors['domains'] = "\"{$domain}\" is not a domain name. Use one per line, such as acme.com.";
                break;
            }
            $owner = $this->samlConnections->domainOwner($domain);
            if ($owner !== null && $owner !== $id) {
                $errors['domains'] = "{$domain} already belongs to " . ($this->samlConnections->find($owner)['name'] ?? 'another connection') . '. A domain can have one identity provider.';
                break;
            }
        }
        if ($domains === [] && !isset($errors['domains'])) {
            $errors['domains'] = 'Give at least one email domain, such as acme.com. People at it sign in through this provider.';
        }

        return [$input, $domains, $errors];
    }

    /** The certificate as a PEM block, or null when it is not one. */
    private static function normalizeCertificate(string $text): ?string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $text) ?? '';
        if ($body === '' || base64_decode($body, true) === false) {
            return null;
        }
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($body, 64, "\n") . "-----END CERTIFICATE-----";

        $readable = @openssl_x509_read($pem) !== false; // quiet: a warning printed here would stop the page from sending its status
        while (openssl_error_string() !== false) {
            // drain OpenSSL's error queue so it does not leak into later calls
        }

        return $readable ? $pem : null;
    }

    private function samlForm(?array $connection, array $item, array $errors = []): ResponseInterface
    {
        $details = $connection === null ? null : [
            'entity_id' => $this->samlService->entityId((int) $connection['id']),
            'acs_url' => $this->samlService->acsUrl((int) $connection['id']),
            'metadata_url' => $this->samlService->entityId((int) $connection['id']),
        ];
        $expires = null;
        if ($connection !== null) {
            $parsed = openssl_x509_parse((string) $connection['idp_certificate']);
            $expires = is_array($parsed) ? (int) $parsed['validTo_time_t'] : null;
        }

        return $this->render('saml/form.html.twig', [
            'tab' => 'saml', 'connection' => $connection, 'item' => $item, 'errors' => $errors, 'details' => $details,
            'cert_expires' => $expires, 'people' => $connection === null ? 0 : $this->samlConnections->peopleCount((int) $connection['id']),
        ], $errors ? 422 : 200);
    }

    private function userUnlinkConnection(ServerRequestInterface $request, string $sub, string $provider): ResponseInterface
    {
        $user = $this->users->find($sub);
        if ($user === null) {
            return $this->notFound('User');
        }
        $label = $this->social->labelFor($provider);
        $removed = $this->social->unlinkAny($sub, $provider);

        return $this->done('/admin/users/' . rawurlencode($sub) . '/edit', $removed ? "{$label} is disconnected from {$user['name']}." : "{$user['name']} had no {$label} account connected.", $removed ? 'success' : 'error');
    }

    private function userSendReset(ServerRequestInterface $request, string $sub): ResponseInterface
    {
        $user = $this->users->find($sub);
        if ($user === null) {
            return $this->notFound('User');
        }
        $back = '/admin/users/' . rawurlencode($sub) . '/edit';
        if ($this->passwordReset === null) {
            return $this->done($back, 'Email is not set up, so no password reset can be sent (see SSO_SMTP_HOST).', 'error');
        }

        try {
            $this->passwordReset->sendTo($user, $this->clientIp->of($request));
        } catch (\DomainException $e) {
            return $this->done($back, $e->getMessage(), 'error');
        } catch (MailFailed $e) {
            return $this->done($back, 'The email could not be sent: ' . $e->getMessage(), 'error');
        }

        return $this->done($back, "A password reset email was sent to {$user['email']}.");
    }

    private function userResetMfa(ServerRequestInterface $request, string $sub): ResponseInterface
    {
        $user = $this->users->find($sub);
        if ($user === null) {
            return $this->notFound('User');
        }
        $this->mfa->disable($sub);
        $this->sessions->endOthers($sub, $sub === $this->admin['sub'] ? (string) ($_SESSION['sid'] ?? '') : '');

        return $this->done('/admin/users/' . rawurlencode($sub) . '/edit', "Two-step verification was reset for {$user['name']}. They are signed out everywhere" . ($this->mfa->requiredFor($user) ? ' and set it up again at their next sign-in.' : '.'));
    }

    private function userResetLock(ServerRequestInterface $request, string $sub): ResponseInterface
    {
        $user = $this->users->find($sub);
        if ($user === null) {
            return $this->notFound('User');
        }
        $this->throttle->resetAccount($sub, $user['username'], $user['email']);

        return $this->done('/admin/users/' . rawurlencode($sub) . '/edit', "{$user['name']} can try signing in again.");
    }

    /** Locks the account until an administrator unlocks it: no sign-in, and every session and token it has ends now. */
    private function userLock(ServerRequestInterface $request, string $sub): ResponseInterface
    {
        $user = $this->users->find($sub);
        if ($user === null) {
            return $this->notFound('User');
        }
        $back = $this->lockReturnUrl($request, $sub);
        if ($sub === $this->admin['sub']) {
            return $this->done($back, 'You cannot lock your own account.', 'error');
        }
        $this->users->setLocked($sub, true);
        $this->sessions->endAllForUser($sub);

        return $this->done($back, "{$user['name']} is locked and signed out everywhere. They cannot sign in until you unlock them.");
    }

    private function userUnlock(ServerRequestInterface $request, string $sub): ResponseInterface
    {
        $user = $this->users->find($sub);
        if ($user === null) {
            return $this->notFound('User');
        }
        $this->users->setLocked($sub, false);
        $this->throttle->resetAccount($sub, $user['username'], $user['email']);

        return $this->done($this->lockReturnUrl($request, $sub), "{$user['name']} can sign in again.");
    }

    /** The lock buttons sit on the user page and on the Activity tab; the person returns to where they pressed. */
    private function lockReturnUrl(ServerRequestInterface $request, string $sub): string
    {
        return (((array) $request->getParsedBody())['back'] ?? '') === 'activity'
            ? '/admin/security'
            : '/admin/users/' . rawurlencode($sub) . '/danger';
    }

    // -------------------------------------------------------------- scopes

    private function scopeList(ServerRequestInterface $request): ResponseInterface
    {
        $scopes = array_map(fn (array $scope): array => $scope + ['clients' => $scope['builtin'] ? [] : $this->clients->usingScope($scope['name'])], $this->scopes->all());

        return $this->render('scopes/list.html.twig', ['scopes' => $scopes]);
    }

    private function scopeNew(ServerRequestInterface $request): ResponseInterface
    {
        return $this->scopeForm(null, ['name' => '', 'description' => '']);
    }

    private function scopeEdit(ServerRequestInterface $request, string $name): ResponseInterface
    {
        return $this->scopeTab($name, 'details');
    }

    private function scopeDanger(ServerRequestInterface $request, string $name): ResponseInterface
    {
        return $this->scopeTab($name, 'danger');
    }

    private function scopeTab(string $name, string $tab): ResponseInterface
    {
        $scope = $this->scopes->find($name);
        if ($scope === null) {
            return $this->notFound('Scope');
        }
        if ($scope['builtin']) {
            return $this->done('/admin/scopes', "{$name} is built in, so there is nothing to change.", 'error');
        }

        return $this->scopeForm($scope, $scope, [], $tab);
    }

    private function scopeCreate(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $input = ['name' => $this->text($body, 'name'), 'description' => $this->text($body, 'description')];
        $errors = [];

        if (!ScopeName::isValid($input['name'])) {
            $errors['name'] = 'Use letters, digits and . _ : - only (at most 64 characters), such as invoices:read.';
        } elseif ($this->scopes->exists($input['name'])) {
            $errors['name'] = 'There is already a scope with that name.';
        }
        if (mb_strlen($input['description']) > 255) {
            $errors['description'] = 'Max 255 characters.';
        }
        if ($errors) {
            return $this->scopeForm(null, $input, $errors);
        }
        $this->scopes->create($input['name'], $input['description']);

        return $this->done('/admin/scopes', "Scope {$input['name']} added. No app can ask for it until you allow it on the app's page.");
    }

    private function scopeUpdate(ServerRequestInterface $request, string $name): ResponseInterface
    {
        $scope = $this->scopes->find($name);
        if ($scope === null) {
            return $this->notFound('Scope');
        }
        if ($scope['builtin']) {
            return $this->done('/admin/scopes', "{$name} is built in, so there is nothing to change.", 'error');
        }
        $input = ['name' => $name, 'description' => $this->text((array) $request->getParsedBody(), 'description')];
        if (mb_strlen($input['description']) > 255) {
            return $this->scopeForm($scope, $input, ['description' => 'Max 255 characters.']);
        }
        $this->scopes->updateDescription($name, $input['description']);

        return $this->done('/admin/scopes', "Scope {$name} updated.");
    }

    private function scopeDelete(ServerRequestInterface $request, string $name): ResponseInterface
    {
        if ($this->scopes->isBuiltin($name)) {
            return $this->done('/admin/scopes', "{$name} is built in and cannot be removed.", 'error');
        }
        $using = $this->clients->usingScope($name);
        if ($using !== []) {
            $names = implode(', ', array_map(static fn (array $c): string => $c['name'], $using));

            return $this->done('/admin/scopes', "{$name} is still allowed for: {$names}. Take it off their allowed scopes first.", 'error');
        }
        if (!$this->scopes->delete($name)) {
            return $this->notFound('Scope');
        }

        return $this->done('/admin/scopes', "Scope {$name} removed. Access tokens already issued with it work until they expire.");
    }

    /**
     * @param array{name: string, description: string, builtin: bool}|null $scope null for a new one
     * @param array{name: string, description: string} $item
     * @param array<string, string> $errors
     */
    private function scopeForm(?array $scope, array $item, array $errors = [], string $tab = 'details'): ResponseInterface
    {
        return $this->render('scopes/form.html.twig', [
            'tab' => $tab,
            'scope' => $scope,
            'item' => $item,
            'errors' => $errors,
            'clients' => $scope === null ? [] : $this->clients->usingScope($scope['name']),
        ], $errors ? 422 : 200);
    }

    /** What each scope means, for the allowed-scopes list on a client. @return array<string, string> */
    private function scopeInfo(): array
    {
        $info = [];
        foreach ($this->scopes->all() as $scope) {
            $info[$scope['name']] = $scope['description'];
        }

        return $info;
    }

    private function ipRuleList(ServerRequestInterface $request): ResponseInterface
    {
        return $this->ipRuleForm($request, null, ['type' => 'deny', 'cidr' => '', 'note' => '']);
    }

    private function ipRuleCreate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->createIpRule($request, null);
    }

    private function ipRuleDelete(ServerRequestInterface $request, string $id): ResponseInterface
    {
        return $this->deleteIpRule((int) $id, null);
    }

    /** The tab used to be called Network: keep old bookmarks working. */
    private function clientNetworkMoved(ServerRequestInterface $request, string $clientId): ResponseInterface
    {
        return new RedirectResponse('/admin/clients/' . rawurlencode($clientId) . '/ip-rules', 301);
    }

    private function clientIpRules(ServerRequestInterface $request, string $clientId): ResponseInterface
    {
        $client = $this->clients->find($clientId);

        return $client === null ? $this->notFound('Client') : $this->ipRuleForm($request, $client, ['type' => 'deny', 'cidr' => '', 'note' => '']);
    }

    private function clientIpRuleCreate(ServerRequestInterface $request, string $clientId): ResponseInterface
    {
        $client = $this->clients->find($clientId);

        return $client === null ? $this->notFound('Client') : $this->createIpRule($request, $client);
    }

    private function clientIpRuleScope(ServerRequestInterface $request, string $clientId): ResponseInterface
    {
        $client = $this->clients->find($clientId);
        if ($client === null) {
            return $this->notFound('Client');
        }
        $ignores = (((array) $request->getParsedBody())['ignore_general'] ?? '') === '1';
        $this->ipRules->setIgnoresGeneral($clientId, $ignores);

        return $this->done($this->ipRulesUrl($client), $ignores
            ? "{$client['name']} now ignores the general IP rules."
            : "The general IP rules apply to {$client['name']} again.");
    }

    private function clientIpRuleDelete(ServerRequestInterface $request, string $clientId, string $id): ResponseInterface
    {
        $client = $this->clients->find($clientId);

        return $client === null ? $this->notFound('Client') : $this->deleteIpRule((int) $id, $client);
    }

    /** @param ?array $client the client the rule is for, or null for a global rule */
    private function createIpRule(ServerRequestInterface $request, ?array $client): ResponseInterface
    {
        $clientId = $client['client_id'] ?? null;
        $body = (array) $request->getParsedBody();
        $input = ['type' => $this->text($body, 'type'), 'cidr' => $this->text($body, 'cidr'), 'note' => $this->text($body, 'note')];
        $errors = [];

        $cidr = Cidr::normalize($input['cidr']);
        if (!in_array($input['type'], ['allow', 'deny'], true)) {
            $errors['type'] = 'Choose allow or deny.';
        }
        if ($cidr === null) {
            $errors['cidr'] = 'Enter an IP address or a range such as 203.0.113.0/24 or 2001:db8::/32.';
        } elseif (!$errors && $this->ipRules->exists($input['type'], $cidr, $clientId)) {
            $errors['cidr'] = 'This rule already exists.';
        }
        if (mb_strlen($input['note']) > 255) {
            $errors['note'] = 'Max 255 characters.';
        }
        // Global rules: never let an administrator lock themselves out of the whole service by accident.
        // Client rules only affect that one app, and can always be fixed from here.
        $mine = $this->clientIp->of($request);
        if ($client === null && !$errors && !$this->ipPolicy->allowsAfterAdding($input['type'], $cidr, $mine)) {
            $errors['cidr'] = "This rule would block your own address ({$mine}), so you could not sign in again. "
                . ($input['type'] === 'allow' ? 'Include your address in the range.' : 'Use a range that does not contain your address.');
        }
        if ($errors) {
            return $this->ipRuleForm($request, $client, $input, $errors);
        }
        $this->ipRules->create($input['type'], $cidr, $input['note'], $clientId);

        return $this->done($this->ipRulesUrl($client), ucfirst($input['type']) . " rule for {$cidr} added.");
    }

    private function deleteIpRule(int $id, ?array $client): ResponseInterface
    {
        $rule = $this->ipRules->find($id);
        // A rule can only be removed from the page it is shown on.
        if ($rule === null || $rule['client_id'] !== ($client['client_id'] ?? null)) {
            return $this->notFound('Rule');
        }
        $this->ipRules->delete($rule['id']);

        return $this->done($this->ipRulesUrl($client), "Rule for {$rule['cidr']} removed.");
    }

    private function ipRulesUrl(?array $client): string
    {
        return $client === null ? '/admin/ip-rules' : '/admin/clients/' . rawurlencode($client['client_id']) . '/ip-rules';
    }

    private function ipRuleForm(ServerRequestInterface $request, ?array $client, array $item, array $errors = []): ResponseInterface
    {
        $rules = $this->ipRules->all($client['client_id'] ?? null);
        $context = [
            'rules' => $rules,
            'item' => $item,
            'errors' => $errors,
            'my_ip' => $this->clientIp->of($request),
            'allow_active' => (bool) array_filter($rules, static fn (array $r): bool => $r['type'] === 'allow'),
            'base' => $this->ipRulesUrl($client),
            'client' => $client,
            // The general rules also apply to a client; shown read-only on its page.
            'inherited' => $client === null ? [] : $this->ipRules->all(),
            'ignores_general' => $client !== null && $this->ipRules->ignoresGeneral($client['client_id']),
        ];

        return $client === null
            ? $this->render('ip_rules.html.twig', $context, $errors ? 422 : 200)
            : $this->render('clients/form.html.twig', $context + ['tab' => 'ip-rules', 'rel_groups' => null, 'rel_users' => null, 'grants' => self::GRANTS, 'scope_names' => $this->scopes->names(), 'scope_info' => $this->scopeInfo(), 'scope_builtin' => $this->scopes->builtinNames(), 'new_secret' => null], $errors ? 422 : 200);
    }

    // -------------------------------------------------------------- access test

    /** A read-only question asked through the query string, so a result can be bookmarked and shared. */
    private function accessTest(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $asked = isset($q['ip']);
        $input = [
            'ip' => trim((string) ($q['ip'] ?? $this->clientIp->of($request))),
            'client' => (string) ($q['client'] ?? ''),
            'user' => trim((string) ($q['user'] ?? '')),
        ];
        $error = null;
        $result = null;

        // A person is typed in as a username (or an id, which is what a shared link holds); the test itself works with the id.
        $person = $input['user'] === '' ? null : ($this->users->find($input['user']) ?? $this->users->findByUsername($input['user']));
        $userError = $asked && $input['user'] !== '' && $person === null ? 'No one has that username or id.' : null;

        if ($asked) {
            if (!AccessTester::validIp($input['ip'])) {
                $error = 'Enter a single IP address, for example 203.0.113.7 or 2001:db8::1.';
            } else {
                $result = $userError !== null ? null : $this->tester->evaluate($input['ip'], $input['client'] ?: null, $person['sub'] ?? null);
            }
        }

        $target = $person;

        return $this->render('access_test.html.twig', [
            'target' => $target,
            'target_refusal' => $target === null ? null : $this->impersonation->refusal($this->admin, $target),
            'input' => $input,
            'error' => $error,
            'result' => $result,
            'client_options' => $this->clients->all(),
            'user_options' => $this->users->page('', 30, 0),
            'user_error' => $userError,
            'my_ip' => $this->clientIp->of($request),
        ], $error || $userError ? 422 : 200);
    }

    /** Signs this browser in as the chosen person, to see the apps the way they do. Stopping is done from the banner. */
    private function impersonate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->startImpersonation($request, $this->text((array) $request->getParsedBody(), 'user'), '/admin/access-test?ip=' . rawurlencode($this->clientIp->of($request)) . '&user=');
    }

    private function userImpersonate(ServerRequestInterface $request, string $sub): ResponseInterface
    {
        return $this->startImpersonation($request, $sub, '/admin/users/' . rawurlencode($sub) . '/edit?');
    }

    /** @param string $failedUrl where to go back to when it is refused (the reason is flashed there) */
    private function startImpersonation(ServerRequestInterface $request, string $sub, string $failedUrl): ResponseInterface
    {
        $error = $this->impersonation->start($this->admin, $sub, $this->clientIp->of($request), $request->getHeaderLine('User-Agent'));
        if ($error !== null) {
            return $this->done(str_ends_with($failedUrl, '=') ? $failedUrl . rawurlencode($sub) : rtrim($failedUrl, '?'), $error, 'error');
        }

        return new RedirectResponse('/account', 303);
    }

    // ------------------------------------------------------------------ helpers

    private function render(string $template, array $context = [], int $status = 200): ResponseInterface
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return new HtmlResponse($this->twig->render('admin/' . $template, $context + [
            'flash' => $flash,
            'admin' => $this->admin,
            'csrf' => $_SESSION['csrf'],
            'counts' => [
                'users' => $this->users->count(),
                'groups' => $this->groups->count(),
                'clients' => $this->clients->count(),
                'roles' => $this->roles->count(),
            ],
            'search_index' => $this->searchIndex(),
        ]), $status);
    }

    /** Palette entry in the shape admin.js expects: type, title, subtitle, url, avatar hue and initials. */
    private static function paletteItem(string $type, string $title, string $sub, string $url, string $keywords = ''): array
    {
        return [
            't' => $type, 'title' => $title, 'sub' => $sub, 'url' => $url, 'k' => mb_strtolower($keywords),
            'h' => AdminTwigExtension::hue($title), 'i' => AdminTwigExtension::initials($title),
        ];
    }

    /**
     * The few things the command palette always offers (actions and pages), as JSON for an inline
     * <script type="application/json">. People, groups and clients are not embedded: they are looked up
     * through /admin/search as you type, so the pages stay small however many there are.
     */
    private function searchIndex(): string
    {
        $items = [
            self::paletteItem('action', 'New user', 'Create a person who can sign in', '/admin/users/new', 'add create'),
            self::paletteItem('action', 'New group', 'Create a group', '/admin/groups/new', 'add create'),
            self::paletteItem('action', 'New role', 'Create a role apps can check', '/admin/roles/new', 'add create'),
            self::paletteItem('action', 'New client', 'Register an app', '/admin/clients/new', 'add create app'),
            self::paletteItem('action', 'New scope', 'Add something an app can ask for', '/admin/scopes/new', 'add create permission'),
            self::paletteItem('page', 'Dashboard', '', '/admin'),
            self::paletteItem('page', 'Users', 'All people', '/admin/users'),
            self::paletteItem('page', 'Groups', 'All groups', '/admin/groups'),
            self::paletteItem('page', 'Roles', 'All roles', '/admin/roles'),
            self::paletteItem('page', 'Clients', 'All apps', '/admin/clients'),
            self::paletteItem('page', 'Scopes', 'What apps can ask for', '/admin/scopes'),
        ];

        return json_encode($items, self::JSON_SAFE);
    }

    /** Palette lookup: a handful of matching people, groups and clients. */
    private function search(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams()['q'] ?? '';
        $q = is_string($q) ? trim($q) : '';
        if ($q === '') {
            return new JsonResponse([]);
        }

        $items = [];
        foreach ($this->users->search($q, 6) as $u) {
            $items[] = self::paletteItem('user', $u['name'], $u['username'], '/admin/users/' . rawurlencode($u['sub']) . '/edit');
        }
        foreach ($this->groups->search($q, 4) as $g) {
            $items[] = self::paletteItem('group', $g['name'], $g['members'] . ((int) $g['members'] === 1 ? ' member' : ' members'), '/admin/groups/' . $g['id'] . '/edit');
        }
        foreach ($this->clients->search($q, 4) as $c) {
            $items[] = self::paletteItem('client', $c['name'], $c['client_id'], '/admin/clients/' . rawurlencode($c['client_id']) . '/edit');
        }

        return new JsonResponse($items);
    }

    /** Redirect after a successful POST, with a one-time message (and optionally a one-time secret). */
    private function done(string $to, string $message, string $type = 'success', ?string $secret = null, ?string $clientId = null): ResponseInterface
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
        if ($secret !== null) {
            $_SESSION['new_secret'] = ['client_id' => $clientId, 'secret' => $secret];
        }

        return new RedirectResponse($to);
    }

    private function notFound(string $what): ResponseInterface
    {
        return $this->render('error.html.twig', ['message' => "{$what} not found"], 404);
    }

    private function csrfValid(ServerRequestInterface $request): bool
    {
        $body = (array) $request->getParsedBody();

        return hash_equals($_SESSION['csrf'], (string) ($body['_csrf'] ?? ''));
    }

    private function text(array $body, string $key): string
    {
        return trim((string) ($body[$key] ?? ''));
    }
}

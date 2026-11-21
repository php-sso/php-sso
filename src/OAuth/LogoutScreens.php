<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\Pages;
use PhpSso\Store\Users;
use Laminas\Diactoros\Response\RedirectResponse;
use PhpSso\OAuthServer\Client\Client;
use PhpSso\OAuthServer\Logout\LogoutUi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The screens of the end-session endpoint, and the clearing of this browser's session once it is over. */
final class LogoutScreens implements LogoutUi
{
    public function __construct(private Pages $pages, private Users $users)
    {
    }

    public function confirm(ServerRequestInterface $request, ?Client $client, array $fields): ResponseInterface
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
        $user = is_string($_SESSION['user'] ?? null) ? $this->users->find($_SESSION['user']) : null;

        return $this->pages->render('logout_confirm', [
            'csrf' => $_SESSION['csrf'],
            'user' => $user === null ? null : ['name' => $user['name'], 'username' => $user['username'], 'email' => $user['email'], 'is_admin' => (bool) $user['is_admin']],
            'client_name' => $client instanceof SsoClient ? $client->name : $client?->id(),
            'fields' => $fields,
        ]);
    }

    public function signedOut(ServerRequestInterface $request, ?string $redirectUri, ?string $endedSessionId): ResponseInterface
    {
        // Sign this browser out only if it belongs to the session that ended; a client may name another one.
        if ($endedSessionId !== null && ($_SESSION['sid'] ?? null) === $endedSessionId) {
            $_SESSION = [];
            session_regenerate_id(true);
        }

        if ($redirectUri !== null) {
            return new RedirectResponse($redirectUri);
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));

        return $this->pages->render('signed_out', ['csrf' => $_SESSION['csrf'], 'user' => null]);
    }
}

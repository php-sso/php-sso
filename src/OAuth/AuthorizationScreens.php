<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\Pages;
use PhpSso\Store\Users;
use Laminas\Diactoros\Response\RedirectResponse;
use PhpSso\OAuthServer\Authorization\AuthorizationUi;
use PhpSso\OAuthServer\Client\Client;
use PhpSso\OAuthServer\Endpoint\AuthorizationHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The two screens the authorization endpoint hands back to us: the sign-in page, and "no access". */
final class AuthorizationScreens implements AuthorizationUi
{
    public function __construct(private Pages $pages, private Users $users)
    {
    }

    /**
     * To the sign-in page, and back to the authorization request afterwards. When `prompt=login` or `max_age` asked
     * for a fresh sign-in, the way back leaves those two out, otherwise the person would be sent to sign in forever.
     * (/login always shows the form, signed in or not, and a successful login replaces the session.)
     */
    public function loginRequired(ServerRequestInterface $request): ResponseInterface
    {
        $resume = $request->getAttribute(AuthorizationHandler::RESUME_PARAMS);
        $query = is_array($resume) ? http_build_query($resume, '', '&', PHP_QUERY_RFC3986) : $request->getUri()->getQuery();
        $_SESSION['return_to'] = '/authorize?' . $query;

        return new RedirectResponse('/login');
    }

    public function accessDenied(ServerRequestInterface $request, Client $client): ResponseInterface
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
        $user = is_string($_SESSION['user'] ?? null) ? $this->users->find($_SESSION['user']) : null;

        return $this->pages->render('no_access', [
            'csrf' => $_SESSION['csrf'],
            'user' => $user === null ? null : ['name' => $user['name'], 'username' => $user['username'], 'email' => $user['email'], 'is_admin' => (bool) $user['is_admin']],
            'client_name' => $client instanceof SsoClient ? $client->name : $client->id(),
        ], 403);
    }
}

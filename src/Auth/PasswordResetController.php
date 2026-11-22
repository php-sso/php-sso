<?php

declare(strict_types=1);

namespace PhpSso\Auth;

use Laminas\Diactoros\Response\RedirectResponse;
use PhpSso\Net\ClientIp;
use PhpSso\Pages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The pages of the password reset: asking for a link (`/password/forgot`), the link itself, and choosing the password (`/password/reset`).
 * The link only starts it: its token moves into the session and the address bar is left clean, so it is not in the history, in a copied address or in a referrer.
 */
final class PasswordResetController
{
    private const TOKEN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(private Pages $pages, private PasswordReset $reset, private ClientIp $clientIp, private int $minutes)
    {
    }

    /** @return ResponseInterface|null null when the request is not for this controller */
    public function handle(ServerRequestInterface $request, string $path): ?ResponseInterface
    {
        $method = $request->getMethod();
        if ($path === '/password/forgot') {
            return match ($method) {
                'GET' => $this->forgotForm(),
                'POST' => $this->forgot($request),
                default => null,
            };
        }
        if ($method === 'GET' && preg_match('#^/password/reset/([^/]+)$#', $path, $m) === 1) {
            return $this->link(rawurldecode($m[1]));
        }
        if ($path === '/password/reset') {
            return match ($method) {
                'GET' => $this->resetForm(),
                'POST' => $this->resetPassword($request),
                default => null,
            };
        }

        return null;
    }

    private function forgotForm(string $error = '', string $typed = '', int $status = 200): ResponseInterface
    {
        return $this->pages->render('password_forgot', $this->context() + ['sent' => false, 'error' => $error, 'identifier' => $typed, 'minutes' => $this->minutes], $status);
    }

    private function forgot(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (!$this->csrfValid($body)) {
            return $this->expired();
        }
        $typed = is_string($body['identifier'] ?? null) ? trim($body['identifier']) : '';
        if ($typed === '') {
            return $this->forgotForm('Enter your username or your email address.', '', 400);
        }

        $this->reset->request($typed, $this->clientIp->of($request));

        return $this->pages->render('password_forgot', $this->context() + ['sent' => true, 'error' => '', 'identifier' => '', 'minutes' => $this->minutes]);
    }

    private function link(string $token): ResponseInterface
    {
        if (preg_match(self::TOKEN, $token) !== 1 || $this->reset->inspect($token) === null) {
            unset($_SESSION['password_reset']);

            return $this->invalid();
        }
        $_SESSION['password_reset'] = $token;

        return new RedirectResponse('/password/reset', 303);
    }

    private function resetForm(string $error = '', int $status = 200): ResponseInterface
    {
        $user = is_string($_SESSION['password_reset'] ?? null) ? $this->reset->inspect($_SESSION['password_reset']) : null;
        if ($user === null) {
            return $this->invalid();
        }

        return $this->pages->render('password_reset', $this->context() + ['username' => $user['username'], 'min_length' => PasswordReset::MIN_LENGTH, 'error' => $error], $status);
    }

    private function resetPassword(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (!$this->csrfValid($body)) {
            return $this->expired();
        }
        $token = $_SESSION['password_reset'] ?? null;
        if (!is_string($token)) {
            return $this->invalid();
        }

        $error = $this->reset->complete($token, (string) ($body['new_password'] ?? ''), (string) ($body['confirm_password'] ?? ''), $this->clientIp->of($request));
        if ($error === null) {
            unset($_SESSION['password_reset']);
            $_SESSION['login_notice'] = 'Your password was changed. Sign in with the new one.';

            return new RedirectResponse('/login', 303);
        }
        if ($error === 'This link is no longer valid.') {
            unset($_SESSION['password_reset']);

            return $this->invalid();
        }

        return $this->resetForm($error, 422);
    }

    private function invalid(): ResponseInterface
    {
        return $this->pages->render('password_reset_invalid', $this->context(), 400);
    }

    private function expired(): ResponseInterface
    {
        return $this->pages->render('error', ['title' => 'Page expired', 'message' => 'Go back, reload the page and try again.', 'user' => null, 'csrf' => ''], 400);
    }

    /** @param array<string, mixed> $body */
    private function csrfValid(array $body): bool
    {
        return hash_equals($_SESSION['csrf'] ?? '', (string) ($body['_csrf'] ?? ''));
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));

        return ['csrf' => $_SESSION['csrf'], 'user' => null];
    }
}

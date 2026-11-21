<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\Database;
use PhpSso\Pages;
use PDO;
use PhpSso\OAuthServer\Device\DeviceVerifier;
use PhpSso\OAuthServer\Exception\OAuthException;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The page a person opens on their phone or computer to approve a device (RFC 8628): enter the code the device
 * shows, see which app is asking, approve or decline. The OAuth server's DeviceVerifier does the work; this
 * page signs the person in first and limits how many wrong codes one person may try.
 */
final class DevicePage
{
    private const MAX_FAILURES = 10;
    private const WINDOW_SECONDS = 600;

    private PDO $pdo;

    public function __construct(private Pages $pages, private DeviceVerifier $verifier, Database $db)
    {
        $this->pdo = $db->pdo;
    }

    /** @param array<string, mixed> $user the signed-in person */
    public function handle(ServerRequestInterface $request, array $user): ResponseInterface
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
        $isPost = $request->getMethod() === 'POST';
        $params = $isPost ? (array) $request->getParsedBody() : $request->getQueryParams();
        $typed = is_string($params['user_code'] ?? null) ? trim($params['user_code']) : '';
        $action = $isPost && is_string($params['action'] ?? null) ? $params['action'] : '';

        if ($isPost && !hash_equals($_SESSION['csrf'], (string) ($params['_csrf'] ?? ''))) {
            return $this->render($user, ['step' => 'enter', 'error' => 'The page expired. Enter the code again.'], 400);
        }
        if ($typed === '') {
            return $this->render($user, ['step' => 'enter']);
        }
        if ($this->failures($user['sub']) >= self::MAX_FAILURES) {
            return $this->render($user, ['step' => 'enter', 'error' => 'Too many wrong codes. Wait a few minutes and try again.'], 429);
        }

        $pending = $this->verifier->lookup($typed);
        if ($pending === null) {
            $this->recordFailure($user['sub']);

            return $this->render($user, ['step' => 'enter', 'error' => 'That code is not valid or has expired.'], 422);
        }

        if ($action === 'approve' || $action === 'deny') {
            try {
                $action === 'approve' ? $this->verifier->approve($typed, $user['sub'], is_string($_SESSION['sid'] ?? null) ? $_SESSION['sid'] : null) : $this->verifier->deny($typed);
            } catch (OAuthException $e) {
                return $this->render($user, ['step' => 'enter', 'error' => $e->errorDescription], 403);
            }

            return $this->render($user, ['step' => 'done', 'approved' => $action === 'approve', 'client_name' => $this->nameOf($pending->client)]);
        }

        return $this->render($user, [
            'step' => 'confirm',
            'user_code' => $pending->userCode,
            'client_name' => $this->nameOf($pending->client),
            'scopes' => $pending->scopes,
        ]);
    }

    /** Sends a signed-out person to sign in, and back to the page with the code they were given. */
    public function loginRedirect(ServerRequestInterface $request): ResponseInterface
    {
        $_SESSION['return_to'] = '/device' . ($request->getUri()->getQuery() === '' ? '' : '?' . $request->getUri()->getQuery());

        return new RedirectResponse('/login');
    }

    private function nameOf(\PhpSso\OAuthServer\Client\Client $client): string
    {
        return $client instanceof SsoClient ? $client->name : $client->id();
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $context */
    private function render(array $user, array $context, int $status = 200): ResponseInterface
    {
        return $this->pages->render('device', $context + [
            'error' => '',
            'user_code' => '',
            'client_name' => '',
            'scopes' => [],
            'approved' => false,
            'csrf' => $_SESSION['csrf'],
            'user' => ['name' => $user['name'], 'username' => $user['username'], 'email' => $user['email'], 'is_admin' => (bool) $user['is_admin']],
        ], $status);
    }

    private function failures(string $userSub): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM device_failures WHERE user_sub = ? AND created_at > ?');
        $stmt->execute([$userSub, time() - self::WINDOW_SECONDS]);

        return (int) $stmt->fetchColumn();
    }

    private function recordFailure(string $userSub): void
    {
        $this->pdo->prepare('INSERT INTO device_failures (user_sub, created_at) VALUES (?, ?)')->execute([$userSub, time()]);
    }
}

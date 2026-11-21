<?php

declare(strict_types=1);

namespace PhpSso\Auth;

use PhpSso\Auth\Actions\RequiredActions;
use PhpSso\Net\ClientIp;
use PhpSso\Pages;
use PhpSso\Store\Users;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The page between proving who you are and being signed in, when something is required first (a new password).
 * One address serves every action: it shows the first one that still applies, and signs the person in when none does.
 */
final class RequiredActionController
{
    public function __construct(
        private Pages $pages,
        private LoginFlow $flow,
        private RequiredActions $actions,
        private Users $users,
        private ClientIp $clientIp,
    ) {
    }

    public function form(string $error = '', int $status = 200, ?ServerRequestInterface $request = null): ResponseInterface
    {
        $pending = $this->flow->actionsPending();
        $user = $pending === null ? null : $this->users->find($pending['sub']);
        if ($pending === null || $user === null) {
            return new RedirectResponse('/login');
        }
        $action = $this->actions->next($user);
        if ($action === null) {
            return $this->finish($user, $pending['identifier'], $pending['amr'], $request); // nothing applies any more (done in another tab)
        }
        $page = $action->page($user);

        return $this->pages->render($page['template'], $this->context() + $page['vars'] + ['error' => $error], $status);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($body['_csrf'] ?? ''))) {
            return $this->pages->render('error', $this->context() + ['title' => 'Page expired', 'message' => 'Go back, reload the sign-in page and try again.'], 400);
        }
        $pending = $this->flow->actionsPending();
        $user = $pending === null ? null : $this->users->find($pending['sub']);
        if ($pending === null || $user === null) {
            return new RedirectResponse('/login');
        }
        $action = $this->actions->next($user);
        if ($action !== null) {
            $error = $action->submit($user, $body, $this->clientIp->of($request));
            if ($error !== null) {
                return $this->form($error, 422, $request);
            }
            $user = $this->users->find($user['sub']) ?? $user;
            if ($this->actions->next($user) !== null) {
                return new RedirectResponse('/login/required', 303); // the next one
            }
        }

        return $this->finish($user, $pending['identifier'], $pending['amr'], $request);
    }

    /** @param array<string, mixed> $user @param list<string> $amr how they proved who they are, kept for the session */
    private function finish(array $user, string $identifier, array $amr, ?ServerRequestInterface $request): ResponseInterface
    {
        $ip = $request === null ? '' : $this->clientIp->of($request);
        $agent = $request === null ? '' : $request->getHeaderLine('User-Agent');

        return new RedirectResponse($this->flow->complete($user, $identifier, $ip, $agent, $amr), 303);
    }

    /** @return array{csrf: string, user: null} */
    private function context(): array
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));

        return ['csrf' => $_SESSION['csrf'], 'user' => null];
    }
}

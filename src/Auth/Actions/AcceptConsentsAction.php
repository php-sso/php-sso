<?php

declare(strict_types=1);

namespace PhpSso\Auth\Actions;

use PhpSso\Store\Consents;

/** The person must accept every notice that is switched on, at its current version, one page each. */
final class AcceptConsentsAction implements RequiredAction
{
    public function __construct(private Consents $consents)
    {
    }

    public function key(): string
    {
        return 'accept_consents';
    }

    public function applies(array $user): bool
    {
        return $this->consents->pendingFor($user['sub']) !== [];
    }

    public function page(array $user): array
    {
        $pending = $this->consents->pendingFor($user['sub']);
        $consent = $pending[0];

        return ['template' => 'consent', 'vars' => [
            'consent' => ['id' => (int) $consent['id'], 'version' => (int) $consent['version'], 'name' => $consent['name'], 'message' => $consent['message'], 'url' => $consent['url']],
            'step' => ['number' => 1, 'of' => count($pending)],
        ]];
    }

    public function submit(array $user, array $body, string $ip): ?string
    {
        $pending = $this->consents->pendingFor($user['sub']);
        if ($pending === []) {
            return null;
        }
        $consent = $pending[0];
        if ((int) ($body['consent_id'] ?? 0) !== (int) $consent['id'] || (int) ($body['version'] ?? 0) !== (int) $consent['version']) {
            return 'This notice has changed. Read it again before you accept.';
        }
        if (!isset($body['accept'])) {
            return 'Tick the box to continue.';
        }

        return $this->consents->accept($user['sub'], (int) $consent['id'], (int) $consent['version'], $ip) ? null : 'This notice has changed. Read it again before you accept.';
    }
}

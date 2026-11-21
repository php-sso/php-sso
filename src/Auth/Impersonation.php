<?php

declare(strict_types=1);

namespace PhpSso\Auth;

use PhpSso\Store\Sessions;
use PhpSso\Store\Users;

/**
 * An administrator browsing as someone else, to see what that person sees. The person gets a login session of their
 * own (marked with the administrator who started it, so it is in the audit trail) and the administrator's session is
 * kept aside in the PHP session until they stop. Two-step verification and required actions are skipped on purpose:
 * the administrator is not the person. Everything that changes the account itself is switched off meanwhile (see Sso).
 */
final class Impersonation
{
    public function __construct(private Sessions $sessions, private Users $users)
    {
    }

    /**
     * Why this administrator may not browse as this person, or null when they may. Administrators and locked
     * accounts are refused: the first would hand out someone's admin rights, the second is locked for a reason.
     *
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $target
     */
    public function refusal(array $admin, array $target): ?string
    {
        return match (true) {
            $target['sub'] === $admin['sub'] => 'That is you.',
            Users::isLocked($target) => "{$target['name']} is locked. Unlock the account first.",
            (bool) $target['is_admin'] => 'Administrators cannot be impersonated.',
            default => null,
        };
    }

    /**
     * Signs the browser in as the person. @param array<string, mixed> $admin the signed-in administrator
     * @return string|null what stopped it, or null when it worked
     */
    public function start(array $admin, string $targetSub, string $ip, string $userAgent): ?string
    {
        if ($this->active() !== null) {
            return 'Stop the current impersonation first.';
        }
        $target = $this->users->find($targetSub);
        if ($target === null) {
            return 'That person no longer exists.';
        }
        if (($refusal = $this->refusal($admin, $target)) !== null) {
            return $refusal;
        }

        $_SESSION['impersonator'] = ['sub' => $admin['sub'], 'sid' => (string) ($_SESSION['sid'] ?? '')];
        $_SESSION['user'] = $target['sub'];
        $_SESSION['sid'] = $this->sessions->start($target['sub'], $ip, $userAgent, $admin['sub']);

        return null;
    }

    /** @return array{sub: string, sid: string}|null the administrator behind this browser's session, when it is an impersonation */
    public function active(): ?array
    {
        $held = $_SESSION['impersonator'] ?? null;

        return is_array($held) && is_string($held['sub'] ?? null) && is_string($held['sid'] ?? null)
            ? ['sub' => $held['sub'], 'sid' => $held['sid']]
            : null;
    }

    /** Keeps the administrator's own session from going idle while they browse as someone else. */
    public function keepAlive(): void
    {
        if (($held = $this->active()) !== null) {
            $this->sessions->touch($held['sid'], $held['sub']);
        }
    }

    /**
     * Ends the impersonated session (its tokens are revoked and apps told, like any session) and gives the browser back
     * to the administrator. @return bool false when the administrator's own session ran out meanwhile: they are signed out
     */
    public function stop(): bool
    {
        $held = $this->active();
        if ($held === null) {
            return false;
        }
        if (is_string($_SESSION['sid'] ?? null)) {
            $this->sessions->end($_SESSION['sid']);
        }
        unset($_SESSION['impersonator']);

        if ($this->sessions->touch($held['sid'], $held['sub'])) {
            $_SESSION['user'] = $held['sub'];
            $_SESSION['sid'] = $held['sid'];

            return true;
        }
        unset($_SESSION['user'], $_SESSION['sid']);

        return false;
    }
}

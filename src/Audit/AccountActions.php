<?php

declare(strict_types=1);

namespace PhpSso\Audit;

/**
 * What the changes people make to their own account are called in the audit log. A change counts as made only when the response says so: the two
 * screens that show new recovery codes answer 200 (`expect` is `page`), every other one flashes a success message (`expect` is `flash`). A bare redirect
 * (nothing to enrol, already enrolled) and every error change nothing, so they are not recorded.
 */
final class AccountActions
{
    /** @return array{action: string, type: string|null, target: string|null, detail: array<string, string>, expect: string}|null null when the path is not a recorded change */
    public static function plan(string $path): ?array
    {
        $fixed = [
            '/profile' => ['account.profile_update', 'flash'],
            '/password' => ['account.password_change', 'flash'],
            '/mfa/setup' => ['account.mfa_enable', 'page'],
            '/mfa/recovery' => ['account.mfa_recovery_codes', 'page'],
            '/mfa/disable' => ['account.mfa_disable', 'flash'],
            '/sessions/end-others' => ['account.sessions_end_others', 'flash'],
        ];
        if (isset($fixed[$path])) {
            return ['action' => $fixed[$path][0], 'type' => null, 'target' => null, 'detail' => [], 'expect' => $fixed[$path][1]];
        }
        if (preg_match('#^/social/([a-z0-9_-]+)/unlink$#', $path, $m) === 1) {
            return ['action' => 'account.social_unlink', 'type' => null, 'target' => null, 'detail' => ['provider' => $m[1]], 'expect' => 'flash'];
        }
        if (preg_match('#^/sessions/([0-9a-f]{32})/end$#', $path, $m) === 1) {
            return ['action' => 'account.session_end', 'type' => 'session', 'target' => $m[1], 'detail' => [], 'expect' => 'flash'];
        }

        return null;
    }

    /** @param array{expect: string} $plan */
    public static function succeeded(array $plan, int $status, ?string $flashType): bool
    {
        return $plan['expect'] === 'page' ? $status === 200 : ($status < 400 && $flashType === 'success');
    }
}

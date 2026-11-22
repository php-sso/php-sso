<?php

declare(strict_types=1);

namespace PhpSso\Audit;

use PhpSso\Store\AuditLog;
use Throwable;

/**
 * Records who did what. Writing is best effort: if the audit log cannot be written (the database is down, say), the action it describes is
 * not stopped, because that would turn a broken log into an outage, but the failure and the entry are written to the error log so they are not lost.
 *
 * With `$stream` every entry is also written to the error log as one JSON line starting with `SSO_AUDIT`, so a log collector can ship it
 * somewhere that an administrator of this server cannot change.
 *
 * @phpstan-import-type Entry from AuditLog
 */
final class Audit
{
    /** The first part of the actions' names, for the filter of the audit page: `user.lock` is in the group `user`. */
    public const GROUPS = ['user', 'client', 'group', 'role', 'scope', 'ip_rule', 'session', 'scim_client', 'saml_connection', 'consent', 'security', 'relation', 'account', 'impersonation', 'keys', 'backup'];

    public function __construct(private AuditLog $log, private bool $stream = false)
    {
    }

    /**
     * @param array<string, mixed>|string $actor the signed-in person (a user row with `sub` and `username`), or a name such as `cli:deploy` for something that is not a person
     * @param array<string, mixed> $detail what else is worth knowing; never a secret
     */
    public function record(string $action, array|string $actor, string $ip = '', ?string $targetType = null, ?string $target = null, ?string $label = null, array $detail = []): void
    {
        $entry = [
            'actor_sub' => is_array($actor) ? (string) $actor['sub'] : null,
            'actor' => is_array($actor) ? (string) $actor['username'] : $actor,
            'ip' => $ip,
            'action' => $action,
            'target_type' => $targetType,
            'target' => $target,
            'target_label' => $label,
            'detail' => $detail,
        ];

        try {
            $this->log->record($entry);
        } catch (Throwable $e) {
            error_log('SSO: could not write the audit log (' . $e->getMessage() . '): ' . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        if ($this->stream) {
            error_log('SSO_AUDIT ' . json_encode(['at' => time()] + $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }
}

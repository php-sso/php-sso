<?php

declare(strict_types=1);

namespace PhpSso\Audit;

/**
 * What the administrators' changes are called in the audit log, and what is worth keeping of each. The admin panel records every POST that
 * succeeded, from this one table, so a new action needs a line here (a test fails until it has one).
 *
 * For each handler of the admin controller: its action (`thing.verb`), the kind of target, which route argument is the target (`target`, an index into
 * the route's captures), the posted field that names a thing being made (`label`) or the target itself (`targetField`), the route arguments to keep
 * (`args`: index => name), the posted fields whose values are safe to keep (`fields`), and `names`: keep only the names of the fields that were posted (for forms of settings).
 * Nothing else of what was posted is kept, so a password or a secret never reaches the log.
 */
final class AdminActions
{
    private const ACTIONS = [
        'sessionEnd' => ['action' => 'session.end', 'type' => 'session', 'target' => 0],
        'scopeCreate' => ['action' => 'scope.create', 'type' => 'scope', 'label' => 'name'],
        'scopeUpdate' => ['action' => 'scope.update', 'type' => 'scope', 'target' => 0],
        'scopeDelete' => ['action' => 'scope.delete', 'type' => 'scope', 'target' => 0],
        'ipRuleCreate' => ['action' => 'ip_rule.create', 'type' => 'ip_rule', 'fields' => ['type', 'cidr']],
        'ipRuleDelete' => ['action' => 'ip_rule.delete', 'type' => 'ip_rule', 'target' => 0],
        'clientIpRuleCreate' => ['action' => 'client.ip_rule_create', 'type' => 'client', 'target' => 0, 'fields' => ['type', 'cidr']],
        'clientIpRuleScope' => ['action' => 'client.ip_rule_scope', 'type' => 'client', 'target' => 0],
        'clientIpRuleDelete' => ['action' => 'client.ip_rule_delete', 'type' => 'client', 'target' => 0, 'args' => [1 => 'rule']],
        'impersonate' => ['action' => 'user.impersonate', 'type' => 'user', 'targetField' => 'user'],
        'userImpersonate' => ['action' => 'user.impersonate', 'type' => 'user', 'target' => 0],
        'scimCreate' => ['action' => 'scim_client.create', 'type' => 'scim_client', 'label' => 'name'],
        'scimUpdate' => ['action' => 'scim_client.update', 'type' => 'scim_client', 'target' => 0],
        'scimRotate' => ['action' => 'scim_client.rotate_token', 'type' => 'scim_client', 'target' => 0],
        'scimDelete' => ['action' => 'scim_client.delete', 'type' => 'scim_client', 'target' => 0],
        'securityAccounts' => ['action' => 'security.accounts', 'names' => true],
        'securitySettings' => ['action' => 'security.settings', 'names' => true],
        'securityReset' => ['action' => 'security.reset_failures', 'names' => true],
        'securityMfa' => ['action' => 'security.mfa_policy', 'fields' => ['policy']],
        'securitySocial' => ['action' => 'security.social', 'names' => true],
        'samlCreate' => ['action' => 'saml_connection.create', 'type' => 'saml_connection', 'label' => 'name'],
        'samlUpdate' => ['action' => 'saml_connection.update', 'type' => 'saml_connection', 'target' => 0],
        'samlDelete' => ['action' => 'saml_connection.delete', 'type' => 'saml_connection', 'target' => 0],
        'consentCreate' => ['action' => 'consent.create', 'type' => 'consent', 'label' => 'name'],
        'consentUpdate' => ['action' => 'consent.update', 'type' => 'consent', 'target' => 0],
        'consentBump' => ['action' => 'consent.ask_everyone_again', 'type' => 'consent', 'target' => 0],
        'consentDelete' => ['action' => 'consent.delete', 'type' => 'consent', 'target' => 0],
        'relation' => ['action' => 'relation', 'type' => '*'],
        'userCreate' => ['action' => 'user.create', 'type' => 'user', 'label' => 'username', 'fields' => ['is_admin']],
        'userResetLock' => ['action' => 'user.reset_failures', 'type' => 'user', 'target' => 0],
        'userLock' => ['action' => 'user.lock', 'type' => 'user', 'target' => 0],
        'userUnlock' => ['action' => 'user.unlock', 'type' => 'user', 'target' => 0],
        'userSendReset' => ['action' => 'user.send_reset', 'type' => 'user', 'target' => 0],
        'userResetMfa' => ['action' => 'user.reset_mfa', 'type' => 'user', 'target' => 0],
        'userAskConsentAgain' => ['action' => 'user.consent_ask_again', 'type' => 'user', 'target' => 0, 'args' => [1 => 'consent']],
        'userUnlinkConnection' => ['action' => 'user.unlink_connection', 'type' => 'user', 'target' => 0, 'args' => [1 => 'provider']],
        'userUpdate' => ['action' => 'user.update', 'type' => 'user', 'target' => 0, 'fields' => ['is_admin']],
        'userDelete' => ['action' => 'user.delete', 'type' => 'user', 'target' => 0],
        'groupCreate' => ['action' => 'group.create', 'type' => 'group', 'label' => 'name'],
        'groupUpdate' => ['action' => 'group.update', 'type' => 'group', 'target' => 0],
        'groupDelete' => ['action' => 'group.delete', 'type' => 'group', 'target' => 0],
        'roleCreate' => ['action' => 'role.create', 'type' => 'role', 'label' => 'name'],
        'roleUpdate' => ['action' => 'role.update', 'type' => 'role', 'target' => 0],
        'roleDelete' => ['action' => 'role.delete', 'type' => 'role', 'target' => 0],
        'clientCreate' => ['action' => 'client.create', 'type' => 'client', 'label' => 'name'],
        'clientUpdate' => ['action' => 'client.update', 'type' => 'client', 'target' => 0],
        'clientAccess' => ['action' => 'client.access', 'type' => 'client', 'target' => 0],
        'clientRegistrar' => ['action' => 'client.registrar', 'type' => 'client', 'target' => 0, 'fields' => ['registrar']],
        'clientRotateSecret' => ['action' => 'client.rotate_secret', 'type' => 'client', 'target' => 0],
        'clientDelete' => ['action' => 'client.delete', 'type' => 'client', 'target' => 0],
    ];

    /** Where a thing's own page is, by the part of the address, for finding the id of what a create made. */
    private const PAGES = ['users' => 'user', 'clients' => 'client', 'groups' => 'group', 'roles' => 'role', 'scim' => 'scim_client', 'saml' => 'saml_connection', 'consents' => 'consent'];

    /** The row key that holds a target's name, by kind. */
    private const NAME_KEY = ['user' => 'username', 'client' => 'name', 'group' => 'name', 'role' => 'name', 'scim_client' => 'name', 'saml_connection' => 'name', 'consent' => 'name'];

    /** @return list<string> every handler that has an entry */
    public static function handlers(): array
    {
        return array_keys(self::ACTIONS);
    }

    /** The kinds of target an action can be about. @return list<string> */
    public static function types(): array
    {
        return array_values(array_unique(array_filter(array_column(self::ACTIONS, 'type'), static fn (string $t): bool => $t !== '*')));
    }

    /**
     * What to record for a handler that is about to run, or null when it is not one that is recorded. Called before the handler, so the name of
     * something it deletes is still there to be copied.
     *
     * @param list<string> $args the captures of the route, decoded
     * @param array<string, mixed> $body what was posted
     * @param callable(string, string): ?array<string, mixed> $find looks up a row by kind and id (null when there is none)
     * @return array{action: string, type: string|null, target: string|null, label: string|null, detail: array<string, mixed>}|null
     */
    public static function plan(string $handler, array $args, array $body, callable $find): ?array
    {
        $spec = self::ACTIONS[$handler] ?? null;
        if ($spec === null) {
            return null;
        }

        $action = $spec['action'];
        $type = $spec['type'] ?? null;
        $detail = [];
        if ($handler === 'relation') {
            [$name, $owner, $operation] = [$args[0] ?? '', $args[1] ?? '', $args[2] ?? ''];
            $type = explode('-', $name)[0];
            $action = 'relation.' . str_replace('-', '_', $operation);
            $args = [$owner];
            $spec['target'] = 0;
            $detail = ['relation' => $name] + (isset($body['item']) && is_scalar($body['item']) ? ['item' => (string) $body['item']] : []);
        }

        $target = isset($spec['target']) ? ($args[$spec['target']] ?? null) : (isset($spec['targetField']) && is_scalar($body[$spec['targetField']] ?? null) ? (string) $body[$spec['targetField']] : null);
        $row = $type !== null && $target !== null ? $find($type, $target) : null;
        $label = $row !== null ? (string) ($row[self::NAME_KEY[$type] ?? ''] ?? '') : (isset($spec['label']) && is_scalar($body[$spec['label']] ?? null) ? (string) $body[$spec['label']] : null);

        foreach ($spec['args'] ?? [] as $index => $name) {
            if (isset($args[$index])) {
                $detail[$name] = $args[$index];
            }
        }
        foreach ($spec['fields'] ?? [] as $field) {
            $detail[$field] = $field === 'is_admin' ? isset($body[$field]) : (is_scalar($body[$field] ?? null) ? (string) $body[$field] : null);
        }
        if ($handler === 'userUpdate' && $row !== null) {
            $detail['was_admin'] = (bool) ($row['is_admin'] ?? false);
        }
        if ($spec['names'] ?? false) {
            $detail['fields'] = array_values(array_filter(array_map('strval', array_keys($body)), static fn (string $k): bool => $k !== '_csrf'));
        }

        return ['action' => $action, 'type' => $type, 'target' => $target, 'label' => $label === '' ? null : $label, 'detail' => $detail];
    }

    /** Whether a response means the change was made: it did not fail and was not a refusal (`done()` flashes those as `error`). */
    public static function succeeded(int $status, ?string $flashType): bool
    {
        return $status < 400 && $flashType !== 'error';
    }

    /**
     * What a create made, from where it sent the browser next (`/admin/users/<id>`), when the plan does not know yet.
     *
     * @return array{string, string}|null the kind and the id
     */
    public static function createdBy(string $location): ?array
    {
        if (preg_match('#^/admin/([a-z]+)/([^/?\#]+)#', $location, $m) !== 1 || !isset(self::PAGES[$m[1]]) || $m[2] === 'new') {
            return null;
        }

        return [self::PAGES[$m[1]], rawurldecode($m[2])];
    }
}

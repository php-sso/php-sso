<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PDO;

/**
 * OAuth clients. Rows are returned with `redirect_uris` and `grants` as arrays
 * and `confidential` as bool.
 */
final class SqliteClients implements \PhpSso\Store\Clients
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    public function all(): array
    {
        return array_map($this->decode(...), $this->pdo->query('SELECT * FROM clients ORDER BY name')->fetchAll());
    }

    /**
     * The clients that may ask for this scope. A client with no list of its own (an old record) may ask for the built-in
     * scopes only, so it never counts for one an administrator added.
     *
     * @return list<array{client_id: string, name: string}>
     */
    public function usingScope(string $scope): array
    {
        $using = [];
        foreach ($this->all() as $client) {
            if (in_array($scope, $client['scopes'] ?? [], true)) {
                $using[] = ['client_id' => (string) $client['client_id'], 'name' => (string) $client['name']];
            }
        }

        return $using;
    }

    /** Clients whose name or ID contains the text; for the admin search. */
    public function search(string $q, int $limit): array
    {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $stmt = $this->pdo->prepare('SELECT client_id, name FROM clients
            WHERE name LIKE :q ESCAPE \'\\\' OR client_id LIKE :q ESCAPE \'\\\'
            ORDER BY name COLLATE NOCASE LIMIT :l');
        $stmt->bindValue(':q', $like);
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function find(string $clientId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE client_id = ?');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch();

        return $row ? $this->decode($row) : null;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    }

    /** @param array{client_id: string, name: string, secret_hash: ?string, confidential: bool, redirect_uris: string[], grants: string[], backchannel_logout_uri?: ?string, post_logout_redirect_uris?: string[], scopes?: ?string[], restricted?: bool} $c */
    public function insert(array $c): void
    {
        $this->pdo->prepare('INSERT INTO clients (client_id, name, secret_hash, confidential, redirect_uris, grants, backchannel_logout_uri, post_logout_redirect_uris, restricted, scopes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $c['client_id'], $c['name'], $c['secret_hash'], (int) $c['confidential'],
            json_encode($c['redirect_uris']), json_encode($c['grants']),
            $c['backchannel_logout_uri'] ?? null, json_encode($c['post_logout_redirect_uris'] ?? []), (int) ($c['restricted'] ?? false),
            isset($c['scopes']) ? json_encode($c['scopes']) : null,
        ]);
    }

    /** @param string[] $redirectUris @param string[] $grants @param string[] $postLogoutUris @param string[] $scopes */
    public function update(string $clientId, string $name, array $redirectUris, array $grants, ?string $backchannelLogoutUri, array $postLogoutUris, array $scopes): void
    {
        $this->pdo->prepare('UPDATE clients SET name = ?, redirect_uris = ?, grants = ?, backchannel_logout_uri = ?, post_logout_redirect_uris = ?, scopes = ? WHERE client_id = ?')
            ->execute([$name, json_encode($redirectUris), json_encode($grants), $backchannelLogoutUri, json_encode($postLogoutUris), json_encode($scopes), $clientId]);
    }

    /**
     * Changes a client's ID and type. Everything that points at the ID moves with it. The ID is not cascaded
     * by the schema, so the foreign key checks wait until the whole change is in place.
     * Tokens already issued name the old ID: access tokens run out on their own, refresh tokens stop working.
     *
     * @param ?string $secretHash the secret hash to store afterwards; null for a public client
     */
    public function change(string $clientId, string $newClientId, bool $confidential, ?string $secretHash): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec('PRAGMA defer_foreign_keys = ON');
            if ($newClientId !== $clientId) {
                foreach (['clients', 'client_group', 'client_user', 'session_clients', 'ip_rules'] as $table) {
                    $this->pdo->prepare("UPDATE {$table} SET client_id = ? WHERE client_id = ?")->execute([$newClientId, $clientId]);
                }
            }
            $this->pdo->prepare('UPDATE clients SET confidential = ?, secret_hash = ? WHERE client_id = ?')
                ->execute([(int) $confidential, $confidential ? $secretHash : null, $newClientId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function updateSecretHash(string $clientId, string $hash): void
    {
        $this->pdo->prepare('UPDATE clients SET secret_hash = ? WHERE client_id = ?')->execute([$hash, $clientId]);
    }

    public function delete(string $clientId): void
    {
        $this->pdo->prepare('DELETE FROM clients WHERE client_id = ?')->execute([$clientId]);
    }

    public function setRestricted(string $clientId, bool $restricted): void
    {
        $this->pdo->prepare('UPDATE clients SET restricted = ? WHERE client_id = ?')->execute([(int) $restricted, $clientId]);
    }

    /**
     * Open clients accept every user. Restricted clients accept the people linked to them directly and
     * the members of the groups linked to them; with nobody linked, nobody gets in.
     */
    public function userMayUse(string $clientId, string $userSub): bool
    {
        $stmt = $this->pdo->prepare('SELECT
            NOT EXISTS (SELECT 1 FROM clients WHERE client_id = :c AND restricted = 1)
            OR EXISTS (SELECT 1 FROM client_user cu WHERE cu.client_id = :c AND cu.user_sub = :u)
            OR EXISTS (SELECT 1 FROM client_group cg
                JOIN user_group_member m ON m.group_id = cg.group_id
                WHERE cg.client_id = :c AND m.user_sub = :u)');
        $stmt->execute([':c' => $clientId, ':u' => $userSub]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Why a person may or may not use a client, for the access tester.
     *
     * @return array{allowed: bool, reason: string}
     */
    public function explainAccess(string $clientId, string $userSub): array
    {
        $stmt = $this->pdo->prepare('SELECT restricted FROM clients WHERE client_id = ?');
        $stmt->execute([$clientId]);
        if (!(int) $stmt->fetchColumn()) {
            return ['allowed' => true, 'reason' => 'The client is open to every user.'];
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM client_user WHERE client_id = ? AND user_sub = ?');
        $stmt->execute([$clientId, $userSub]);
        if ($stmt->fetchColumn()) {
            return ['allowed' => true, 'reason' => 'The client is restricted, and this person is linked to it directly.'];
        }

        $stmt = $this->pdo->prepare('SELECT g.name FROM client_group cg
            JOIN user_group g ON g.id = cg.group_id
            JOIN user_group_member m ON m.group_id = cg.group_id
            WHERE cg.client_id = ? AND m.user_sub = ? ORDER BY g.name');
        $stmt->execute([$clientId, $userSub]);
        $groups = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($groups) {
            return ['allowed' => true, 'reason' => 'The client is restricted, and this person is in ' . implode(', ', $groups) . '.'];
        }

        return ['allowed' => false, 'reason' => 'The client is restricted, and this person is neither linked to it nor in a group that is.'];
    }

    /** Who each restricted client is limited to, for the list page: client id => group names and number of people. */
    public function accessSummary(): array
    {
        $out = [];
        $rows = $this->pdo->query('SELECT cg.client_id, g.name FROM client_group cg JOIN user_group g ON g.id = cg.group_id ORDER BY g.name COLLATE NOCASE')->fetchAll();
        foreach ($rows as $row) {
            $out[$row['client_id']]['groups'][] = $row['name'];
        }
        foreach ($this->pdo->query('SELECT client_id, COUNT(*) AS n FROM client_user GROUP BY client_id')->fetchAll() as $row) {
            $out[$row['client_id']]['people'] = (int) $row['n'];
        }

        return $out;
    }

    private function decode(array $row): array
    {
        $row['confidential'] = (bool) $row['confidential'];
        $row['restricted'] = (bool) $row['restricted'];
        $row['redirect_uris'] = json_decode($row['redirect_uris'], true);
        $row['grants'] = json_decode($row['grants'], true);
        $row['scopes'] = $row['scopes'] === null ? null : json_decode($row['scopes'], true);
        $row['post_logout_redirect_uris'] = json_decode($row['post_logout_redirect_uris'] ?? '[]', true) ?: [];

        return $row;
    }
}

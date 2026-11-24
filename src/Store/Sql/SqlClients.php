<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;

/**
 * OAuth clients. Rows are returned with `redirect_uris` and `grants` as arrays
 * and `confidential` as bool.
 */
final class SqlClients implements \PhpSso\Store\Clients
{
    private PDO $pdo;
    private Dialect $dialect;

    public function __construct(Connection $db)
    {
        $this->pdo = $db->pdo;
        $this->dialect = $db->dialect;
    }

    public function all(): array
    {
        return array_map($this->decode(...), $this->pdo->query('SELECT * FROM clients ORDER BY name')->fetchAll());
    }

    public function page(string $q, int $limit, int $offset): array
    {
        [$where, $params] = $this->matching($q);
        $stmt = $this->pdo->prepare('SELECT * FROM clients' . $where . ' ORDER BY ' . $this->dialect->ci('name') . ', client_id LIMIT :l OFFSET :o');
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':l', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':o', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map($this->decode(...), $stmt->fetchAll());
    }

    public function countMatching(string $q): int
    {
        [$where, $params] = $this->matching($q);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM clients' . $where);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @return array{string, array<string, string>} */
    private function matching(string $q): array
    {
        if (trim($q) === '') {
            return ['', []];
        }

        return [' WHERE (' . $this->dialect->like('name', ':q') . ' OR ' . $this->dialect->like('client_id', ':q') . ')', [':q' => '%' . addcslashes(trim($q), '%_\\') . '%']];
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
            WHERE ' . $this->dialect->like('name', ':q') . ' OR ' . $this->dialect->like('client_id', ':q') . '
            ORDER BY ' . $this->dialect->ci('name') . ' LIMIT :l');
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

    public function saveRegistered(array $c): void
    {
        $existing = $this->find($c['client_id']);
        if ($existing === null) {
            $this->insert($c);
            $this->pdo->prepare('UPDATE clients SET registration = ? WHERE client_id = ?')->execute([json_encode($c['registration']), $c['client_id']]);

            return;
        }
        if ($existing['registration'] === null) {
            throw new \LogicException('Client ' . $c['client_id'] . ' was not registered through the registration endpoint, so that endpoint cannot replace it.');
        }
        $this->pdo->prepare('UPDATE clients SET name = ?, secret_hash = ?, confidential = ?, redirect_uris = ?, grants = ?, backchannel_logout_uri = ?, post_logout_redirect_uris = ?, scopes = ?, registration = ? WHERE client_id = ?')
            ->execute([
                $c['name'], $c['secret_hash'], (int) $c['confidential'], json_encode($c['redirect_uris']), json_encode($c['grants']),
                $c['backchannel_logout_uri'], json_encode($c['post_logout_redirect_uris']), json_encode($c['scopes']), json_encode($c['registration']), $c['client_id'],
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
            $this->dialect->deferForeignKeys($this->pdo);
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
        } finally {
            $this->dialect->restoreForeignKeys($this->pdo);
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

    public function setRegistrar(string $clientId, ?array $scopes, array $resources = []): void
    {
        $this->pdo->prepare('UPDATE clients SET registrar = ? WHERE client_id = ?')
            ->execute([$scopes === null ? null : json_encode(['scopes' => array_values($scopes), 'resources' => array_values($resources)]), $clientId]);
    }

    public function setResources(string $clientId, array $resources): void
    {
        $this->pdo->prepare('UPDATE clients SET resources = ? WHERE client_id = ?')
            ->execute([$resources === [] ? null : json_encode(array_values($resources)), $clientId]);
    }

    public function anyRegistrar(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM clients WHERE registrar IS NOT NULL')->fetchColumn() > 0;
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
    public function accessSummary(array $clientIds = []): array
    {
        $only = $clientIds === [] ? '' : ' WHERE client_id IN (' . implode(', ', array_fill(0, count($clientIds), '?')) . ')';
        $out = [];
        $groups = $this->pdo->prepare('SELECT cg.client_id, g.name FROM client_group cg JOIN user_group g ON g.id = cg.group_id'
            . ($clientIds === [] ? '' : ' WHERE cg.client_id IN (' . implode(', ', array_fill(0, count($clientIds), '?')) . ')') . ' ORDER BY ' . $this->dialect->ci('g.name'));
        $groups->execute($clientIds);
        foreach ($groups->fetchAll() as $row) {
            $out[$row['client_id']]['groups'][] = $row['name'];
        }
        $people = $this->pdo->prepare('SELECT client_id, COUNT(*) AS n FROM client_user' . $only . ' GROUP BY client_id');
        $people->execute($clientIds);
        foreach ($people->fetchAll() as $row) {
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
        $row['registration'] = ($row['registration'] ?? null) === null ? null : json_decode($row['registration'], true);
        $row['registrar'] = ($row['registrar'] ?? null) === null ? null : json_decode($row['registrar'], true);
        $row['resources'] = ($row['resources'] ?? null) === null ? [] : (json_decode($row['resources'], true) ?: []);

        return $row;
    }
}

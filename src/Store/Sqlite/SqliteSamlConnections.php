<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PDO;

/**
 * Company identity providers people can sign in through with SAML. A connection also claims email domains: a person
 * who types an address at one of them is sent straight to that company's provider, and only that provider may vouch
 * for such addresses.
 */
final class SqliteSamlConnections implements \PhpSso\Store\SamlConnections
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    /** @return list<array<string, mixed>> every connection with its `domains` (a list) */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM saml_connections ORDER BY name COLLATE NOCASE, id')->fetchAll();

        return array_map(fn (array $row): array => $row + ['domains' => $this->domainsOf((int) $row['id'])], $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM saml_connections WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? $row + ['domains' => $this->domainsOf($id)] : null;
    }

    /** The switched-on connection that claims this email domain, or null. */
    public function findByDomain(string $domain): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.id FROM saml_connections c JOIN saml_domains d ON d.connection_id = c.id WHERE d.domain = ? AND c.enabled = 1');
        $stmt->execute([strtolower($domain)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->find((int) $id);
    }

    /** The connection that claims this email domain (switched on or not), as an id, or null. */
    public function domainOwner(string $domain): ?int
    {
        $stmt = $this->pdo->prepare('SELECT connection_id FROM saml_domains WHERE domain = ?');
        $stmt->execute([strtolower($domain)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Whether this person's address is at a domain a switched-on connection claims: their company signs them in, so a
     * password here would bypass its two-step verification and its offboarding.
     */
    public function managesAddress(string $email): bool
    {
        $at = strrpos($email, '@');

        return $at !== false && $this->findByDomain(substr($email, $at + 1)) !== null;
    }

    /**
     * @param array{name: string, idp_entity_id: string, idp_sso_url: string, idp_certificate: string, enabled: bool, create_users: bool, link_existing: bool} $data
     * @param list<string> $domains
     */
    public function create(array $data, array $domains): int
    {
        $this->pdo->prepare('INSERT INTO saml_connections (name, idp_entity_id, idp_sso_url, idp_certificate, enabled, create_users, link_existing)
            VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$data['name'], $data['idp_entity_id'], $data['idp_sso_url'], $data['idp_certificate'], (int) $data['enabled'], (int) $data['create_users'], (int) $data['link_existing']]);
        $id = (int) $this->pdo->lastInsertId();
        $this->setDomains($id, $domains);

        return $id;
    }

    /** @param array{name: string, idp_entity_id: string, idp_sso_url: string, idp_certificate: string, enabled: bool, create_users: bool, link_existing: bool} $data @param list<string> $domains */
    public function update(int $id, array $data, array $domains): void
    {
        $this->pdo->prepare('UPDATE saml_connections SET name = ?, idp_entity_id = ?, idp_sso_url = ?, idp_certificate = ?, enabled = ?, create_users = ?, link_existing = ? WHERE id = ?')
            ->execute([$data['name'], $data['idp_entity_id'], $data['idp_sso_url'], $data['idp_certificate'], (int) $data['enabled'], (int) $data['create_users'], (int) $data['link_existing'], $id]);
        $this->setDomains($id, $domains);
    }

    /** Deletes it. People who signed in through it keep their accounts but lose that way in. */
    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM user_identities WHERE provider = ?')->execute(['saml-' . $id]);
        $this->pdo->prepare('DELETE FROM saml_connections WHERE id = ?')->execute([$id]);
    }

    /** @return list<string> */
    public function domainsOf(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT domain FROM saml_domains WHERE connection_id = ? ORDER BY domain');
        $stmt->execute([$id]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** How many people signed in through (are connected to) this connection. */
    public function peopleCount(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_identities WHERE provider = ?');
        $stmt->execute(['saml-' . $id]);

        return (int) $stmt->fetchColumn();
    }

    /** @param list<string> $domains */
    private function setDomains(int $id, array $domains): void
    {
        $this->pdo->prepare('DELETE FROM saml_domains WHERE connection_id = ?')->execute([$id]);
        $insert = $this->pdo->prepare('INSERT INTO saml_domains (domain, connection_id) VALUES (?, ?)');
        foreach (array_unique(array_map('strtolower', $domains)) as $domain) {
            $insert->execute([$domain, $id]);
        }
    }
}

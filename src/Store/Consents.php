<?php

declare(strict_types=1);

namespace PhpSso\Store;

use PhpSso\Database;
use PDO;

/**
 * Notices a person must accept before they are signed in. A consent is a name, a short message and a link to the real
 * text, which lives elsewhere. Its `version` is a counter: bump() asks everyone to accept again, and nothing else does.
 * Editing the message or link never does. Each acceptance keeps a copy of what was shown.
 */
final class Consents
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    /**
     * All consents in the order they are asked, each with `accepted`: how many people accepted the current version.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query('SELECT c.*,
                (SELECT COUNT(DISTINCT a.user_sub) FROM user_consents a
                  WHERE a.consent_id = c.id AND a.version = c.version AND a.withdrawn_at IS NULL) AS accepted
            FROM consents c ORDER BY c.position, c.id')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM consents WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function create(string $name, string $message, ?string $url, bool $enabled): int
    {
        $position = (int) $this->pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM consents')->fetchColumn();
        $this->pdo->prepare('INSERT INTO consents (name, message, url, enabled, position) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, $message, $url, (int) $enabled, $position]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Changes what is shown. The version stays, so nobody is asked again: use bump() for that. */
    public function update(int $id, string $name, string $message, ?string $url, bool $enabled): void
    {
        $this->pdo->prepare('UPDATE consents SET name = ?, message = ?, url = ?, enabled = ? WHERE id = ?')
            ->execute([$name, $message, $url, (int) $enabled, $id]);
    }

    /** Asks everyone to accept again. @return int|null the new version, or null when there is no such consent */
    public function bump(int $id): ?int
    {
        $this->pdo->prepare('UPDATE consents SET version = version + 1 WHERE id = ?')->execute([$id]);

        return $this->find($id)['version'] ?? null;
    }

    /** Whether it was deleted: a consent someone has accepted is kept as evidence (switch it off instead). */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_consents WHERE consent_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            return false;
        }
        $this->pdo->prepare('DELETE FROM consents WHERE id = ?')->execute([$id]);

        return true;
    }

    /**
     * The consents this person still has to accept: switched on, and not accepted (or withdrawn) at the current version.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingFor(string $sub): array
    {
        $stmt = $this->pdo->prepare('SELECT c.* FROM consents c
            WHERE c.enabled = 1 AND NOT EXISTS (
                SELECT 1 FROM user_consents a
                 WHERE a.user_sub = ? AND a.consent_id = c.id AND a.version = c.version AND a.withdrawn_at IS NULL)
            ORDER BY c.position, c.id');
        $stmt->execute([$sub]);

        return $stmt->fetchAll();
    }

    /**
     * Records that the person accepted what they saw. Refused when it changed meanwhile (switched off, or asked again).
     *
     * @param int $version the version on the page they accepted
     */
    public function accept(string $sub, int $consentId, int $version, string $ip, ?int $now = null): bool
    {
        $consent = $this->find($consentId);
        if ($consent === null || !(bool) $consent['enabled'] || (int) $consent['version'] !== $version) {
            return false;
        }
        $this->pdo->prepare('INSERT INTO user_consents (user_sub, consent_id, version, accepted_at, ip, name, message, url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$sub, $consentId, $version, $now ?? time(), $ip, $consent['name'], $consent['message'], $consent['url']]);

        return true;
    }

    /** Asks one person to accept again; what they accepted before stays on record. */
    public function withdraw(string $sub, int $consentId, ?int $now = null): void
    {
        $this->pdo->prepare('UPDATE user_consents SET withdrawn_at = ? WHERE user_sub = ? AND consent_id = ? AND withdrawn_at IS NULL')
            ->execute([$now ?? time(), $sub, $consentId]);
    }

    /**
     * Where one person stands with every consent that is switched on: `accepted` (the current version), `outdated`
     * (an older version, so they will be asked) or `waiting` (never, or withdrawn).
     *
     * @return list<array{id: int, name: string, version: int, state: string, accepted_version: ?int, accepted_at: ?int}>
     */
    public function statusFor(string $sub): array
    {
        $stmt = $this->pdo->prepare('SELECT consent_id, MAX(version) AS version, MAX(accepted_at) AS accepted_at
            FROM user_consents WHERE user_sub = ? AND withdrawn_at IS NULL GROUP BY consent_id');
        $stmt->execute([$sub]);
        $latest = [];
        foreach ($stmt->fetchAll() as $row) {
            $latest[(int) $row['consent_id']] = $row;
        }

        $status = [];
        foreach ($this->all() as $consent) {
            if (!(bool) $consent['enabled']) {
                continue;
            }
            $mine = $latest[(int) $consent['id']] ?? null;
            $status[] = [
                'id' => (int) $consent['id'],
                'name' => (string) $consent['name'],
                'version' => (int) $consent['version'],
                'state' => $mine === null ? 'waiting' : ((int) $mine['version'] === (int) $consent['version'] ? 'accepted' : 'outdated'),
                'accepted_version' => $mine === null ? null : (int) $mine['version'],
                'accepted_at' => $mine === null ? null : (int) $mine['accepted_at'],
            ];
        }

        return $status;
    }
}

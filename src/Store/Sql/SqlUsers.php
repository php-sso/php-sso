<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;

final class SqlUsers implements \PhpSso\Store\Users
{
    private PDO $pdo;
    private Dialect $dialect;

    public function __construct(Connection $db)
    {
        $this->pdo = $db->pdo;
        $this->dialect = $db->dialect;
    }

    /** All users, each with a `groups` list of group names. */
    public function all(): array
    {
        $users = $this->pdo->query('SELECT * FROM users ORDER BY username')->fetchAll();
        foreach ($users as &$user) {
            $user['groups'] = $this->groupNames($user['sub']);
        }

        return $users;
    }

    /** Administrators only, without the per-user group lookup that all() does. */
    public function admins(): array
    {
        return $this->pdo->query('SELECT * FROM users WHERE is_admin = 1 ORDER BY username')->fetchAll();
    }

    /** People whose name, username or email contains the text; for the admin search. */
    public function search(string $q, int $limit): array
    {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $stmt = $this->pdo->prepare('SELECT sub, username, name, email FROM users
            WHERE ' . $this->dialect->like('username', ':q') . ' OR ' . $this->dialect->like('name', ':q') . ' OR ' . $this->dialect->like('email', ':q') . '
            ORDER BY ' . $this->dialect->ci('name') . ' LIMIT :l');
        $stmt->bindValue(':q', $like);
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function find(string $sub): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE sub = ?');
        $stmt->execute([$sub]);

        return $stmt->fetch() ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);

        return $stmt->fetch() ?: null;
    }

    /** The person with this email address (ignoring case), or null when no one or more than one has it. */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE ' . $this->dialect->ci('email') . ' = ' . $this->dialect->ciValue('?') . ' LIMIT 2');
        $stmt->execute([$email]);
        $rows = $stmt->fetchAll();

        return count($rows) === 1 ? $rows[0] : null;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function adminCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
    }

    /** Whether another person (not `$exceptSub`) has this email address, ignoring case. */
    public function emailTaken(string $email, ?string $exceptSub = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE ' . $this->dialect->ci('email') . ' = ' . $this->dialect->ciValue('?') . ' AND sub != ?');
        $stmt->execute([$email, $exceptSub ?? '']);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array{username: string, password: string|null, name: string, email: string, email_verified: bool, is_admin: bool, must_change_password?: bool} $data
     * @return string the new user's `sub`; a null password creates a person with no local password
     */
    public function create(array $data): string
    {
        $sub = self::uuid();
        $this->pdo->prepare('INSERT INTO users (sub, username, password_hash, name, email, email_verified, is_admin, must_change_password)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $sub, $data['username'], $data['password'] === null ? null : password_hash($data['password'], PASSWORD_DEFAULT),
            $data['name'], $data['email'], (int) $data['email_verified'], (int) $data['is_admin'], (int) ($data['must_change_password'] ?? false),
        ]);

        return $sub;
    }

    /** Same fields as create(); an empty password keeps the current one, and a missing `must_change_password` leaves the flag as it is. */
    public function update(string $sub, array $data): void
    {
        $this->pdo->prepare('UPDATE users SET username = ?, name = ?, email = ?, email_verified = ?, is_admin = ? WHERE sub = ?')
            ->execute([$data['username'], $data['name'], $data['email'], (int) $data['email_verified'], (int) $data['is_admin'], $sub]);

        if (array_key_exists('must_change_password', $data)) {
            $this->setMustChangePassword($sub, (bool) $data['must_change_password']);
        }
        if ($data['password'] !== '') {
            $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE sub = ?')
                ->execute([password_hash($data['password'], PASSWORD_DEFAULT), $sub]);
        }
    }

    public function updateProfile(string $sub, string $name, string $email, bool $emailVerified): void
    {
        $this->pdo->prepare('UPDATE users SET name = ?, email = ?, email_verified = ? WHERE sub = ?')
            ->execute([$name, $email, (int) $emailVerified, $sub]);
    }

    /** Locks or unlocks the account by hand. Ending its sessions is up to the caller. */
    public function setLocked(string $sub, bool $locked): void
    {
        $this->pdo->prepare('UPDATE users SET locked_at = ? WHERE sub = ?')->execute([$locked ? time() : null, $sub]);
    }

    /** Requires (or stops requiring) the person to choose a new password at their next sign-in. */
    public function setMustChangePassword(string $sub, bool $must): void
    {
        $this->pdo->prepare('UPDATE users SET must_change_password = ? WHERE sub = ?')->execute([(int) $must, $sub]);
    }

    /** Sets a password the person chose, which also ends any requirement to choose one. */
    public function setPassword(string $sub, string $password): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE sub = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $sub]);
    }

    public function delete(string $sub): void
    {
        $this->pdo->prepare('DELETE FROM users WHERE sub = ?')->execute([$sub]);
    }

    /** @return string[] */
    public function groupNames(string $sub): array
    {
        $stmt = $this->pdo->prepare('SELECT g.name FROM user_group g
            JOIN user_group_member m ON m.group_id = g.id WHERE m.user_sub = ? ORDER BY g.name');
        $stmt->execute([$sub]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return int[] */
    public function groupIds(string $sub): array
    {
        $stmt = $this->pdo->prepare('SELECT group_id FROM user_group_member WHERE user_sub = ?');
        $stmt->execute([$sub]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param int[] $groupIds */
    public function setGroups(string $sub, array $groupIds): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->prepare('DELETE FROM user_group_member WHERE user_sub = ?')->execute([$sub]);
        $insert = $this->pdo->prepare('INSERT INTO user_group_member (user_sub, group_id) VALUES (?, ?)');
        foreach (array_unique($groupIds) as $id) {
            $insert->execute([$sub, $id]);
        }
        $this->pdo->commit();
    }

    /** Random UUID v4. The `sub` claim must never change, so it is independent of the (editable) username. */
    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}

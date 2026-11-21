<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;

/**
 * What an app can ask for. The built-in scopes (openid, profile, email, groups, roles) come from the config because the
 * code behind them is fixed: they release claims. The scopes an administrator adds release no claims: they are only a name
 * that ends up in the access token (`invoices:read`), and whatever receives the token decides what it means. A client gets
 * one only when it is allowed it explicitly.
 */
final class SqlScopes implements \PhpSso\Store\Scopes
{
    /** What the built-in scopes mean, for the admin panel. */
    public const BUILTIN_DESCRIPTIONS = [
        'openid' => 'Signs the person in. Required for OpenID Connect.',
        'profile' => 'Their name.',
        'email' => 'Their email address, and whether it is verified.',
        'groups' => 'The groups they belong to.',
        'roles' => 'Their roles in the app that asks.',
    ];

    private PDO $pdo;
    private Dialect $dialect;

    /** @param list<string> $builtin the names of the built-in scopes, from the config */
    public function __construct(Connection $db, private array $builtin)
    {
        $this->pdo = $db->pdo;
        $this->dialect = $db->dialect;
    }

    /** @return list<string> */
    public function builtinNames(): array
    {
        return $this->builtin;
    }

    /** Every scope the server offers: the built-in ones, then the added ones by name. @return list<string> */
    public function names(): array
    {
        return array_merge($this->builtin, $this->customNames());
    }

    /** @return list<array{name: string, description: string, builtin: bool}> */
    public function all(): array
    {
        $scopes = array_map(static fn (string $name): array => ['name' => $name, 'description' => self::BUILTIN_DESCRIPTIONS[$name] ?? '', 'builtin' => true], $this->builtin);
        foreach ($this->pdo->query('SELECT name, description FROM scopes ORDER BY ' . $this->dialect->ci('name'))->fetchAll() as $row) {
            $scopes[] = ['name' => (string) $row['name'], 'description' => (string) $row['description'], 'builtin' => false];
        }

        return $scopes;
    }

    /** @return array{name: string, description: string, builtin: bool}|null the scope with exactly this name */
    public function find(string $name): ?array
    {
        foreach ($this->all() as $scope) {
            if ($scope['name'] === $name) {
                return $scope;
            }
        }

        return null;
    }

    public function isBuiltin(string $name): bool
    {
        return in_array($name, $this->builtin, true);
    }

    /** Whether the name is taken, ignoring case so that `Invoices:Read` cannot be mistaken for `invoices:read`. */
    public function exists(string $name): bool
    {
        foreach ($this->names() as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function create(string $name, string $description, ?int $now = null): void
    {
        $this->pdo->prepare('INSERT INTO scopes (name, description, created_at) VALUES (?, ?, ?)')->execute([$name, $description, $now ?? time()]);
    }

    /** Only for an added scope. @return bool whether there is one by that name */
    public function updateDescription(string $name, string $description): bool
    {
        $stmt = $this->pdo->prepare('UPDATE scopes SET description = ? WHERE name = ?');
        $stmt->execute([$description, $name]);

        return $stmt->rowCount() > 0;
    }

    /** Only for an added scope; the built-in ones cannot be removed. @return bool whether one was removed */
    public function delete(string $name): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM scopes WHERE name = ?');
        $stmt->execute([$name]);

        return $stmt->rowCount() > 0;
    }

    /** @return list<string> */
    private function customNames(): array
    {
        return array_map('strval', $this->pdo->query('SELECT name FROM scopes ORDER BY ' . $this->dialect->ci('name'))->fetchAll(PDO::FETCH_COLUMN));
    }
}

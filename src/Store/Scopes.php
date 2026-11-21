<?php

declare(strict_types=1);

namespace PhpSso\Store;

/**
 * What an app can ask for. The built-in scopes (openid, profile, email, groups, roles) come from the config because the
 * code behind them is fixed: they release claims. The scopes an administrator adds release no claims: they are only a name
 * that ends up in the access token (`invoices:read`), and whatever receives the token decides what it means. A client gets
 * one only when it is allowed it explicitly.
 */
interface Scopes
{
    /** @return list<string> */
    public function builtinNames(): array;

    /** Every scope the server offers: the built-in ones, then the added ones by name. @return list<string> */
    public function names(): array;

    /** @return list<array{name: string, description: string, builtin: bool}> */
    public function all(): array;

    /** @return array{name: string, description: string, builtin: bool}|null the scope with exactly this name */
    public function find(string $name): ?array;

    public function isBuiltin(string $name): bool;

    /** Whether the name is taken, ignoring case so that `Invoices:Read` cannot be mistaken for `invoices:read`. */
    public function exists(string $name): bool;

    public function create(string $name, string $description, ?int $now = null): void;

    /** Only for an added scope. @return bool whether there is one by that name */
    public function updateDescription(string $name, string $description): bool;

    /** Only for an added scope; the built-in ones cannot be removed. @return bool whether one was removed */
    public function delete(string $name): bool;
}

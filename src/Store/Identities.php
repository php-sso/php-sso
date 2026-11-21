<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** Which accounts at outside providers belong to which person. */
interface Identities
{
    /** @return array{id: int, user_sub: string, provider: string, subject: string, email: ?string, created_at: int, last_login_at: ?int}|null */
    public function find(string $provider, string $subject): ?array;

    /** @return list<array<string, mixed>> */
    public function forUser(string $userSub): array;

    /** Connects the provider's account to the person. A provider account belongs to one person only (the database insists). */
    public function link(string $userSub, string $provider, string $subject, ?string $email): void;

    /** Notes a sign-in, and the email the provider gave this time. */
    public function touch(int $id, ?string $email): void;

    /** Disconnects the person's account at the provider. @return bool whether there was one */
    public function unlink(string $userSub, string $provider): bool;
}

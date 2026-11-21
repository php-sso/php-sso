<?php

declare(strict_types=1);

namespace PhpSso\Store;

/**
 * Notices a person must accept before they are signed in. A consent is a name, a short message and a link to the real
 * text, which lives elsewhere. Its `version` is a counter: bump() asks everyone to accept again, and nothing else does.
 * Editing the message or link never does. Each acceptance keeps a copy of what was shown.
 */
interface Consents
{
    /**
     * All consents in the order they are asked, each with `accepted`: how many people accepted the current version.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array;

    public function find(int $id): ?array;

    public function create(string $name, string $message, ?string $url, bool $enabled): int;

    /** Changes what is shown. The version stays, so nobody is asked again: use bump() for that. */
    public function update(int $id, string $name, string $message, ?string $url, bool $enabled): void;

    /** Asks everyone to accept again. @return int|null the new version, or null when there is no such consent */
    public function bump(int $id): ?int;

    /** Whether it was deleted: a consent someone has accepted is kept as evidence (switch it off instead). */
    public function delete(int $id): bool;

    /**
     * The consents this person still has to accept: switched on, and not accepted (or withdrawn) at the current version.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingFor(string $sub): array;

    /**
     * Records that the person accepted what they saw. Refused when it changed meanwhile (switched off, or asked again).
     *
     * @param int $version the version on the page they accepted
     */
    public function accept(string $sub, int $consentId, int $version, string $ip, ?int $now = null): bool;

    /** Asks one person to accept again; what they accepted before stays on record. */
    public function withdraw(string $sub, int $consentId, ?int $now = null): void;

    /**
     * Where one person stands with every consent that is switched on: `accepted` (the current version), `outdated`
     * (an older version, so they will be asked) or `waiting` (never, or withdrawn).
     *
     * @return list<array{id: int, name: string, version: int, state: string, accepted_version: ?int, accepted_at: ?int}>
     */
    public function statusFor(string $sub): array;
}

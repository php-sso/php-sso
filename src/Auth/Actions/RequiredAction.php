<?php

declare(strict_types=1);

namespace PhpSso\Auth\Actions;

/**
 * Something a person must finish after proving who they are and before they are signed in: choose a new password,
 * and later perhaps accept a notice. The sign-in does not complete while one still applies.
 */
interface RequiredAction
{
    /** Short stable name, for logs and tests. */
    public function key(): string;

    /** @param array<string, mixed> $user the user row */
    public function applies(array $user): bool;

    /**
     * The page that asks for it.
     *
     * @param array<string, mixed> $user
     * @return array{template: string, vars: array<string, mixed>}
     */
    public function page(array $user): array;

    /**
     * Handles the submitted page.
     *
     * @param array<string, mixed> $user
     * @param array<mixed> $body the posted form
     * @param string $ip the person's address, for the record
     * @return string|null what to tell the person when it was not done, or null when it is done
     */
    public function submit(array $user, array $body, string $ip): ?string;
}

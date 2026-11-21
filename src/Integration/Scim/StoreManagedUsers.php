<?php

declare(strict_types=1);

namespace PhpSso\Integration\Scim;

use PhpSso\ScimServer\Contract\ManagedUsers;
use PhpSso\ScimServer\ScimFilter;
use PhpSso\ScimServer\ScimUser;
use PhpSso\ScimServer\UserPage;
use PhpSso\Store\ScimUsers;
use PhpSso\Store\Users;
use PhpSso\Store\UserState;
use PhpSso\Store\DuplicateValue;
use PhpSso\ScimServer\UniquenessViolation;

/** Which people each provisioning client manages, kept in the `scim_users` table next to the application's users. */
final class StoreManagedUsers implements ManagedUsers
{
    public function __construct(private ScimUsers $scimUsers)
    {
    }

    public function find(int $clientId, string $sub): ?ScimUser
    {
        return $this->user($this->scimUsers->find($clientId, $sub));
    }

    public function findByUserName(int $clientId, string $userName): ?ScimUser
    {
        return $this->user($this->scimUsers->findByUserName($clientId, $userName));
    }

    public function findByExternalId(int $clientId, string $externalId): ?ScimUser
    {
        return $this->user($this->scimUsers->findByExternalId($clientId, $externalId));
    }

    public function list(int $clientId, ?ScimFilter $filter, int $start, int $count): UserPage
    {
        $page = $this->scimUsers->list($clientId, $filter === null ? null : ['attr' => $filter->attr, 'value' => $filter->value], $start, $count);

        return new UserPage($page['total'], array_map(fn (array $row): ScimUser => $this->user($row) ?? throw new \LogicException('Unreachable.'), $page['rows']));
    }

    public function attach(int $clientId, string $sub, string $userName, ?string $externalId, ?string $givenName, ?string $familyName): void
    {
        try {
            $this->scimUsers->attach($clientId, $sub, $userName, $externalId, $givenName, $familyName);
        } catch (DuplicateValue $e) {
            throw new UniquenessViolation('A unique value is already taken.', 0, $e);
        }
    }

    public function update(string $sub, string $userName, ?string $externalId, ?string $givenName, ?string $familyName): void
    {
        try {
            $this->scimUsers->update($sub, $userName, $externalId, $givenName, $familyName);
        } catch (DuplicateValue $e) {
            throw new UniquenessViolation('A unique value is already taken.', 0, $e);
        }
    }

    public function isManaged(string $sub): bool
    {
        return $this->scimUsers->managedBy($sub) !== null;
    }

    /** @param array<string, mixed>|null $row */
    private function user(?array $row): ?ScimUser
    {
        if ($row === null) {
            return null;
        }

        return new ScimUser(
            (string) $row['sub'],
            (string) $row['user_name'],
            $row['external_id'] ?? null,
            $row['given_name'] ?? null,
            $row['family_name'] ?? null,
            (string) $row['name'],
            (string) $row['email'],
            !UserState::isLocked($row),
            (bool) $row['is_admin'],
        );
    }
}

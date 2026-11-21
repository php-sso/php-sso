<?php

declare(strict_types=1);

namespace PhpSso\Integration\Scim;

use PhpSso\ScimServer\Contract\ManagedGroups;
use PhpSso\ScimServer\GroupPage;
use PhpSso\ScimServer\ScimFilter;
use PhpSso\ScimServer\ScimGroup;
use PhpSso\Store\ScimGroups;
use PhpSso\Store\DuplicateValue;
use PhpSso\ScimServer\UniquenessViolation;

/** Which groups each provisioning client manages, kept in the `scim_groups` table next to the application's groups. */
final class StoreManagedGroups implements ManagedGroups
{
    public function __construct(private ScimGroups $scimGroups)
    {
    }

    public function find(int $clientId, int $groupId): ?ScimGroup
    {
        return $this->group($this->scimGroups->find($clientId, $groupId));
    }

    public function findByDisplayName(int $clientId, string $displayName): ?ScimGroup
    {
        return $this->group($this->scimGroups->findByDisplayName($clientId, $displayName));
    }

    public function findByExternalId(int $clientId, string $externalId): ?ScimGroup
    {
        return $this->group($this->scimGroups->findByExternalId($clientId, $externalId));
    }

    public function list(int $clientId, ?ScimFilter $filter, int $start, int $count): GroupPage
    {
        $page = $this->scimGroups->list($clientId, $filter === null ? null : ['attr' => $filter->attr, 'value' => $filter->value], $start, $count);

        return new GroupPage($page['total'], array_map(fn (array $row): ScimGroup => $this->group($row) ?? throw new \LogicException('Unreachable.'), $page['rows']));
    }

    public function attach(int $clientId, int $groupId, string $displayName, ?string $externalId): void
    {
        try {
            $this->scimGroups->attach($clientId, $groupId, $displayName, $externalId);
        } catch (DuplicateValue $e) {
            throw new UniquenessViolation('A unique value is already taken.', 0, $e);
        }
    }

    public function update(int $groupId, string $displayName, ?string $externalId): void
    {
        try {
            $this->scimGroups->update($groupId, $displayName, $externalId);
        } catch (DuplicateValue $e) {
            throw new UniquenessViolation('A unique value is already taken.', 0, $e);
        }
    }

    public function isManaged(int $groupId): bool
    {
        return $this->scimGroups->managedBy($groupId) !== null;
    }

    public function members(int $groupId): array
    {
        return $this->scimGroups->members($groupId);
    }

    public function addMembers(int $groupId, array $subs): void
    {
        $this->scimGroups->addMembers($groupId, $subs);
    }

    public function removeMembers(int $groupId, array $subs): void
    {
        $this->scimGroups->removeMembers($groupId, $subs);
    }

    public function replaceMembers(int $groupId, array $subs): void
    {
        $this->scimGroups->replaceMembers($groupId, $subs);
    }

    /** @param array<string, mixed>|null $row */
    private function group(?array $row): ?ScimGroup
    {
        if ($row === null) {
            return null;
        }

        return new ScimGroup((int) $row['id'], (string) $row['name'], (string) $row['description'], (string) $row['display_name'], $row['external_id'] ?? null);
    }
}

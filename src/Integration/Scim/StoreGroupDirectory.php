<?php

declare(strict_types=1);

namespace PhpSso\Integration\Scim;

use PhpSso\ScimServer\Contract\GroupDirectory;
use PhpSso\ScimServer\DirectoryGroup;
use PhpSso\Store\Groups;

/** The application's groups, as the SCIM server sees them. */
final class StoreGroupDirectory implements GroupDirectory
{
    public function __construct(private Groups $groups)
    {
    }

    public function findByName(string $name): ?DirectoryGroup
    {
        $row = $this->groups->findByName($name);

        return $row === null ? null : new DirectoryGroup((int) $row['id'], (string) $row['name'], (string) $row['description']);
    }

    public function create(string $name, string $description): int
    {
        return $this->groups->create($name, $description);
    }

    public function update(int $id, string $name, string $description): void
    {
        $this->groups->update($id, $name, $description);
    }

    public function delete(int $id): void
    {
        $this->groups->delete($id);
    }
}

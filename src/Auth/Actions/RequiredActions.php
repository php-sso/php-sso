<?php

declare(strict_types=1);

namespace PhpSso\Auth\Actions;

/** The things a person may have to finish before being signed in, in the order they are asked. */
final class RequiredActions
{
    /** @param list<RequiredAction> $actions */
    public function __construct(private array $actions)
    {
    }

    /** The first action that still applies to this person, or null when they may be signed in. @param array<string, mixed> $user */
    public function next(array $user): ?RequiredAction
    {
        foreach ($this->actions as $action) {
            if ($action->applies($user)) {
                return $action;
            }
        }

        return null;
    }
}

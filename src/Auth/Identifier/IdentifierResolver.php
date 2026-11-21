<?php

declare(strict_types=1);

namespace PhpSso\Auth\Identifier;

/**
 * What people type to say who they are on the sign-in page (a username, an email address, or either).
 * The sign-in flow only talks to this interface, so another kind of identifier is one new class.
 */
interface IdentifierResolver
{
    /** The input in the form it is stored and compared in (trimmed and lower-cased for an email address). */
    public function normalize(string $input): string;

    /** @return array<string, mixed>|null the user row, or null when nobody matches (or more than one person does) */
    public function find(string $input): ?array;

    /** The domain of an email address in the input (lower-case), or null when the input holds none. */
    public function domain(string $input): ?string;

    /** Text for the label of the sign-in field, for example "Email address". */
    public function label(): string;

    /** The HTML input type for the sign-in field: `text` or `email`. */
    public function inputType(): string;
}

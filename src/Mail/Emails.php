<?php

declare(strict_types=1);

namespace PhpSso\Mail;

use PhpSso\Pages;

/** The messages the server sends, made from the templates (which a theme can replace: see `email_*.twig` in the theming docs). */
final class Emails
{
    public function __construct(private Pages $pages)
    {
    }

    /**
     * The link to choose a new password.
     *
     * @param array<string, mixed> $user the person (a user row)
     */
    public function passwordReset(array $user, string $link, int $minutes, string $ip, bool $byAdmin = false): Email
    {
        return $this->make('password_reset', $user, ['link' => $link, 'minutes' => $minutes, 'ip' => $ip, 'by_admin' => $byAdmin]);
    }

    /**
     * A notice that the password was changed, for the person to notice it if it was not them.
     *
     * @param array<string, mixed> $user
     */
    public function passwordChanged(array $user, string $ip, int $at): Email
    {
        return $this->make('password_changed', $user, ['ip' => $ip, 'at' => gmdate('Y-m-d H:i', $at) . ' UTC']);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $context
     */
    private function make(string $name, array $user, array $context): Email
    {
        $rendered = $this->pages->email($name, $context + ['user' => ['name' => (string) $user['name'], 'username' => (string) $user['username'], 'email' => (string) $user['email']]]);

        return new Email((string) $user['email'], $rendered['subject'], $rendered['text'], $rendered['html'], (string) $user['name']);
    }
}

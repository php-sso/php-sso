<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Mfa\Mfa;

use PhpSso\Auth\Mfa\Mfa;
use PhpSso\Auth\Mfa\SecretBox;
use PhpSso\Store\MfaFactors;
use PhpSso\Store\Settings;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlMfaFactors;
use PhpSso\Store\Sql\SqlSettings;

/** The two-step service on an in-memory database with a throwaway key file. */
final class Support
{
    public readonly TestData $data;
    public readonly MfaFactors $factors;
    public readonly Settings $settings;
    public readonly Mfa $mfa;
    private string $keyFile;

    public function __construct(string $defaultPolicy = 'optional')
    {
        $this->data = new TestData();
        $this->factors = new SqlMfaFactors($this->data->db);
        $this->settings = new SqlSettings($this->data->db);
        $this->keyFile = sys_get_temp_dir() . '/sso-mfa-test-' . bin2hex(random_bytes(4)) . '.key';
        $this->mfa = new Mfa($this->factors, new SecretBox($this->keyFile), $this->settings, 'Test SSO', $defaultPolicy);
    }

    public function __destruct()
    {
        @unlink($this->keyFile);
    }

    /** A person with the authenticator app set up. @return array{0: array<string, mixed>, 1: string, 2: string[]} the user row, the secret and the recovery codes */
    public function enrolledUser(string $username = 'alice', bool $admin = false, ?int $now = null): array
    {
        $sub = $this->data->users->create(['username' => $username, 'password' => 'secret-pass', 'name' => ucfirst($username), 'email' => $username . '@example.com', 'email_verified' => true, 'is_admin' => $admin]);
        $user = $this->data->users->find($sub);
        $secret = $this->mfa->newSecret();
        $codes = $this->mfa->enroll($user, $secret, \PhpSso\Auth\Mfa\Totp::code($secret, intdiv($now ?? time(), 30)), $now);

        return [$user, $secret, $codes];
    }
}

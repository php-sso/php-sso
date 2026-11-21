<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Impersonation;

use PhpSso\Auth\Impersonation;
use PhpSso\Store\Sessions;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlSessions;

/** An administrator who is signed in, someone to browse as, and the service under test. */
final class Support
{
    public readonly TestData $data;
    public readonly Sessions $sessions;
    public readonly Impersonation $impersonation;
    public readonly string $adminSub;
    public readonly string $adminSid;

    public function __construct()
    {
        $this->data = new TestData();
        $this->sessions = new SqlSessions($this->data->db, 3600);
        $this->impersonation = new Impersonation($this->sessions, $this->data->users);
        $this->adminSub = $this->data->users->create(['username' => 'root', 'password' => 'secret-pass', 'name' => 'Root', 'email' => 'root@example.com', 'email_verified' => true, 'is_admin' => true]);
        $this->adminSid = $this->sessions->start($this->adminSub, '203.0.113.1', 'agent');
        $_SESSION = ['user' => $this->adminSub, 'sid' => $this->adminSid];
    }

    /** @return array<string, mixed> */
    public function admin(): array
    {
        return $this->data->users->find($this->adminSub);
    }
}

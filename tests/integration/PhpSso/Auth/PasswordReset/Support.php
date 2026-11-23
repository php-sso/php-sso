<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\PasswordReset;

use Closure;
use PhpSso\Audit\Audit;
use PhpSso\Auth\PasswordReset;
use PhpSso\Mail\Email;
use PhpSso\Mail\Emails;
use PhpSso\Pages;
use PhpSso\Store\Sql\SqlPasswordResets;
use PhpSso\Store\Sql\SqlSessions;
use Tests\unit\PhpSso\Support\InMemoryAuditLog;
use Tests\unit\PhpSso\Support\InMemoryMailer;
use Tests\integration\PhpSso\Support\TestData;

/** The password reset on real stores, with a mailer that keeps what it is given and "after the answer" work that waits until the test says the answer has gone. */
final class Support
{
    public readonly TestData $data;
    public readonly SqlPasswordResets $resets;
    public readonly SqlSessions $sessions;
    public readonly InMemoryMailer $mailer;
    public readonly InMemoryAuditLog $audit;
    public readonly PasswordReset $service;
    public readonly Pages $pages;

    /** @var list<list<string>> what the failed sign-in forgetter was asked: the account id and the names */
    public array $cleared = [];

    /** @var list<Closure> */
    private array $deferred = [];

    public function __construct(int $minutes = 60, bool $failingMail = false)
    {
        $this->data = new TestData();
        $this->resets = new SqlPasswordResets($this->data->db);
        $this->sessions = new SqlSessions($this->data->db, 3600);
        $this->mailer = new InMemoryMailer($failingMail);
        $this->audit = new InMemoryAuditLog();
        $root = dirname(__DIR__, 5);
        $this->pages = new Pages($root . '/templates/pages', '/nonexistent', sys_get_temp_dir() . '/sso-reset-test-' . getmypid(), $root . '/public', ['app_name' => 'Acme SSO'], true);
        $this->service = new PasswordReset(
            $this->data->users,
            $this->resets,
            $this->sessions,
            function (string $sub, string ...$names): int {
                $this->cleared[] = [$sub, ...$names];

                return 0;
            },
            $this->mailer,
            new Emails($this->pages),
            new Audit($this->audit),
            'https://sso.example.com',
            $minutes,
            function (Closure $work): void {
                $this->deferred[] = $work;
            },
        );
    }

    /** The answer has been sent: the work that waited for it is done now. */
    public function answerSent(): void
    {
        $work = $this->deferred;
        $this->deferred = [];
        foreach ($work as $do) {
            $do();
        }
    }

    public function tokenIn(Email $email): string
    {
        preg_match('#https://sso\.example\.com/password/reset/([A-Za-z0-9_-]{43})#', $email->text, $m);

        return $m[1] ?? '';
    }

    /** Asks for a link as the person and returns the token from the email. */
    public function link(string $typed = 'alice', string $ip = '203.0.113.5'): string
    {
        $this->service->request($typed, $ip);
        $this->answerSent();

        return $this->tokenIn($this->mailer->sent[array_key_last($this->mailer->sent)]);
    }
}

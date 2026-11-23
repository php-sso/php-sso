<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Account\AccountController;

use PhpSso\Account\AccountController;
use PhpSso\Auth\UserRules;
use PhpSso\Net\ClientIp;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Tests\integration\PhpSso\Auth\LoginController\Support as LoginSupport;

/** The account page controller on top of the sign-in test fixtures. */
final class Support
{
    public readonly LoginSupport $login;
    public readonly \Tests\integration\PhpSso\Auth\Social\SocialLogin\Support $social;
    public readonly AccountController $controller;
    public readonly \Tests\unit\PhpSso\Support\InMemoryAuditLog $audit;

    public function __construct(string $mfaPolicy = 'optional')
    {
        $this->login = new LoginSupport([], $mfaPolicy);
        $this->social = new \Tests\integration\PhpSso\Auth\Social\SocialLogin\Support([], $this->login->data);
        $root = dirname(__DIR__, 5);
        $pages = new \PhpSso\Pages($root . '/templates/pages', '/nonexistent', sys_get_temp_dir() . '/sso-account-test-' . getmypid(), $root . '/public', ['app_name' => 'SSO'], true);
        $this->controller = new AccountController(
            $pages,
            $this->login->data->users,
            $this->login->sessions,
            new UserRules($this->login->data->users, 'username'),
            $this->login->mfa,
            new \PhpSso\Auth\Mfa\MfaChallenge($this->login->mfa, $this->login->throttle, $this->login->attempts),
            new ClientIp([]),
            $this->social->social,
            new \PhpSso\Audit\Audit($this->audit = new \Tests\unit\PhpSso\Support\InMemoryAuditLog()),
        );
        $_SESSION = ['csrf' => 'token'];
    }

    /** @param array<string, string> $body */
    public static function post(string $path, array $body = []): ServerRequestInterface
    {
        return (new ServerRequest(['REMOTE_ADDR' => '203.0.113.9'], [], 'http://localhost' . $path, 'POST'))->withParsedBody($body + ['_csrf' => 'token']);
    }

    public static function get(string $path): ServerRequestInterface
    {
        return new ServerRequest(['REMOTE_ADDR' => '203.0.113.9'], [], 'http://localhost' . $path, 'GET');
    }
}

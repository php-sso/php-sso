<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Pages;

use PhpSso\Pages;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Pages::class, 'render')]
final class RenderTest extends TestCase
{
    private string $theme;

    protected function setUp(): void
    {
        $this->theme = sys_get_temp_dir() . '/sso-theme-' . bin2hex(random_bytes(4));
        mkdir($this->theme . '/templates', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->theme . '/templates/logout.html.twig');
        @rmdir($this->theme . '/templates');
        @rmdir($this->theme);
    }

    public function testTheBuiltInSignOutPageAsksAndPostsTheFormToken(): void
    {
        // Arrange
        $pages = $this->pages();

        // Act
        $html = (string) $pages->render('logout', ['user' => ['name' => 'Zed', 'username' => 'zed', 'email' => 'z@example.com', 'is_admin' => false], 'csrf' => 'tok123'])->getBody();

        // Assert
        $this->assertStringContainsString('<form method="post" action="/logout"', $html);
        $this->assertStringContainsString('name="_csrf" value="tok123"', $html);
    }

    public function testAThemeCanReplaceTheSignOutPage(): void
    {
        // Arrange
        file_put_contents($this->theme . '/templates/logout.html.twig', 'THEMED {{ user.name }} {{ csrf }}');
        $pages = $this->pages();

        // Act
        $html = (string) $pages->render('logout', ['user' => ['name' => 'Zed', 'username' => 'zed', 'email' => 'z@example.com', 'is_admin' => false], 'csrf' => 'tok123'])->getBody();

        // Assert
        $this->assertSame('THEMED Zed tok123', $html);
    }

    private function pages(): Pages
    {
        return new Pages(dirname(__DIR__, 4) . '/templates/pages', $this->theme, sys_get_temp_dir() . '/sso-cache-' . bin2hex(random_bytes(4)), dirname(__DIR__, 4) . '/public', ['app_name' => 'Test'], false);
    }
}

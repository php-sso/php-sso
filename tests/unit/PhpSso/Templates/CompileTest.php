<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Templates;

use PhpSso\Admin\AdminTwigExtension;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/** A template with a syntax error only fails when someone opens that page, so every one is compiled here. */
#[CoversNothing]
final class CompileTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function admin(): iterable
    {
        $root = dirname(__DIR__, 4) . '/templates';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/admin', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.twig')) {
                $name = substr($file->getPathname(), strlen($root) + 1);
                yield $name => [$name];
            }
        }
    }

    #[DataProvider('admin')]
    public function testEveryAdminTemplateCompiles(string $name): void
    {
        // Arrange
        $root = dirname(__DIR__, 4);
        $twig = new Environment(new FilesystemLoader($root . '/templates'), ['strict_variables' => true]);
        $twig->addExtension(new AdminTwigExtension($root . '/public'));

        // Act
        $template = $twig->load($name);

        // Assert
        $this->assertSame($name, $template->getTemplateName());
    }

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        $root = dirname(__DIR__, 4) . '/templates/pages';
        foreach (glob($root . '/*.twig') ?: [] as $file) {
            yield basename($file) => [basename($file)];
        }
    }

    #[DataProvider('pages')]
    public function testEveryPageTemplateCompiles(string $name): void
    {
        // Arrange
        $root = dirname(__DIR__, 4);
        $twig = new Environment(new FilesystemLoader($root . '/templates/pages'), ['strict_variables' => true]);
        $twig->addExtension(new AdminTwigExtension($root . '/public'));
        $twig->addFunction(new TwigFunction('theme_asset', static fn (string $path): string => $path)); // Pages adds these two
        $twig->addFunction(new TwigFunction('has_theme_asset', static fn (string $path): bool => false));

        // Act
        $template = $twig->load($name);

        // Assert
        $this->assertSame($name, $template->getTemplateName());
    }
}

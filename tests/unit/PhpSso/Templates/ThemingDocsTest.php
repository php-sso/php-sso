<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Templates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/theming.md is what theme authors rely on, and the app skeleton copies it. A page or block that is added without
 * the docs noticing is invisible to them, so these checks fail the build when the docs, the pages and the skeleton drift.
 */
#[CoversNothing]
final class ThemingDocsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function docs(): string
    {
        return (string) file_get_contents(self::root() . '/docs/theming.md');
    }

    /** @return string[] page templates a theme may replace (files that start with an underscore are helpers) */
    private static function pageFiles(): array
    {
        return array_values(array_filter(
            glob(self::root() . '/templates/pages/*.twig') ?: [],
            static fn (string $file): bool => !str_starts_with(basename($file), '_'),
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        foreach (self::pageFiles() as $file) {
            yield basename($file) => [basename($file)];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function blocks(): iterable
    {
        $names = [];
        foreach (self::pageFiles() as $file) {
            preg_match_all('/\{%\s*block\s+(\w+)/', (string) file_get_contents($file), $found);
            $names = [...$names, ...$found[1]];
        }
        foreach (array_unique($names) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('pages')]
    public function testEveryThemablePageIsListedInTheDocs(string $page): void
    {
        // Arrange
        $docs = self::docs();

        // Act
        $listed = str_contains($docs, $page);

        // Assert
        $this->assertTrue($listed, "docs/theming.md does not list {$page}");
    }

    #[DataProvider('blocks')]
    public function testEveryBlockIsListedInTheDocs(string $block): void
    {
        // Arrange
        $docs = self::docs();

        // Act
        $listed = str_contains($docs, '`' . $block . '`');

        // Assert
        $this->assertTrue($listed, "docs/theming.md does not mention the block `{$block}`");
    }

    public function testSkeletonThemeReadmeIsACopyOfTheDocs(): void
    {
        // Arrange
        $copy = dirname(self::root()) . '/app-skeleton/theme/README.md';
        if (!is_file($copy)) {
            $this->markTestSkipped('The app-skeleton checkout is not next to this one.');
        }

        // Act
        $same = file_get_contents($copy) === self::docs();

        // Assert
        $this->assertTrue($same, 'app-skeleton/theme/README.md differs from docs/theming.md: copy the docs over it.');
    }

    /** @return iterable<string, array{string}> */
    public static function skeletonTemplates(): iterable
    {
        $files = glob(dirname(self::root()) . '/app-skeleton/theme/templates/*.twig') ?: [];
        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
        if ($files === []) {
            yield 'no app-skeleton checkout' => ['']; // an empty data set is an error, so say why there is nothing to check
        }
    }

    #[DataProvider('skeletonTemplates')]
    public function testSkeletonTemplatesOnlyOverrideBlocksThatExist(string $file): void
    {
        // Arrange
        if ($file === '') {
            $this->markTestSkipped('The app-skeleton checkout is not next to this one.');
        }
        $source = (string) file_get_contents($file);
        preg_match("/\{%\s*extends\s+'@sso\/([\w.]+)'/", $source, $parent);
        preg_match_all('/\{%\s*block\s+(\w+)/', $source, $overridden);
        $parentFile = self::root() . '/templates/pages/' . ($parent[1] ?? basename($file));
        $parentBlocks = [];
        if (is_file($parentFile)) {
            preg_match_all('/\{%\s*block\s+(\w+)/', (string) file_get_contents($parentFile), $found);
            $parentBlocks = $found[1];
        }

        // Act
        $missing = array_values(array_diff($overridden[1], $parentBlocks));

        // Assert
        $this->assertNotEmpty($parent, basename($file) . ' must extend the built-in page with @sso/, or it replaces it entirely');
        $this->assertFileExists($parentFile);
        $this->assertSame([], $missing, basename($file) . ' overrides blocks that do not exist in ' . basename($parentFile));
    }
}

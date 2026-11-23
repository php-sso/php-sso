<?php

declare(strict_types=1);

namespace Tests\unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A unit test never talks to a database, a local server or a mailbox: that makes it an integration test, and it goes in
 * tests/integration. This keeps the unit suite fast and keeps it from drifting.
 */
#[CoversNothing]
final class SuiteRulesTest extends TestCase
{
    private const FORBIDDEN = [
        'Store\\Sql' => 'the SQL stores',
        'Sql[A-Z]\w+' => 'a SQL store class',
        'TestData' => 'the database fixture',
        'TestConnection' => 'a database connection',
        '\bPDO\b' => 'PDO',
        'Migrator' => 'the migrator',
        'Tests\\\\integration' => 'the integration suite',
        'proc_open' => 'a local server',
        'fsockopen' => 'a network connection',
        'SSO_TEST_(DATABASE|SMTP|MAILPIT)' => 'a test service',
    ];

    /** @return iterable<string, array{string}> */
    public static function files(): iterable
    {
        $root = dirname(__DIR__);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/unit', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && $file->getFilename() !== 'SuiteRulesTest.php') {
                yield substr($file->getPathname(), strlen($root) + 1) => [$file->getPathname()];
            }
        }
    }

    #[DataProvider('files')]
    public function testAUnitTestFileDoesNotTouchADatabaseTheNetworkOrTheIntegrationSuite(string $path): void
    {
        // Arrange
        $source = (string) file_get_contents($path);
        $found = [];

        // Act
        foreach (self::FORBIDDEN as $pattern => $what) {
            if (preg_match('/' . $pattern . '/', $source) === 1) {
                $found[] = $what;
            }
        }

        // Assert
        $this->assertSame([], $found, 'This file belongs in tests/integration: it uses ' . implode(', ', $found) . '.');
    }
}

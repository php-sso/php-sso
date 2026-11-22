<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Backup\Exporter;

use PharData;
use PhpSso\Backup\Exporter;
use PhpSso\Keys;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use Tests\unit\PhpSso\Support\UsesBackupFiles;

#[CoversMethod(Exporter::class, 'export')]
final class ExportTest extends TestCase
{
    use UsesBackupFiles;

    /** @return array<string, string> the files of an archive, by path inside it */
    private function contents(string $archive): array
    {
        $out = $this->work . '/read-' . bin2hex(random_bytes(3));
        (new PharData($archive))->extractTo($out);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($out, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr($file->getPathname(), strlen($out) + 1)] = (string) file_get_contents($file->getPathname());
        }

        return $files;
    }

    public function testItWritesTheRowsOfEveryTableAndAManifestWithTheCounts(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $data->group('staff', [$sub]);
        $data->client('app');
        $target = $this->work . '/backup.tar.gz';

        // Act
        $result = (new Exporter($data->db, $this->work . '/keys'))->export($target, 1_800_000_000);

        // Assert
        $files = $this->contents($target);
        $manifest = json_decode($files['manifest.json'], true);
        $this->assertSame(1, $manifest['tables']['users']);
        $this->assertSame(1, $manifest['tables']['user_group_member']);
        $this->assertSame(1_800_000_000, $manifest['createdAt']);
        $this->assertSame('alice', json_decode(trim($files['database/users.jsonl']), true)['username']);
        $this->assertSame(array_sum($manifest['tables']), $result['rows']);
        $this->assertArrayHasKey('database/oauth_codes.jsonl', $files);
    }

    public function testTheArchiveIsReadableOnlyByItsOwner(): void
    {
        // Arrange
        $data = new TestData();
        $target = $this->work . '/backup.tar.gz';

        // Act
        (new Exporter($data->db, $this->work . '/keys'))->export($target);

        // Assert
        $this->assertSame(0600, fileperms($target) & 0777);
    }

    public function testItIncludesTheKeyFilesAndNothingElseFromTheKeyFolder(): void
    {
        // Arrange
        $data = new TestData();
        $keys = $this->work . '/keys';
        (new Keys($keys))->rotate(60);
        file_put_contents($keys . '/notes.txt', 'not a key');
        $target = $this->work . '/backup.tar.gz';

        // Act
        $result = (new Exporter($data->db, $keys))->export($target);

        // Assert
        $names = array_keys($this->contents($target));
        $this->assertContains('keys/private.pem', $names);
        $this->assertContains('keys/public.pem', $names);
        $this->assertCount(2, array_filter($names, static fn (string $n): bool => str_starts_with($n, 'keys/ring/')));
        $this->assertNotContains('keys/notes.txt', $names);
        $this->assertNotContains('keys/.lock', $names);
        $this->assertSame(4, $result['keyFiles']);
    }

    public function testItNeverOverwritesAnExistingBackup(): void
    {
        // Arrange
        $data = new TestData();
        $target = $this->work . '/backup.tar.gz';
        file_put_contents($target, 'earlier');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('never overwrites');

        // Act
        (new Exporter($data->db, $this->work . '/keys'))->export($target);

        // Assert (the exception above)
    }

    public function testItRefusesAFileNameThatIsNotATarGz(): void
    {
        // Arrange
        $data = new TestData();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('.tar.gz');

        // Act
        (new Exporter($data->db, $this->work . '/keys'))->export($this->work . '/backup.zip');

        // Assert (the exception above)
    }

    public function testItLeavesNoTemporaryFilesBehind(): void
    {
        // Arrange
        $data = new TestData();
        mkdir($this->work . '/tmp');

        // Act
        (new Exporter($data->db, $this->work . '/keys', $this->work . '/tmp'))->export($this->work . '/backup.tar.gz');

        // Assert
        $this->assertSame([], array_values(array_diff(scandir($this->work . '/tmp'), ['.', '..'])));
        $this->assertSame(['backup.tar.gz'], array_values(array_diff(scandir($this->work), ['.', '..', 'keys', 'tmp'])));
    }
}

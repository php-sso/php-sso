<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Backup\Importer;

use Phar;
use PharData;
use PhpSso\Backup\Exporter;
use PhpSso\Backup\Importer;
use PhpSso\Keys;
use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\SqlStorage;
use PhpSso\Store\StorageOptions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;
use Tests\unit\PhpSso\Support\UsesBackupFiles;

#[CoversMethod(Importer::class, 'import')]
final class ImportTest extends TestCase
{
    use UsesBackupFiles;

    /** A SQLite database with a bit of everything, and its backup (with key files). @return array{Connection, string} */
    private function source(): array
    {
        $db = Connection::sqlite(':memory:');
        $storage = new SqlStorage($db, new StorageOptions(3600, ['openid', 'profile']));
        $sub = $storage->users()->create(['username' => 'alice', 'password' => 'secret-pass', 'name' => 'Alice', 'email' => 'alice@example.com', 'email_verified' => true, 'is_admin' => false]);
        $first = $storage->groups()->create('Staff', 'Everyone');
        $storage->groups()->create('Admins', '');
        $storage->users()->setGroups($sub, [$first]);
        $storage->clients()->insert(['client_id' => 'app', 'name' => 'App', 'secret_hash' => null, 'confidential' => false, 'redirect_uris' => ['http://localhost/cb'],
            'grants' => ['authorization_code'], 'backchannel_logout_uri' => null, 'post_logout_redirect_uris' => []]);
        $storage->roles()->create('editor', 'May edit', false, ['app']);
        $storage->settings()->set('mfa.policy', 'admins');
        $storage->loginAttempts()->record('alice', $sub, true, '127.0.0.1', 'test');
        (new Keys($this->work . '/source-keys'))->rotate(60);

        $archive = $this->work . '/source.tar.gz';
        (new Exporter($db, $this->work . '/source-keys'))->export($archive);

        return [$db, $archive];
    }

    /** Rebuilds an archive after `$change` has altered its extracted folder. */
    private function tamper(string $archive, callable $change): string
    {
        $dir = $this->work . '/tamper-' . bin2hex(random_bytes(3));
        (new PharData($archive))->extractTo($dir);
        $change($dir);
        $tar = $dir . '.tar';
        $built = new PharData($tar);
        $built->buildFromDirectory($dir);
        unset($built);
        // Plain gzip, not PharData::compress(): before PHP 8.4 the same process then reads the .tar.gz back empty.
        copy($tar, 'compress.zlib://' . $tar . '.gz');
        Phar::unlinkArchive($tar);

        return $tar . '.gz';
    }

    public function testItRestoresEveryRowFromAnotherKindOfDatabaseIntoAnEmptyOne(): void
    {
        // Arrange
        [$source, $archive] = $this->source();
        $target = TestConnection::open();

        // Act
        $result = (new Importer($target, $this->work . '/keys'))->import($archive);

        // Assert
        $this->assertSame($this->everything($source), $this->everything($target));
        $this->assertGreaterThan(8, $result['rows']);
    }

    public function testItReplacesWhatIsThereWhenToldTo(): void
    {
        // Arrange
        [$source, $archive] = $this->source();
        $target = TestConnection::open();
        (new Importer($target, $this->work . '/keys-one'))->import($archive);
        $target->pdo->exec('DELETE FROM user_group_member');
        $target->pdo->exec("DELETE FROM users WHERE username = 'alice'");
        $target->pdo->exec("INSERT INTO scopes (name, description, created_at) VALUES ('stray', '', 1)");

        // Act
        (new Importer($target, $this->work . '/keys-two'))->import($archive, true);

        // Assert
        $this->assertSame($this->everything($source), $this->everything($target));
    }

    public function testItRefusesADatabaseThatHasPeopleInItUnlessToldToReplaceThem(): void
    {
        // Arrange
        [, $archive] = $this->source();
        $target = TestConnection::open();
        (new Importer($target, $this->work . '/keys'))->import($archive, false, false);
        $before = $this->everything($target);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already has people');

        // Act
        try {
            (new Importer($target, $this->work . '/keys'))->import($archive, false, false);
        } finally {
            // Assert (also: nothing changed)
            $this->assertSame($before, $this->everything($target));
        }
    }

    public function testItWritesTheKeyFilesWithTheRightModes(): void
    {
        // Arrange
        [, $archive] = $this->source();
        $target = TestConnection::open();
        $keys = $this->work . '/restored-keys';

        // Act
        $result = (new Importer($target, $keys))->import($archive);

        // Assert
        $this->assertSame(4, $result['keyFiles']);
        $this->assertSame(0600, fileperms($keys . '/private.pem') & 0777);
        $this->assertSame(0644, fileperms($keys . '/public.pem') & 0777);
        $this->assertSame(0700, fileperms($keys) & 0777);
        $this->assertCount(1, glob($keys . '/ring/*.key'));
        $this->assertSame(0600, fileperms(glob($keys . '/ring/*.key')[0]) & 0777);
        $this->assertSame((new Keys($this->work . '/source-keys'))->provider->signingKey()->kid, (new Keys($keys))->provider->signingKey()->kid);
    }

    public function testItLeavesTheKeysAloneWhenToldToRestoreOnlyTheData(): void
    {
        // Arrange
        [, $archive] = $this->source();
        $target = TestConnection::open();
        $keys = $this->work . '/restored-keys';

        // Act
        $result = (new Importer($target, $keys))->import($archive, false, false);

        // Assert
        $this->assertSame(0, $result['keyFiles']);
        $this->assertDirectoryDoesNotExist($keys);
    }

    public function testItRefusesAKeyFolderThatHasKeysUnlessToldToReplaceIt(): void
    {
        // Arrange
        [, $archive] = $this->source();
        $target = TestConnection::open();
        new Keys($this->work . '/has-keys');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already has signing keys');

        // Act
        (new Importer($target, $this->work . '/has-keys'))->import($archive);

        // Assert (the exception above)
    }

    public function testItMovesTheExistingKeysAsideWhenReplacing(): void
    {
        // Arrange
        [, $archive] = $this->source();
        $target = TestConnection::open();
        $keys = $this->work . '/has-keys';
        $oldKid = (new Keys($keys))->provider->signingKey()->kid;

        // Act
        (new Importer($target, $keys))->import($archive, true);

        // Assert
        $aside = glob($keys . '.before-restore-*');
        $this->assertCount(1, $aside);
        $this->assertSame($oldKid, (new Keys($aside[0]))->provider->signingKey()->kid);
        $this->assertNotSame($oldKid, (new Keys($keys))->provider->signingKey()->kid);
    }

    public function testItNeverWritesAFileTheArchiveNamesOutsideTheKnownKeyFiles(): void
    {
        // Arrange
        [, $archive] = $this->source();
        $evil = $this->tamper($archive, function (string $dir): void {
            $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
            $manifest['keys'] = ['../outside.pem', 'ring/../../outside2.pem', 'notes.txt', ...$manifest['keys']];
            file_put_contents($dir . '/manifest.json', json_encode($manifest));
            file_put_contents($dir . '/keys/notes.txt', 'x');
        });
        $target = TestConnection::open();
        $keys = $this->work . '/restored-keys';

        // Act
        $result = (new Importer($target, $keys))->import($evil);

        // Assert
        $this->assertSame(4, $result['keyFiles']);
        $this->assertFileDoesNotExist($this->work . '/outside.pem');
        $this->assertFileDoesNotExist($this->work . '/outside2.pem');
        $this->assertFileDoesNotExist($keys . '/notes.txt');
    }

    public function testItRefusesABackupWithATableItDoesNotKnow(): void
    {
        // Arrange
        [, $archive] = $this->source();
        $other = $this->tamper($archive, function (string $dir): void {
            $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
            $manifest['tables']['from_the_future'] = 0;
            file_put_contents($dir . '/manifest.json', json_encode($manifest));
        });
        $target = TestConnection::open();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('from_the_future');

        // Act
        (new Importer($target, $this->work . '/keys'))->import($other);

        // Assert (the exception above)
    }

    public function testItRefusesAColumnTheDatabaseDoesNotHave(): void
    {
        // Arrange
        [, $archive] = $this->source();
        $other = $this->tamper($archive, function (string $dir): void {
            $row = json_decode(trim((string) file_get_contents($dir . '/database/users.jsonl')), true);
            $row['shoe_size'] = 42;
            file_put_contents($dir . '/database/users.jsonl', json_encode($row) . "\n");
        });
        $target = TestConnection::open();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shoe_size');

        // Act
        (new Importer($target, $this->work . '/keys'))->import($other, false, false);

        // Assert (the exception above)
    }

    public function testADamagedBackupChangesNothing(): void
    {
        // Arrange
        [, $archive] = $this->source();
        $target = TestConnection::open();
        (new Importer($target, $this->work . '/keys'))->import($archive, false, false);
        $before = $this->everything($target);
        $damaged = $this->tamper($archive, function (string $dir): void {
            file_put_contents($dir . '/database/user_group.jsonl', '');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('damaged');

        // Act
        try {
            (new Importer($target, $this->work . '/keys-2'))->import($damaged, true, false);
        } finally {
            // Assert (also: the replace was rolled back)
            $this->assertSame($before, $this->everything($target));
        }
    }

    public function testNewRowsGetNewIdsAfterARestore(): void
    {
        // Arrange
        [, $archive] = $this->source();
        $target = TestConnection::open();
        (new Importer($target, $this->work . '/keys'))->import($archive, false, false);
        $highest = (int) $target->pdo->query('SELECT MAX(id) FROM user_group')->fetchColumn();
        $storage = new SqlStorage($target, new StorageOptions(3600, ['openid']));

        // Act
        $next = $storage->groups()->create('Brand new', '');

        // Assert
        $this->assertGreaterThan($highest, $next);
    }

    public function testItRefusesAFileThatIsNotABackup(): void
    {
        // Arrange
        $target = TestConnection::open();
        $file = $this->work . '/not-a-backup.tar.gz';
        file_put_contents($file, 'plain text');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a backup');

        // Act
        (new Importer($target, $this->work . '/keys'))->import($file);

        // Assert (the exception above)
    }
}

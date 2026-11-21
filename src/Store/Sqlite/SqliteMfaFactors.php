<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PDO;

/** Second factors: one authenticator app per person (its secret arrives sealed) and their recovery codes (hashes). */
final class SqliteMfaFactors implements \PhpSso\Store\MfaFactors
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    /** @return array{secret: string, last_counter: int, created_at: int}|null */
    public function find(string $userSub): ?array
    {
        $stmt = $this->pdo->prepare('SELECT secret, last_counter, created_at FROM user_mfa WHERE user_sub = ?');
        $stmt->execute([$userSub]);
        $row = $stmt->fetch();

        return $row === false ? null : ['secret' => $row['secret'], 'last_counter' => (int) $row['last_counter'], 'created_at' => (int) $row['created_at']];
    }

    /**
     * Saves the authenticator app (replacing a previous one) together with a fresh set of recovery codes.
     *
     * @param string[] $codeHashes
     */
    public function save(string $userSub, string $sealedSecret, int $counter, array $codeHashes): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO user_mfa (user_sub, secret, last_counter, created_at) VALUES (?, ?, ?, ?)
                ON CONFLICT (user_sub) DO UPDATE SET secret = excluded.secret, last_counter = excluded.last_counter, created_at = excluded.created_at')
                ->execute([$userSub, $sealedSecret, $counter, time()]);
            $this->replaceRecoveryCodes($userSub, $codeHashes);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Makes the step the new last accepted one, but only if it is newer. False means the code was already used
     * (including by a request that ran at the same moment).
     */
    public function advanceCounter(string $userSub, int $counter): bool
    {
        $stmt = $this->pdo->prepare('UPDATE user_mfa SET last_counter = ? WHERE user_sub = ? AND last_counter < ?');
        $stmt->execute([$counter, $userSub, $counter]);

        return $stmt->rowCount() > 0;
    }

    /** @param string[] $codeHashes */
    public function replaceRecoveryCodes(string $userSub, array $codeHashes): void
    {
        $this->pdo->prepare('DELETE FROM mfa_recovery_codes WHERE user_sub = ?')->execute([$userSub]);
        $insert = $this->pdo->prepare('INSERT INTO mfa_recovery_codes (user_sub, code_hash) VALUES (?, ?)');
        foreach ($codeHashes as $hash) {
            $insert->execute([$userSub, $hash]);
        }
    }

    /** Spends a recovery code. False when there is no such unused code. */
    public function useRecoveryCode(string $userSub, string $codeHash): bool
    {
        $stmt = $this->pdo->prepare('UPDATE mfa_recovery_codes SET used_at = ? WHERE user_sub = ? AND code_hash = ? AND used_at IS NULL');
        $stmt->execute([time(), $userSub, $codeHash]);

        return $stmt->rowCount() > 0;
    }

    public function recoveryCodesLeft(string $userSub): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_sub = ? AND used_at IS NULL');
        $stmt->execute([$userSub]);

        return (int) $stmt->fetchColumn();
    }

    /** Removes the authenticator app and the recovery codes. */
    public function remove(string $userSub): void
    {
        $this->pdo->prepare('DELETE FROM mfa_recovery_codes WHERE user_sub = ?')->execute([$userSub]);
        $this->pdo->prepare('DELETE FROM user_mfa WHERE user_sub = ?')->execute([$userSub]);
    }
}

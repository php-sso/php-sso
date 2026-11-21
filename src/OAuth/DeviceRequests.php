<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\Database;
use PDO;
use PhpSso\OAuthServer\Grant\DeviceAuthorizationRecord;
use PhpSso\OAuthServer\Grant\DeviceAuthorizationStore;
use PhpSso\OAuthServer\Grant\DeviceStatus;

/** Device authorization requests (RFC 8628) in SQLite. Only hashes of the device code and the user code are stored. */
final class DeviceRequests implements DeviceAuthorizationStore
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    public function save(DeviceAuthorizationRecord $request): void
    {
        $this->pdo->prepare('INSERT OR REPLACE INTO oauth_device_requests
            (device_code_hash, user_code_hash, client_id, scopes, family_id, expires_at, interval_seconds, status, user_id, session_id, last_polled_at, consumed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $request->deviceCodeHash, $request->userCodeHash, $request->clientId, json_encode($request->scopes), $request->familyId,
            $request->expiresAt, $request->interval, $request->status->value, $request->userId, $request->sessionId,
            $request->lastPolledAt, $request->consumedAt,
        ]);
    }

    public function findByDeviceCodeHash(string $deviceCodeHash): ?DeviceAuthorizationRecord
    {
        return $this->find('device_code_hash', $deviceCodeHash);
    }

    public function findByUserCodeHash(string $userCodeHash): ?DeviceAuthorizationRecord
    {
        return $this->find('user_code_hash', $userCodeHash);
    }

    public function approve(string $deviceCodeHash, string $userId, ?string $sessionId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE oauth_device_requests SET status = 'approved', user_id = ?, session_id = ?
            WHERE device_code_hash = ? AND status = 'pending'");
        $stmt->execute([$userId, $sessionId, $deviceCodeHash]);

        return $stmt->rowCount() > 0;
    }

    public function deny(string $deviceCodeHash): bool
    {
        $stmt = $this->pdo->prepare("UPDATE oauth_device_requests SET status = 'denied' WHERE device_code_hash = ? AND status = 'pending'");
        $stmt->execute([$deviceCodeHash]);

        return $stmt->rowCount() > 0;
    }

    public function recordPoll(string $deviceCodeHash, int $at, int $interval): void
    {
        $this->pdo->prepare('UPDATE oauth_device_requests SET last_polled_at = ?, interval_seconds = ? WHERE device_code_hash = ?')
            ->execute([$at, $interval, $deviceCodeHash]);
    }

    /** One caller wins, so an approved request pays out tokens once. */
    public function markConsumed(string $deviceCodeHash, int $at): bool
    {
        $stmt = $this->pdo->prepare('UPDATE oauth_device_requests SET consumed_at = ? WHERE device_code_hash = ? AND consumed_at IS NULL');
        $stmt->execute([$at, $deviceCodeHash]);

        return $stmt->rowCount() > 0;
    }

    private function find(string $column, string $hash): ?DeviceAuthorizationRecord
    {
        $stmt = $this->pdo->prepare("SELECT * FROM oauth_device_requests WHERE {$column} = ?");
        $stmt->execute([$hash]);
        $row = $stmt->fetch();

        return $row ? new DeviceAuthorizationRecord(
            $row['device_code_hash'], $row['user_code_hash'], $row['client_id'], json_decode($row['scopes'], true), $row['family_id'],
            (int) $row['expires_at'], (int) $row['interval_seconds'], DeviceStatus::from($row['status']),
            $row['user_id'], $row['session_id'],
            $row['last_polled_at'] === null ? null : (int) $row['last_polled_at'],
            $row['consumed_at'] === null ? null : (int) $row['consumed_at'],
        ) : null;
    }
}

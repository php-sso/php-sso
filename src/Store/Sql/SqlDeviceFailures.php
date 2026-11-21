<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PhpSso\Store\DeviceFailures;

final class SqlDeviceFailures implements DeviceFailures
{
    private PDO $pdo;

    public function __construct(Connection $db)
    {
        $this->pdo = $db->pdo;
    }

    public function count(string $userSub, int $since): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM device_failures WHERE user_sub = ? AND created_at > ?');
        $stmt->execute([$userSub, $since]);

        return (int) $stmt->fetchColumn();
    }

    public function record(string $userSub, int $at): void
    {
        $this->pdo->prepare('INSERT INTO device_failures (user_sub, created_at) VALUES (?, ?)')->execute([$userSub, $at]);
    }
}

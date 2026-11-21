<?php

declare(strict_types=1);

namespace PhpSso\Auth;

use PhpSso\Store\LoginAttempts;
use PhpSso\Store\Settings;

/**
 * Limits failed password attempts: too many within a window, for one identifier (or the account it names) or from one
 * address, and sign-in is refused until old failures leave the window. The limits are the config values until an
 * administrator saves others. A reset makes the failures stop counting; they stay in the sign-in history.
 */
final class LoginThrottle
{
    private const KEY = 'login_throttle';
    private const FALLBACK = ['enabled' => true, 'per_identifier' => 5, 'per_ip' => 20, 'window_seconds' => 900];

    /** @param array<string, mixed> $defaults the config values, used until something is saved */
    public function __construct(
        private LoginAttempts $attempts,
        private Settings $settings,
        private array $defaults = [],
    ) {
    }

    /** @return array{enabled: bool, per_identifier: int, per_ip: int, window_seconds: int} */
    public function limits(): array
    {
        $stored = json_decode((string) $this->settings->get(self::KEY), true);
        $merged = (is_array($stored) ? $stored : []) + $this->defaults + self::FALLBACK;

        return [
            'enabled' => (bool) $merged['enabled'],
            'per_identifier' => max(1, (int) $merged['per_identifier']),
            'per_ip' => max(1, (int) $merged['per_ip']),
            'window_seconds' => max(60, (int) $merged['window_seconds']),
        ];
    }

    /** The limits as the settings form shows them: the window in minutes. @return array{enabled: bool, per_identifier: int, per_ip: int, window_minutes: int} */
    public function form(): array
    {
        $limits = $this->limits();

        return [
            'enabled' => $limits['enabled'],
            'per_identifier' => $limits['per_identifier'],
            'per_ip' => $limits['per_ip'],
            'window_minutes' => intdiv($limits['window_seconds'], 60),
        ];
    }

    /**
     * Reads the settings form. Valid numbers come back as integers and invalid ones as typed, so the form can show them again.
     *
     * @param array<mixed> $body
     * @return array{0: array{enabled: bool, per_identifier: int|string, per_ip: int|string, window_minutes: int|string}, 1: array<string, string>} the values and the errors by field
     */
    public static function parseForm(array $body): array
    {
        $ranges = [
            'per_identifier' => [1, 100, 'Use a number from 1 to 100.'],
            'per_ip' => [1, 1000, 'Use a number from 1 to 1000.'],
            'window_minutes' => [1, 1440, 'Use a number of minutes from 1 to 1440 (one day).'],
        ];
        $values = ['enabled' => isset($body['enabled'])];
        $errors = [];
        foreach ($ranges as $field => [$min, $max, $message]) {
            $raw = trim((string) ($body[$field] ?? ''));
            $number = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
            $values[$field] = $number === false ? $raw : $number;
            if ($number === false) {
                $errors[$field] = $message;
            }
        }

        return [$values, $errors];
    }

    /** @param array{enabled: bool, per_identifier: int, per_ip: int, window_minutes: int} $form values that passed parseForm() */
    public function save(array $form): void
    {
        $this->settings->set(self::KEY, json_encode([
            'enabled' => $form['enabled'],
            'per_identifier' => $form['per_identifier'],
            'per_ip' => $form['per_ip'],
            'window_seconds' => $form['window_minutes'] * 60,
        ], JSON_THROW_ON_ERROR));
    }

    /** Seconds until this person may try again, or null when nothing stops them. */
    public function lockedFor(string $identifier, ?string $sub, string $ip, ?int $now = null): ?int
    {
        $limits = $this->limits();
        if (!$limits['enabled']) {
            return null;
        }
        $now ??= time();
        $since = $now - $limits['window_seconds'];

        $waits = [];
        foreach ([
            [$this->attempts->failureTimesForIdentifier($identifier, $sub, $since), $limits['per_identifier']],
            [$this->attempts->failureTimesFromIp($ip, $since), $limits['per_ip']],
        ] as [$times, $limit]) {
            if (count($times) >= $limit) {
                // Locked until enough of the oldest failures have left the window to get below the limit.
                $waits[] = $times[count($times) - $limit] + $limits['window_seconds'] - $now;
            }
        }

        return $waits === [] ? null : max(1, ...$waits);
    }

    /** Puts a refusal in the sign-in history, once per address or identifier per window so a flood cannot fill it. */
    public function recordLocked(string $identifier, ?string $sub, string $ip, string $userAgent): void
    {
        if (!$this->attempts->hasThrottledSince($ip, $identifier, time() - $this->limits()['window_seconds'])) {
            $this->attempts->record($identifier, $sub, false, $ip, $userAgent, throttled: true);
        }
    }

    /**
     * Everyone with failures that count right now, locked ones first.
     *
     * @return list<array{type: string, key: string, sub: ?string, label: string, failures: int, limit: int, locked: bool, until: int}>
     *   `type` is account, identifier (no account) or ip; `until` is when the lock ends, or when the failures have all expired
     */
    public function failing(?int $now = null): array
    {
        $limits = $this->limits();
        $now ??= time();
        $groups = [];
        foreach ($this->attempts->countedFailures($now - $limits['window_seconds']) as $f) {
            $who = $f['user_sub'] !== null ? ['account', $f['user_sub'], $f['username']] : ['identifier', mb_strtolower($f['username']), $f['username']];
            $groups[$who[0] . ':' . $who[1]] ??= ['type' => $who[0], 'key' => $who[1], 'sub' => $who[0] === 'account' ? $who[1] : null, 'label' => $who[2], 'limit' => $limits['per_identifier'], 'times' => []];
            $groups[$who[0] . ':' . $who[1]]['times'][] = $f['created_at'];
            if ($f['ip'] !== '') {
                $groups['ip:' . $f['ip']] ??= ['type' => 'ip', 'key' => $f['ip'], 'sub' => null, 'label' => $f['ip'], 'limit' => $limits['per_ip'], 'times' => []];
                $groups['ip:' . $f['ip']]['times'][] = $f['created_at'];
            }
        }

        $rows = [];
        foreach ($groups as $g) {
            $n = count($g['times']);
            $locked = $limits['enabled'] && $n >= $g['limit'];
            $rows[] = [
                'type' => $g['type'], 'key' => $g['key'], 'sub' => $g['sub'], 'label' => $g['label'],
                'failures' => $n, 'limit' => $g['limit'], 'locked' => $locked,
                'until' => ($locked ? $g['times'][$n - $g['limit']] : $g['times'][$n - 1]) + $limits['window_seconds'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['locked'], $b['failures']] <=> [$a['locked'], $a['failures']]);

        return $rows;
    }

    /** How many are locked right now. */
    public function lockedCount(?int $now = null): int
    {
        return count(array_filter($this->failing($now), static fn (array $r): bool => $r['locked']));
    }

    /** The account's own row from failing(), or null when none of its failures count. */
    public function accountStatus(string $sub, ?int $now = null): ?array
    {
        foreach ($this->failing($now) as $row) {
            if ($row['type'] === 'account' && $row['key'] === $sub) {
                return $row;
            }
        }

        return null;
    }

    /** Forgets the account's failures (and those typed as the given identifiers). @return int how many failures stopped counting */
    public function resetAccount(string $sub, string ...$identifiers): int
    {
        return $this->attempts->clearForAccount($sub, $identifiers);
    }

    public function resetIdentifier(string $identifier): int
    {
        return $this->attempts->clearForIdentifier($identifier);
    }

    public function resetIp(string $ip): int
    {
        return $this->attempts->clearForIp($ip);
    }
}

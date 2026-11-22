<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use Closure;
use PhpSso\Audit\Audit;
use PhpSso\OAuthServer\Logout\LogoutDelivery;
use PhpSso\Store\LogoutRetries;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Tells an app again that a session ended, when the first notification did not get through (the app was down, or answered with an error).
 *
 * A failed delivery is noted from the request itself ({@see undelivered()}, called by {@see RetryingLogoutClient}), so every way a session can end is
 * covered, including the end-session endpoint of the OAuth library. {@see run()} then tries again, with a fresh token each time (a logout token is
 * valid for two minutes only), after 1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours. An app that still has not answered after that is given up on,
 * and that is written to the audit log.
 */
final class LogoutRetrier
{
    /** Seconds to wait after the first failure, the second, and so on. */
    public const BACKOFF = [60, 300, 1800, 7200, 43200];

    /** The first delivery and five retries. */
    public const MAX_ATTEMPTS = 6;

    /** How long an entry is taken for one attempt, so that a process that dies halfway does not lose it. */
    private const LEASE_SECONDS = 300;

    /** @param Closure(string, string, string): list<LogoutDelivery> $deliver sends one app the notification, with a fresh token: client id, session id, person */
    public function __construct(private LogoutRetries $queue, private Closure $deliver, private ?Audit $audit = null)
    {
    }

    /** Notes a notification that failed, from the request that carried it. Anything that is not a logout token is ignored. */
    public function undelivered(RequestInterface $request, string $error, ?int $now = null): void
    {
        $body = $request->getBody();
        $position = $body->isSeekable() ? $body->tell() : null;
        $form = (string) $body;
        if ($position !== null) {
            $body->seek($position);
        }
        parse_str($form, $fields);
        $parts = is_string($fields['logout_token'] ?? null) ? explode('.', $fields['logout_token']) : [];
        $claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
        $client = is_array($claims) ? (is_array($claims['aud'] ?? null) ? ($claims['aud'][0] ?? null) : ($claims['aud'] ?? null)) : null;
        if (!is_string($client) || !is_string($claims['sid'] ?? null) || !is_string($claims['sub'] ?? null)) {
            return;
        }

        $now ??= time();
        $this->queue->enqueue($claims['sid'], $claims['sub'], $client, $error, $now + self::BACKOFF[0], $now);
    }

    /**
     * Tries the notifications that are due.
     *
     * @return array{delivered: int, retrying: int, gave_up: int, gone: int} `gone`: the app has no logout address any more, so there is nothing to send
     */
    public function run(int $limit = 10, ?int $now = null): array
    {
        $now ??= time();
        $result = ['delivered' => 0, 'retrying' => 0, 'gave_up' => 0, 'gone' => 0];
        foreach ($this->queue->due($now, $limit) as $job) {
            if (!$this->queue->claim($job['id'], $now, $now + self::LEASE_SECONDS)) {
                continue; // another process has it
            }

            try {
                $deliveries = ($this->deliver)($job['client_id'], $job['session_id'], $job['user_sub']);
            } catch (Throwable $e) {
                $deliveries = [new LogoutDelivery($job['client_id'], '', false, null, $e->getMessage())];
            }
            if ($deliveries === []) {
                $this->queue->delete($job['id']);
                $result['gone']++;
            } elseif ($deliveries[0]->delivered) {
                $this->queue->delete($job['id']);
                $result['delivered']++;
            } else {
                $attempts = $job['attempts'] + 1;
                $error = $deliveries[0]->error ?? 'The app did not accept it.';
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->giveUp($job, $attempts, $error);
                    $result['gave_up']++;
                } else {
                    $this->queue->reschedule($job['id'], $attempts, $now + self::BACKOFF[$attempts - 1], $error);
                    $result['retrying']++;
                }
            }
        }

        return $result;
    }

    /** @param array{id: int, session_id: string, user_sub: string, client_id: string, attempts: int} $job */
    private function giveUp(array $job, int $attempts, string $error): void
    {
        $this->queue->delete($job['id']);
        error_log("SSO: gave up telling {$job['client_id']} that a session ended, after {$attempts} attempts ({$error}).");
        $this->audit?->record('logout.undelivered', 'system', '', 'client', $job['client_id'], null, ['session' => $job['session_id'], 'attempts' => $attempts, 'error' => $error]);
    }
}

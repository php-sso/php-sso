<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** Second factors: one authenticator app per person (its secret arrives sealed) and their recovery codes (hashes). */
interface MfaFactors
{
    /** @return array{secret: string, last_counter: int, created_at: int}|null */
    public function find(string $userSub): ?array;

    /**
     * Saves the authenticator app (replacing a previous one) together with a fresh set of recovery codes.
     *
     * @param string[] $codeHashes
     */
    public function save(string $userSub, string $sealedSecret, int $counter, array $codeHashes): void;

    /**
     * Makes the step the new last accepted one, but only if it is newer. False means the code was already used
     * (including by a request that ran at the same moment).
     */
    public function advanceCounter(string $userSub, int $counter): bool;

    /** @param string[] $codeHashes */
    public function replaceRecoveryCodes(string $userSub, array $codeHashes): void;

    /** Spends a recovery code. False when there is no such unused code. */
    public function useRecoveryCode(string $userSub, string $codeHash): bool;

    public function recoveryCodesLeft(string $userSub): int;

    /** Removes the authenticator app and the recovery codes. */
    public function remove(string $userSub): void;
}

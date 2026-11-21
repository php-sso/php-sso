<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Net\ClientIp;

use PhpSso\Net\ClientIp;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(ClientIp::class, 'of')]
final class OfTest extends TestCase
{
    /** @return array<string, array{string[], string, ?string, string}> trusted proxies, peer, X-Forwarded-For, expected */
    public static function cases(): array
    {
        return [
            'no trusted proxies ignores the header' => [[], '8.8.8.8', '1.1.1.1', '8.8.8.8'],
            'untrusted peer ignores the header' => [['10.0.0.0/8'], '8.8.8.8', '1.1.1.1', '8.8.8.8'],
            'trusted peer uses the forwarded address' => [['10.0.0.0/8'], '10.0.0.1', '1.1.1.1', '1.1.1.1'],
            'trusted peer without header is the peer' => [['10.0.0.0/8'], '10.0.0.1', null, '10.0.0.1'],
            'addresses invented on the left are skipped' => [['10.0.0.0/8'], '10.0.0.1', '6.6.6.6, 1.1.1.1, 10.0.0.2', '1.1.1.1'],
            'chain of only trusted proxies falls back to the peer' => [['10.0.0.0/8'], '10.0.0.1', '10.0.0.2, 10.0.0.3', '10.0.0.1'],
            'malformed header falls back to the peer' => [['10.0.0.0/8'], '10.0.0.1', 'junk', '10.0.0.1'],
        ];
    }

    /** @param string[] $trusted */
    #[DataProvider('cases')]
    public function testItOnlyBelievesForwardedAddressesFromTrustedProxies(array $trusted, string $peer, ?string $forwarded, string $expected): void
    {
        // Arrange
        $clientIp = new ClientIp($trusted);
        $headers = $forwarded === null ? [] : ['X-Forwarded-For' => $forwarded];
        $request = new ServerRequest(['REMOTE_ADDR' => $peer], [], '/', 'GET', 'php://memory', $headers);

        // Act
        $result = $clientIp->of($request);

        // Assert
        $this->assertSame($expected, $result);
    }
}

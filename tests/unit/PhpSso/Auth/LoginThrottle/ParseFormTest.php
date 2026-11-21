<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\LoginThrottle;

use PhpSso\Auth\LoginThrottle;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(LoginThrottle::class, 'parseForm')]
final class ParseFormTest extends TestCase
{
    public function testItReturnsIntegersAndNoErrorsForValidInput(): void
    {
        // Arrange
        $body = ['enabled' => '1', 'per_identifier' => ' 7 ', 'per_ip' => '30', 'window_minutes' => '15'];

        // Act
        [$values, $errors] = LoginThrottle::parseForm($body);

        // Assert
        $this->assertSame(['enabled' => true, 'per_identifier' => 7, 'per_ip' => 30, 'window_minutes' => 15], $values);
        $this->assertSame([], $errors);
    }

    public function testAnUncheckedBoxMeansItIsOff(): void
    {
        // Arrange
        $body = ['per_identifier' => '5', 'per_ip' => '20', 'window_minutes' => '15'];

        // Act
        [$values] = LoginThrottle::parseForm($body);

        // Assert
        $this->assertFalse($values['enabled']);
    }

    /** @return array<string, array{string, string}> field, bad value */
    public static function invalid(): array
    {
        return [
            'zero attempts' => ['per_identifier', '0'],
            'too many attempts' => ['per_identifier', '101'],
            'not a number' => ['per_ip', 'lots'],
            'a decimal' => ['per_ip', '2.5'],
            'empty window' => ['window_minutes', ''],
            'a window over a day' => ['window_minutes', '1441'],
        ];
    }

    #[DataProvider('invalid')]
    public function testItReportsTheFieldAndKeepsWhatWasTyped(string $field, string $bad): void
    {
        // Arrange
        $body = ['per_identifier' => '5', 'per_ip' => '20', 'window_minutes' => '15', $field => $bad];

        // Act
        [$values, $errors] = LoginThrottle::parseForm($body);

        // Assert
        $this->assertSame([$field], array_keys($errors));
        $this->assertSame($bad, $values[$field]);
    }
}

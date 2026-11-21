<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Mfa\QrCode;

use PhpSso\Auth\Mfa\QrCode;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Auth\LoginController\Support;

#[CoversMethod(QrCode::class, 'svg')]
final class SvgTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testItDrawsInlineSvgWithoutAnXmlPrologAndWithoutTheEncodedText(): void
    {
        // Arrange
        $text = 'otpauth://totp/Acme%3Aalice?secret=ZLQ2YYFDUWDSEJFMVEYG37SOKFCND5IH';

        // Act
        $svg = QrCode::svg($text);

        // Assert
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
        $this->assertStringNotContainsString('ZLQ2', $svg);
        $this->assertStringContainsString('viewBox', $svg);
    }

    public function testDifferentTextGivesADifferentCodeAndTheSameTextTheSameCode(): void
    {
        // Arrange
        $one = QrCode::svg('otpauth://totp/a?secret=AAAAAAAAAAAAAAAA');

        // Act
        $same = QrCode::svg('otpauth://totp/a?secret=AAAAAAAAAAAAAAAA');
        $other = QrCode::svg('otpauth://totp/a?secret=BBBBBBBBBBBBBBBB');

        // Assert
        $this->assertSame($one, $same);
        $this->assertNotSame($one, $other);
    }

    public function testAnAddressForALongEmailStyleAccountStillFits(): void
    {
        // Arrange
        $text = 'otpauth://totp/' . rawurlencode('Acme SSO:' . str_repeat('a', 60) . '@' . str_repeat('b', 150) . '.example.com') . '?secret=ZLQ2YYFDUWDSEJFMVEYG37SOKFCND5IH&issuer=Acme%20SSO&algorithm=SHA1&digits=6&period=30';

        // Act
        $svg = QrCode::svg($text);

        // Assert
        $this->assertStringStartsWith('<svg', $svg);
    }

    public function testTextBeyondTheLargestCodeIsRefused(): void
    {
        // Arrange
        $text = str_repeat('a', 2332);

        // Act
        $draw = fn () => QrCode::svg($text);

        // Assert
        $this->expectException(\LengthException::class);
        $draw();
    }

    public function testTheSetupPageShowsTheCodeAndStillShowsTheKeyToTypeIn(): void
    {
        // Arrange
        $app = new Support([], 'everyone');
        $sub = $app->data->user('alice');
        $app->flow->beginSecondFactor($sub, 'alice', 'enroll');

        // Act
        $body = (string) $app->mfaController->setupForm()->getBody();

        // Assert
        $this->assertStringContainsString('<div class="qr"', $body);
        $this->assertStringContainsString('<svg', $body);
        $this->assertStringContainsString('class="secret"', $body);
    }
}

<?php

declare(strict_types=1);

namespace PhpSso\Mail;

/** Builds the mailer the `mail` settings describe (the SSO_SMTP_* and SSO_MAIL_* environment variables). */
final class MailFactory
{
    /**
     * @param array<string, mixed> $mail the `mail` part of the config
     * @param string $issuer the public address, whose host is the name this server gives in EHLO
     */
    public static function create(array $mail, string $issuer, string $appName): SmtpMailer
    {
        $domain = substr((string) $mail['from'], (int) strrpos((string) $mail['from'], '@') + 1);
        $host = (string) parse_url($issuer, PHP_URL_HOST);
        $hello = preg_match('/^[A-Za-z0-9.\-]+$/', $host) === 1 ? $host : 'localhost';
        $encryption = (string) $mail['encryption'];

        return new SmtpMailer(
            static fn (): SmtpTransport => SocketTransport::connect((string) $mail['host'], (int) $mail['port'], $encryption === 'tls', (float) $mail['timeout'], (string) $mail['ca_file']),
            new MessageBuilder((string) $mail['from'], (string) ($mail['from_name'] !== '' ? $mail['from_name'] : $appName), $domain),
            (string) $mail['from'],
            $encryption,
            (string) $mail['username'],
            (string) $mail['password'],
            $hello,
        );
    }
}

<?php

declare(strict_types=1);

namespace PhpSso\Mail;

use Closure;

/**
 * Sends mail through an SMTP server (RFC 5321): optionally wraps the connection in TLS first (`tls`, a port that starts with it, or `starttls`, which
 * the server must offer: there is no falling back to plain text), signs in with AUTH PLAIN or AUTH LOGIN, and hands over the message.
 * What is not supported: OAuth sign-in (XOAUTH2) and servers that only offer CRAM-MD5. Mail services that give you a user name and a password work.
 */
final class SmtpMailer implements Mailer
{
    private const MAX_REPLY_LINES = 100;

    /**
     * @param Closure(): SmtpTransport $connect opens a connection
     * @param string $encryption `starttls`, `tls` (the connection starts as TLS: the transport must already be) or `none`
     * @param string $heloName the name this server gives itself in EHLO
     */
    public function __construct(
        private Closure $connect,
        private MessageBuilder $messages,
        private string $from,
        private string $encryption,
        private string $username,
        private string $password,
        private string $heloName,
    ) {
        Email::assertAddress($from);
        if (!in_array($encryption, ['starttls', 'tls', 'none'], true)) {
            throw new \InvalidArgumentException('The encryption is starttls, tls or none.');
        }
        if (preg_match('/^[A-Za-z0-9.\-]+$/', $heloName) !== 1) {
            throw new \InvalidArgumentException('The name for EHLO must be a host name.');
        }
    }

    public function send(Email $email): void
    {
        $message = $this->messages->build($email);
        $transport = ($this->connect)();
        try {
            $this->reply($transport, [220]);
            $capabilities = $this->hello($transport);

            if ($this->encryption === 'starttls') {
                if (!in_array('STARTTLS', $capabilities, true)) {
                    throw new MailFailed('The mail server does not offer STARTTLS, and the mail is not sent unencrypted. Use tls, or fix the server.');
                }
                $this->command($transport, 'STARTTLS', [220]);
                $transport->startTls();
                $capabilities = $this->hello($transport); // what is offered can change once the line is secure
            }
            if ($this->username !== '') {
                $this->authenticate($transport, $capabilities);
            }

            $this->command($transport, 'MAIL FROM:<' . $this->from . '>', [250]);
            $this->command($transport, 'RCPT TO:<' . $email->to . '>', [250, 251]);
            $this->command($transport, 'DATA', [354]);
            $transport->write(self::dotStuffed($message) . ".\r\n");
            $this->reply($transport, [250]);

            try {
                $this->command($transport, 'QUIT', [221]);
            } catch (MailFailed) {
                // it is sent: a server that is rude about hanging up is not a failure
            }
        } finally {
            $transport->close();
        }
    }

    /** @return list<string> what the server offers, upper case (`AUTH PLAIN LOGIN`, `STARTTLS`...) */
    private function hello(SmtpTransport $transport): array
    {
        $lines = $this->command($transport, 'EHLO ' . $this->heloName, [250]);
        array_shift($lines); // the greeting line

        return array_map('strtoupper', $lines);
    }

    /** @param list<string> $capabilities */
    private function authenticate(SmtpTransport $transport, array $capabilities): void
    {
        $methods = [];
        foreach ($capabilities as $capability) {
            if (str_starts_with($capability, 'AUTH ') || str_starts_with($capability, 'AUTH=')) {
                $methods = [...$methods, ...preg_split('/[ =]+/', substr($capability, 5), -1, PREG_SPLIT_NO_EMPTY)];
            }
        }

        if (in_array('PLAIN', $methods, true)) {
            $this->command($transport, 'AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), [235], secret: true);
        } elseif (in_array('LOGIN', $methods, true)) {
            $this->command($transport, 'AUTH LOGIN', [334], secret: true);
            $this->command($transport, base64_encode($this->username), [334], secret: true);
            $this->command($transport, base64_encode($this->password), [235], secret: true);
        } else {
            throw new MailFailed('The mail server offers no way to sign in that is supported (PLAIN or LOGIN).');
        }
    }

    /**
     * @param list<int> $expected
     * @return list<string> the text of every line of the reply
     */
    private function command(SmtpTransport $transport, string $command, array $expected, bool $secret = false): array
    {
        $transport->write($command . "\r\n");

        return $this->reply($transport, $expected, $secret ? 'sign-in' : $command);
    }

    /**
     * @param list<int> $expected
     * @return list<string>
     */
    private function reply(SmtpTransport $transport, array $expected, string $after = 'connecting'): array
    {
        $lines = [];
        for ($i = 0; $i < self::MAX_REPLY_LINES; $i++) {
            $line = $transport->readLine();
            if (preg_match('/^(\d{3})([ -])(.*)$/', $line, $m) !== 1) {
                throw new MailFailed("The mail server sent something that is not an SMTP reply after {$after}.");
            }
            $lines[] = $m[3];
            if ($m[2] === ' ') {
                if (!in_array((int) $m[1], $expected, true)) {
                    throw new MailFailed("The mail server refused it after {$after}: {$m[1]} {$m[3]}");
                }

                return $lines;
            }
        }

        throw new MailFailed('The mail server sent a reply that never ends.');
    }

    /** A line that starts with a dot would end the message early: its dot is doubled. */
    private static function dotStuffed(string $message): string
    {
        return str_starts_with($message, '.') ? '.' . preg_replace('/\r\n\./', "\r\n..", $message) : (string) preg_replace('/\r\n\./', "\r\n..", $message);
    }
}

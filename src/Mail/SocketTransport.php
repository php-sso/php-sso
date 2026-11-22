<?php

declare(strict_types=1);

namespace PhpSso\Mail;

/** A real connection. TLS always checks the certificate (against the system's authorities, or a file of your own) and the name of the server. */
final class SocketTransport implements SmtpTransport
{
    /** @var resource|null */
    private $stream;

    /** @param resource $stream */
    private function __construct($stream, private string $host, private float $timeout, private string $caFile)
    {
        $this->stream = $stream;
    }

    /** @throws MailFailed */
    public static function connect(string $host, int $port, bool $implicitTls, float $timeout, string $caFile = ''): self
    {
        $stream = @stream_socket_client(($implicitTls ? 'ssl' : 'tcp') . '://' . $host . ':' . $port, $code, $message, $timeout, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => self::tls($host, $caFile)]));
        if ($stream === false) {
            throw new MailFailed("Cannot connect to the mail server {$host}:{$port} ({$message}).");
        }
        stream_set_timeout($stream, (int) ceil($timeout));

        return new self($stream, $host, $timeout, $caFile);
    }

    public function readLine(): string
    {
        $line = $this->stream !== null ? fgets($this->stream, 4096) : false;
        if ($line === false) {
            throw new MailFailed('The mail server hung up or took too long to answer.');
        }

        return rtrim($line, "\r\n");
    }

    public function write(string $data): void
    {
        if ($this->stream === null || @fwrite($this->stream, $data) !== strlen($data)) {
            throw new MailFailed('Cannot send to the mail server: the connection is gone.');
        }
    }

    public function startTls(): void
    {
        if ($this->stream === null) {
            throw new MailFailed('Cannot secure a connection that is gone.');
        }
        foreach (self::tls($this->host, $this->caFile) as $option => $value) {
            stream_context_set_option($this->stream, 'ssl', $option, $value);
        }
        if (@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
            throw new MailFailed("Cannot secure the connection to {$this->host}: its certificate was not accepted, or it does not offer TLS 1.2 or later.");
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    /** @return array<string, mixed> */
    private static function tls(string $host, string $caFile): array
    {
        return ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true, 'allow_self_signed' => false]
            + ($caFile !== '' ? ['cafile' => $caFile] : []);
    }
}

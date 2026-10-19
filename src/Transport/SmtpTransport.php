<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;
use ZenoMailer\DkimSigner;
use ZenoMailer\MessageSerializer;
use ZenoMailer\SerializedMessage;
use ZenoMailer\StreamedMessage;

final class SmtpTransport implements TransportInterface, SignableTransport
{
    use SequentialBatchSending;

    private const CRLF = "\r\n";

    private MessageSerializer $serializer;
    private bool $starttls;
    private bool $verifyPeer;
    private bool $verifyPeerName;
    private bool $allowSelfSigned;
    private string $heloHostname;
    private int $timeout;
    private bool $keepalive;
    private int $maxMessagesPerConnection;

    /** @var resource|null */
    private $socket = null;
    /** @var array<string, mixed> */
    private array $capabilities = [];
    private int $messagesOnConnection = 0;
    private ?DkimSigner $dkimSigner = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private string $host,
        private int $port = 25,
        private bool $secure = false,
        private ?string $username = null,
        private ?string $password = null,
        private array $options = []
    ) {
        $this->serializer = new MessageSerializer();
        $this->starttls = isset($options['starttls']) ? filter_var($options['starttls'], FILTER_VALIDATE_BOOLEAN) : false;
        $this->verifyPeer = isset($options['verify_peer']) ? filter_var($options['verify_peer'], FILTER_VALIDATE_BOOLEAN) : true;
        $this->verifyPeerName = isset($options['verify_peer_name']) ? filter_var($options['verify_peer_name'], FILTER_VALIDATE_BOOLEAN) : true;
        $this->allowSelfSigned = isset($options['allow_self_signed']) ? filter_var($options['allow_self_signed'], FILTER_VALIDATE_BOOLEAN) : false;
        $this->heloHostname = (string)($options['helo'] ?? gethostname() ?: 'localhost');
        $this->timeout = (int)($options['timeout'] ?? 30);
        $this->keepalive = isset($options['keepalive']) ? filter_var($options['keepalive'], FILTER_VALIDATE_BOOLEAN) : true;
        $this->maxMessagesPerConnection = (int)($options['max_messages_per_connection'] ?? 0);
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function send(Envelope $envelope): TransportResult
    {
        try {
            if ($this->dkimSigner !== null) {
                // DKIM signs the full message string (inherent to signing)
                $message = $this->dkimSigner->sign($this->serializer->serialize($envelope));
            } elseif ($envelope->getAttachments() !== []) {
                // Memory-safe path: attachments stream from disk to the socket
                $message = $this->serializer->serializeStreamed($envelope);
            } else {
                $message = $this->serializer->serialize($envelope);
            }
        } catch (\InvalidArgumentException $e) {
            return TransportResult::failure('Invalid envelope: ' . $e->getMessage());
        }

        try {
            $socket = $this->acquireConnection();

            $this->mailFrom($socket, $envelope, $this->capabilities);
            $this->recipients($socket, $envelope);
            $finalResponse = $this->data($socket, $message);
            $this->messagesOnConnection++;

            if (!$this->keepalive || ($this->maxMessagesPerConnection > 0 && $this->messagesOnConnection >= $this->maxMessagesPerConnection)) {
                $this->disconnect();
            }

            return TransportResult::success('Email sent via SMTP', $message->getMessageId(), [
                'host' => $this->host,
                'port' => $this->port,
                'response' => $finalResponse,
                'reused_connection' => $this->messagesOnConnection > 1,
            ]);
        } catch (\Exception $e) {
            // The connection state is unknown after an error - discard it.
            $this->disconnect();

            return TransportResult::failure($e->getMessage(), [
                'host' => $this->host,
                'port' => $this->port,
            ], $this->isRetryableError($e));
        }
    }

    /**
     * SMTP 4xx replies and connection-level problems are transient;
     * 5xx replies and envelope validation errors are not.
     */
    private function isRetryableError(\Exception $e): bool
    {
        if ($e instanceof SmtpReplyException) {
            return $e->isTransient();
        }

        return !$e instanceof \InvalidArgumentException;
    }

    /**
     * Close the persistent connection (sends QUIT if still usable).
     * Called automatically on error, on destruction, and when the
     * per-connection message cap is reached.
     */
    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            try {
                $this->sendCommand($this->socket, 'QUIT');
            } catch (\RuntimeException) {
                // Best effort: the socket is being discarded anyway
            }
            fclose($this->socket);
        }

        $this->socket = null;
        $this->capabilities = [];
        $this->messagesOnConnection = 0;
    }

    /**
     * Returns the persistent connection if it is still alive (validated with
     * NOOP), otherwise establishes a fresh authenticated connection.
     *
     * @return resource
     */
    private function acquireConnection()
    {
        if (is_resource($this->socket)) {
            $socket = $this->socket;
            try {
                $this->expect($this->sendCommand($socket, 'NOOP'), ['250'], 'NOOP');

                return $socket;
            } catch (\Exception) {
                // Server closed the connection or went away - reconnect below
                $this->disconnect();
            }
        }

        $capabilities = [];
        $socket = $this->connect($capabilities);
        $this->authenticate($socket, $capabilities);

        $this->socket = $socket;
        $this->capabilities = $capabilities;
        $this->messagesOnConnection = 0;

        return $socket;
    }

    /**
     * Escape leading dots per RFC 5321 section 4.5.2 (dot-stuffing).
     */
    public static function dotStuff(string $message): string
    {
        return preg_replace('/^\./m', '..', $message) ?? $message;
    }

    public function setDkimSigner(DkimSigner $signer): void
    {
        $this->dkimSigner = $signer;
    }

    /**
     * @param array<string, mixed> $capabilities Filled with the parsed EHLO capabilities
     * @return resource
     */
    private function connect(array &$capabilities)
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeerName,
                'allow_self_signed' => $this->allowSelfSigned,
            ],
        ]);

        $protocol = $this->secure ? 'ssl' : 'tcp';
        $socket = @stream_socket_client(
            "{$protocol}://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException("Failed to connect to {$this->host}:{$this->port} - {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $this->timeout);

        $greeting = $this->readResponse($socket);
        $this->expect($greeting, ['220'], 'SMTP greeting');

        $capabilities = $this->ehlo($socket);

        if ($this->starttls && !$this->secure) {
            if (!isset($capabilities['starttls'])) {
                throw new \RuntimeException('SMTP server does not advertise STARTTLS support');
            }

            $this->expect($this->sendCommand($socket, 'STARTTLS'), ['220'], 'STARTTLS');

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('TLS negotiation after STARTTLS failed');
            }

            $capabilities = $this->ehlo($socket);
        }

        return $socket;
    }

    /**
     * @param resource $socket
     * @return array<string, mixed> capability name => value (true, or a list for AUTH mechanisms)
     */
    private function ehlo($socket): array
    {
        $response = $this->sendCommand($socket, 'EHLO ' . $this->heloHostname);
        $this->expect($response, ['250'], 'EHLO');

        $capabilities = [];
        foreach (preg_split('/\r\n|\r|\n/', $response) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^\d{3}[ -]/', $line) !== 1) {
                continue;
            }

            $tokens = preg_split('/\s+/', substr($line, 4)) ?: [];
            if ($tokens === []) {
                continue;
            }

            $keyword = strtolower(array_shift($tokens));
            if ($keyword === 'auth') {
                $capabilities['auth'] = array_map('strtolower', $tokens);
            } else {
                $capabilities[$keyword] = $tokens === [] ? true : $tokens;
            }
        }

        return $capabilities;
    }

    /**
     * @param resource $socket
     * @param array<string, mixed> $capabilities
     */
    private function authenticate($socket, array $capabilities): void
    {
        if ($this->username === null && $this->password === null) {
            return;
        }

        if ($this->username === null || $this->password === null) {
            throw new \InvalidArgumentException('Both username and password are required for SMTP authentication');
        }

        $username = $this->username;
        $password = $this->password;

        $mechanisms = $capabilities['auth'] ?? ['login'];
        if (!is_array($mechanisms)) {
            $mechanisms = ['login'];
        }

        if (in_array('plain', $mechanisms, true)) {
            $response = $this->sendCommand($socket, 'AUTH PLAIN');
            $this->expect($response, ['334'], 'AUTH PLAIN');
            $response = $this->sendCommand(
                $socket,
                base64_encode("\0" . $username . "\0" . $password)
            );
            $this->expect($response, ['235'], 'SMTP authentication');
        } elseif (in_array('login', $mechanisms, true)) {
            $response = $this->sendCommand($socket, 'AUTH LOGIN');
            $this->expect($response, ['334'], 'AUTH LOGIN');
            $response = $this->sendCommand($socket, base64_encode($username));
            $this->expect($response, ['334'], 'SMTP username');
            $response = $this->sendCommand($socket, base64_encode($password));
            $this->expect($response, ['235'], 'SMTP authentication');
        } else {
            throw new \RuntimeException(
                'No supported SMTP AUTH mechanism advertised (server offers: ' . implode(', ', $mechanisms) . ')'
            );
        }
    }

    /**
     * @param resource $socket
     * @param array<string, mixed> $capabilities
     */
    private function mailFrom($socket, Envelope $envelope, array $capabilities): void
    {
        $from = $envelope->getFrom();
        if ($from === null) {
            throw new \InvalidArgumentException('Envelope must have a From address');
        }

        $command = 'MAIL FROM:<' . $from->getEmail() . '>';
        if ($envelope->getEncoding() === '8bit' && isset($capabilities['8bitmime'])) {
            $command .= ' BODY=8BITMIME';
        }

        if ($envelope->requiresSmtpUtf8()) {
            if (!isset($capabilities['smtputf8'])) {
                throw new \RuntimeException(
                    'Envelope contains internationalized addresses (UTF-8 local part) but the server '
                    . 'does not advertise SMTPUTF8 support (RFC 6531)'
                );
            }
            $command .= ' SMTPUTF8';
        }

        $this->expect($this->sendCommand($socket, $command), ['250'], 'MAIL FROM');
    }

    /**
     * @param resource $socket
     */
    private function recipients($socket, Envelope $envelope): void
    {
        foreach ($envelope->getAllRecipients() as $recipient) {
            $response = $this->sendCommand($socket, 'RCPT TO:<' . $recipient->getEmail() . '>');
            $this->expect($response, ['250', '251'], "RCPT TO:<{$recipient->getEmail()}>");
        }
    }

    /**
     * @param resource $socket
     */
    private function data($socket, SerializedMessage|StreamedMessage $message): string
    {
        $this->expect($this->sendCommand($socket, 'DATA'), ['354'], 'DATA');

        if ($message instanceof StreamedMessage) {
            self::registerDotStuffFilter();
            $stream = $message->getStream();
            $filter = stream_filter_append($stream, DotStuffStreamFilter::NAME, STREAM_FILTER_READ);
            if ($filter === false) {
                throw new \RuntimeException('Failed to attach the dot-stuffing stream filter');
            }

            $copied = stream_copy_to_stream($stream, $socket);
            if ($copied === false) {
                throw new \RuntimeException('Failed to stream message data to SMTP socket');
            }

            // The streamed body already ends with CRLF; only the dot is needed
            fwrite($socket, '.' . self::CRLF);
        } else {
            $payload = self::dotStuff($message->getMessage()) . self::CRLF . '.' . self::CRLF;
            $written = fwrite($socket, $payload);
            if ($written === false || $written < strlen($payload)) {
                throw new \RuntimeException('Failed to write message data to SMTP socket');
            }
        }

        $response = $this->readResponse($socket);
        $this->expect($response, ['250'], 'Message acceptance');

        return $response;
    }

    private static function registerDotStuffFilter(): void
    {
        if (!in_array(DotStuffStreamFilter::NAME, stream_get_filters(), true)) {
            stream_filter_register(DotStuffStreamFilter::NAME, DotStuffStreamFilter::class);
        }
    }

    /**
     * @param resource $socket
     */
    private function sendCommand($socket, string $command): string
    {
        $written = fwrite($socket, $command . self::CRLF);
        if ($written === false) {
            throw new \RuntimeException('Failed to write command to SMTP socket');
        }

        return $this->readResponse($socket);
    }

    /**
     * @param resource $socket
     */
    private function readResponse($socket): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;

            $line = rtrim($line, "\r\n");
            if (strlen($line) < 4 || $line[3] === ' ') {
                return trim($response);
            }

            $meta = stream_get_meta_data($socket);
            if ($meta['timed_out']) {
                break;
            }
        }

        $meta = stream_get_meta_data($socket);
        if ($meta['timed_out']) {
            throw new \RuntimeException('SMTP read timed out while reading response: ' . trim($response));
        }

        throw new \RuntimeException('SMTP connection closed unexpectedly while reading response: ' . trim($response));
    }

    /**
     * @param string[] $codes
     */
    private function expect(string $response, array $codes, string $what): void
    {
        foreach ($codes as $code) {
            if (str_starts_with($response, $code)) {
                return;
            }
        }

        throw new SmtpReplyException($what, $response);
    }
}

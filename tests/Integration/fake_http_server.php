<?php

declare(strict_types=1);

/**
 * Standalone fake HTTP server used by the async concurrency tests.
 *
 * Usage: php fake_http_server.php <transcript-file> <delay-ms> <expected-requests>
 *
 * Prints "PORT=<port>" to stdout, then accepts concurrent connections,
 * waits <delay-ms> after each complete request arrives, answers with a
 * Brevo-style JSON response, and records per-request arrival/response
 * timestamps in a JSON transcript. Exits once <expected-requests> responses
 * have been sent (or after a 30s deadline).
 */

$transcriptFile = $argv[1] ?? null;
$delayMs = (int)($argv[2] ?? 300);
$expected = (int)($argv[3] ?? 5);

if ($transcriptFile === null) {
    fwrite(STDERR, "Usage: php fake_http_server.php <transcript-file> <delay-ms> <expected-requests>\n");
    exit(1);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Failed to listen: {$errstr} ({$errno})\n");
    exit(1);
}
stream_set_blocking($server, false);

$name = stream_socket_get_name($server, false);
$port = (int)substr((string)$name, strrpos((string)$name, ':') + 1);
fwrite(STDOUT, "PORT={$port}\n");
fflush(STDOUT);

/** @var array<int, array{res: resource, buffer: string, idx: int|null, respond_at: float|null}> $clients */
$clients = [];
/** @var list<array{arrival: float, responded_at: float|null}> $requests */
$requests = [];

$deadline = microtime(true) + 30;

while (count(array_filter($requests, static fn ($r) => $r['responded_at'] !== null)) < $expected) {
    if (microtime(true) > $deadline) {
        break;
    }

    // Accept any pending connections
    while (($conn = @stream_socket_accept($server, 0)) !== false) {
        stream_set_blocking($conn, false);
        $clients[(int)$conn] = ['res' => $conn, 'buffer' => '', 'idx' => null, 'respond_at' => null];
    }

    // Build the read set: server + clients still awaiting a complete request
    $read = [$server];
    foreach ($clients as $client) {
        if ($client['respond_at'] === null) {
            $read[] = $client['res'];
        }
    }

    // Wake up when the next delayed response is due
    $nextDue = null;
    foreach ($clients as $client) {
        if ($client['respond_at'] !== null) {
            $nextDue = min($nextDue ?? PHP_FLOAT_MAX, $client['respond_at']);
        }
    }
    $timeout = $nextDue === null ? 0.5 : max(0.005, $nextDue - microtime(true));
    $sec = (int)$timeout;
    $usec = (int)(($timeout - $sec) * 1_000_000);

    $write = null;
    $except = null;
    $readyCount = @stream_select($read, $write, $except, $sec, $usec);
    if ($readyCount === false) {
        break;
    }

    foreach ($read as $ready) {
        if ($ready === $server) {
            continue;
        }

        $id = (int)$ready;
        if (!isset($clients[$id])) {
            continue;
        }
        $client = $clients[$id];

        $data = @fread($ready, 65536);

        if (($data === false || $data === '') && feof($ready)) {
            fclose($ready);
            unset($clients[$id]);
            continue;
        }

        $client['buffer'] .= (string)$data;

        if ($client['respond_at'] === null && str_contains($client['buffer'], "\r\n\r\n")) {
            [$headers, $rest] = explode("\r\n\r\n", $client['buffer'], 2);

            if (preg_match('/Expect:\s*100-continue/i', $headers) === 1) {
                fwrite($ready, "HTTP/1.1 100 Continue\r\n\r\n");
            }

            $contentLength = 0;
            if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $m) === 1) {
                $contentLength = (int)$m[1];
            }

            if (strlen($rest) >= $contentLength) {
                $client['idx'] = count($requests);
                $requests[] = ['arrival' => microtime(true), 'responded_at' => null];
                $client['respond_at'] = microtime(true) + $delayMs / 1000;
            }
        }

        $clients[$id] = $client;
    }

    // Answer every client whose delay has elapsed
    $now = microtime(true);
    foreach ($clients as $id => $client) {
        if ($client['respond_at'] !== null && $now >= $client['respond_at']) {
            $body = '{"messageId": "fake-' . $id . '"}';
            fwrite(
                $client['res'],
                "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body
            );
            if ($client['idx'] !== null && isset($requests[$client['idx']])) {
                $requests[$client['idx']]['responded_at'] = microtime(true);
            }
            stream_socket_shutdown($client['res'], STREAM_SHUT_RDWR);
            fclose($client['res']);
            unset($clients[$id]);
        }
    }
}

fclose($server);
file_put_contents($transcriptFile, json_encode(['requests' => $requests]) ?: '{}');
exit(0);

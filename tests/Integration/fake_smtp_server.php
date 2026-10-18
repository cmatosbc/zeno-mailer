<?php

declare(strict_types=1);

/**
 * Standalone fake SMTP server used by the integration tests.
 *
 * Usage: php fake_smtp_server.php <transcript-file> [mode] [expected-sessions]
 *
 * Prints "PORT=<port>" to stdout, serves up to <expected-sessions> SMTP
 * sessions (each may contain multiple messages, to exercise connection
 * reuse), records every command plus each raw (dot-stuffed) DATA payload,
 * writes a JSON transcript to the given file and exits.
 *
 * Modes:
 *  - ok           accept everything
 *  - reject_rcpt  reject RCPT TO for addresses containing "bad@"
 *  - login_only   advertise only AUTH LOGIN (no PLAIN)
 */

$transcriptFile = $argv[1] ?? null;
$mode = $argv[2] ?? 'ok';
$expectedSessions = (int)($argv[3] ?? 1);

if ($transcriptFile === null) {
    fwrite(STDERR, "Usage: php fake_smtp_server.php <transcript-file> [mode] [expected-sessions]\n");
    exit(1);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Failed to listen: {$errstr} ({$errno})\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
$port = (int)substr((string)$name, strrpos((string)$name, ':') + 1);
fwrite(STDOUT, "PORT={$port}\n");
fflush(STDOUT);

$transcript = ['mode' => $mode, 'commands' => [], 'messages' => [], 'raw_data' => null];

$sessions = 0;
while ($sessions < $expectedSessions) {
    $conn = stream_socket_accept($server, 30);
    if ($conn === false) {
        break;
    }

    // Safety net: end the session if the client goes silent for 5 seconds
    stream_set_timeout($conn, 5);

    fwrite($conn, "220 fake.zeno.test ESMTP\r\n");

    $state = 'command';
    $dataBuffer = '';

    while (($line = fgets($conn, 515)) !== false) {
        if ($state === 'data') {
            if ($line === ".\r\n") {
                $transcript['messages'][] = $dataBuffer;
                $transcript['raw_data'] = $dataBuffer;
                $state = 'command';
                fwrite($conn, "250 OK queued as 12345\r\n");
                continue;
            }
            $dataBuffer .= $line;
            continue;
        }

        $command = trim($line);
        $transcript['commands'][] = $command;
        $upper = strtoupper($command);

        if (str_starts_with($upper, 'EHLO') || str_starts_with($upper, 'HELO')) {
            $authLine = $mode === 'login_only' ? '250 AUTH LOGIN' : '250 AUTH PLAIN LOGIN';
            $utf8Line = $mode === 'no_utf8' ? '' : "250-SMTPUTF8\r\n";
            fwrite($conn, "250-fake.zeno.test\r\n250-8BITMIME\r\n250-SIZE 10485760\r\n{$utf8Line}{$authLine}\r\n");
        } elseif (str_starts_with($upper, 'AUTH PLAIN')) {
            fwrite($conn, "334 \r\n");
            $b64 = trim((string)fgets($conn, 515));
            $transcript['commands'][] = 'AUTH_PLAIN_RESPONSE:' . $b64;
            fwrite($conn, "235 OK\r\n");
        } elseif (str_starts_with($upper, 'AUTH LOGIN')) {
            fwrite($conn, "334 VXNlcm5hbWU6\r\n");
            $user = trim((string)fgets($conn, 515));
            $transcript['commands'][] = 'AUTH_USER:' . $user;
            fwrite($conn, "334 UGFzc3dvcmQ6\r\n");
            $pass = trim((string)fgets($conn, 515));
            $transcript['commands'][] = 'AUTH_PASS:' . $pass;
            fwrite($conn, "235 OK\r\n");
        } elseif (str_starts_with($upper, 'MAIL FROM')) {
            fwrite($conn, "250 OK\r\n");
        } elseif (str_starts_with($upper, 'RCPT TO')) {
            if ($mode === 'reject_rcpt' && str_contains($command, 'bad@')) {
                fwrite($conn, "550 mailbox unavailable\r\n");
            } elseif ($mode === 'tempfail' && str_contains($command, 'bad@')) {
                fwrite($conn, "451 temporary local problem, try again\r\n");
            } else {
                fwrite($conn, "250 OK\r\n");
            }
        } elseif (str_starts_with($upper, 'DATA')) {
            $state = 'data';
            $dataBuffer = '';
            fwrite($conn, "354 go ahead\r\n");
        } elseif (str_starts_with($upper, 'NOOP')) {
            fwrite($conn, "250 OK\r\n");
        } elseif (str_starts_with($upper, 'QUIT')) {
            fwrite($conn, "221 bye\r\n");
            break;
        } else {
            fwrite($conn, "502 not implemented\r\n");
        }
    }

    fclose($conn);
    $sessions++;
}

fclose($server);
file_put_contents($transcriptFile, json_encode($transcript) ?: '{}');
exit(0);

#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as LegacyException;
use ZenoMailer\Envelope;
use ZenoMailer\Transport\BrevoTransport;

// Load environment variables
function loadEnv(string $path): array
{
    if (!file_exists($path)) {
        throw new \RuntimeException(".env file not found at: {$path}");
    }

    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, '"\'');
            $env[$key] = $value;
        }
    }

    return $env;
}

class AsyncBenchmark
{
    private string $recipientEmail;
    private string $gmailUser;
    private string $gmailAppPassword;
    private string $brevoApiKey;
    private string $brevoSender;
    private array $results = [];

    public function __construct()
    {
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        echo "=== Async Performance Benchmark ===\n\n";

        $env = loadEnv(__DIR__ . '/.env');

        if (!isset($env['GMAIL_APP_PASSWORD'])) {
            throw new \RuntimeException('GMAIL_APP_PASSWORD not found in .env file');
        }

        if (!isset($env['BREVO_API_KEY'])) {
            throw new \RuntimeException('BREVO_API_KEY not found in .env file');
        }

        $this->recipientEmail = $this->prompt('Recipient email address');
        $this->gmailUser = $this->prompt('Gmail address (for SMTP)', 'carlosmatos552@gmail.com');
        
        echo "\n⚠️  IMPORTANT: Brevo requires a verified sender email.\n";
        echo "   Go to https://app.brevo.com/senders to verify an email first.\n\n";
        
        $this->brevoSender = $this->prompt('Brevo verified sender email', $this->gmailUser);
        
        $this->gmailAppPassword = str_replace(' ', '', $env['GMAIL_APP_PASSWORD']);
        $this->brevoApiKey = $env['BREVO_API_KEY'];

        echo "\nConfiguration loaded:\n";
        echo "  Gmail (SMTP): {$this->gmailUser}\n";
        echo "  Brevo Sender: {$this->brevoSender}\n";
        echo "  Brevo API Key: " . substr($this->brevoApiKey, 0, 20) . "...\n";
        echo "  Recipient: {$this->recipientEmail}\n";
        echo "  PHP Fibers: " . (class_exists(\Fiber::class) ? '✓ Available' : '✗ Not available') . "\n\n";
    }

    private function prompt(string $question, string $default = ''): string
    {
        if ($default !== '') {
            echo "{$question} [{$default}]: ";
        } else {
            echo "{$question}: ";
        }

        $input = trim(fgets(STDIN));
        return $input === '' ? $default : $input;
    }

    public function run(): void
    {
        echo str_repeat('=', 80) . "\n";
        echo "ASYNC BENCHMARK: SMTP vs Brevo Sync vs Brevo Async\n";
        echo str_repeat('=', 80) . "\n\n";

        $batches = [5, 20];

        foreach ($batches as $count) {
            echo "\n" . str_repeat('-', 80) . "\n";
            echo "BATCH SIZE: {$count} emails\n";
            echo str_repeat('-', 80) . "\n\n";

            $this->benchmarkSmtp($count);
            echo "\n";
            $this->benchmarkBrevoSync($count);
            echo "\n";
            $this->benchmarkBrevoAsync($count);
            echo "\n";
        }

        $this->displaySummary();
    }

    private function benchmarkSmtp(int $count): void
    {
        echo "📧 Testing SMTP (Gmail) - Sequential\n";

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $successCount = 0;
        $errors = [];

        for ($i = 1; $i <= $count; $i++) {
            try {
                $mail = new PHPMailer(true);
                
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = $this->gmailUser;
                $mail->Password = $this->gmailAppPassword;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->SMTPDebug = SMTP::DEBUG_OFF;
                
                $mail->setFrom($this->gmailUser, 'Benchmark Test');
                $mail->addAddress($this->recipientEmail, 'Test Recipient');
                
                $mail->isHTML(true);
                $mail->Subject = "SMTP Test #{$i} of {$count}";
                $mail->Body = $this->generateHtmlBody($i, $count, 'SMTP (Sequential)');
                $mail->AltBody = $this->generateTextBody($i, $count, 'SMTP (Sequential)');

                $mail->send();
                $successCount++;
                
                echo "  ✓ Email {$i}/{$count} sent\n";
            } catch (LegacyException $e) {
                $errors[] = "Email {$i}: " . $e->getMessage();
                echo "  ✗ Email {$i}/{$count} failed\n";
            }

            if ($i < $count) {
                usleep(100000); // 100ms delay
            }
        }

        $duration = microtime(true) - $startTime;
        $memoryUsed = (memory_get_usage(true) - $startMemory) / 1024 / 1024;

        $this->results['smtp'][$count] = [
            'total_time' => $duration,
            'avg_time' => $duration / $count,
            'memory_mb' => $memoryUsed,
            'success' => $successCount,
            'failed' => count($errors),
        ];

        echo "\n";
        echo "  Total Time: " . number_format($duration, 3) . "s\n";
        echo "  Avg per Email: " . number_format($duration / $count, 3) . "s\n";
        echo "  Memory Used: " . number_format($memoryUsed, 2) . " MB\n";
        echo "  Success: {$successCount}/{$count}\n";
    }

    private function benchmarkBrevoSync(int $count): void
    {
        echo "🔄 Testing Brevo HTTP API - Synchronous (Sequential)\n";

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $successCount = 0;
        $errors = [];

        $transport = new BrevoTransport($this->brevoApiKey, ['async' => false]);

        for ($i = 1; $i <= $count; $i++) {
            try {
                $envelope = new Envelope();
                $envelope
                    ->setFrom($this->brevoSender, 'Benchmark Test')
                    ->addTo($this->recipientEmail, 'Test Recipient')
                    ->setSubject("Brevo Sync Test #{$i} of {$count}")
                    ->setHtmlBody($this->generateHtmlBody($i, $count, 'Brevo Sync'))
                    ->setTextBody($this->generateTextBody($i, $count, 'Brevo Sync'));

                $result = $transport->send($envelope);

                if ($result->isSuccess()) {
                    $successCount++;
                    echo "  ✓ Email {$i}/{$count} sent\n";
                } else {
                    $errors[] = "Email {$i}: " . $result->getMessage();
                    echo "  ✗ Email {$i}/{$count} failed\n";
                }
            } catch (\Throwable $e) {
                $errors[] = "Email {$i}: " . $e->getMessage();
                echo "  ✗ Email {$i}/{$count} failed\n";
            }

            if ($i < $count) {
                usleep(100000); // 100ms delay
            }
        }

        $duration = microtime(true) - $startTime;
        $memoryUsed = (memory_get_usage(true) - $startMemory) / 1024 / 1024;

        $this->results['brevo_sync'][$count] = [
            'total_time' => $duration,
            'avg_time' => $duration / $count,
            'memory_mb' => $memoryUsed,
            'success' => $successCount,
            'failed' => count($errors),
        ];

        echo "\n";
        echo "  Total Time: " . number_format($duration, 3) . "s\n";
        echo "  Avg per Email: " . number_format($duration / $count, 3) . "s\n";
        echo "  Memory Used: " . number_format($memoryUsed, 2) . " MB\n";
        echo "  Success: {$successCount}/{$count}\n";
    }

    private function benchmarkBrevoAsync(int $count): void
    {
        if (!class_exists(\Fiber::class)) {
            echo "⚠️  Brevo Async - SKIPPED (PHP Fibers not available)\n";
            return;
        }

        echo "🚀 Testing Brevo HTTP API - Asynchronous (Concurrent with Fibers)\n";

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $transport = new BrevoTransport($this->brevoApiKey, ['async' => true]);

        // Create all envelopes
        $envelopes = [];
        for ($i = 1; $i <= $count; $i++) {
            $envelope = new Envelope();
            $envelope
                ->setFrom($this->brevoSender, 'Benchmark Test')
                ->addTo($this->recipientEmail, 'Test Recipient')
                ->setSubject("Brevo Async Test #{$i} of {$count}")
                ->setHtmlBody($this->generateHtmlBody($i, $count, 'Brevo Async'))
                ->setTextBody($this->generateTextBody($i, $count, 'Brevo Async'));
            
            $envelopes[] = $envelope;
        }

        // Send all concurrently
        $results = $transport->sendBatch($envelopes);

        $successCount = 0;
        $errors = [];

        foreach ($results as $i => $result) {
            if ($result->isSuccess()) {
                $successCount++;
                echo "  ✓ Email " . ($i + 1) . "/{$count} sent\n";
            } else {
                $errors[] = "Email " . ($i + 1) . ": " . $result->getMessage();
                echo "  ✗ Email " . ($i + 1) . "/{$count} failed\n";
            }
        }

        $duration = microtime(true) - $startTime;
        $memoryUsed = (memory_get_usage(true) - $startMemory) / 1024 / 1024;

        $this->results['brevo_async'][$count] = [
            'total_time' => $duration,
            'avg_time' => $duration / $count,
            'memory_mb' => $memoryUsed,
            'success' => $successCount,
            'failed' => count($errors),
        ];

        echo "\n";
        echo "  Total Time: " . number_format($duration, 3) . "s\n";
        echo "  Avg per Email: " . number_format($duration / $count, 3) . "s\n";
        echo "  Memory Used: " . number_format($memoryUsed, 2) . " MB\n";
        echo "  Success: {$successCount}/{$count}\n";
        echo "  ⚡ Concurrent requests: ALL {$count} emails sent simultaneously!\n";
    }

    private function generateHtmlBody(int $current, int $total, string $type): string
    {
        return <<<HTML
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
        .content { padding: 30px; }
        .badge { display: inline-block; background: #667eea; color: white; padding: 5px 15px; border-radius: 20px; font-size: 14px; }
        .stats { background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚡ Async Benchmark Test</h1>
            <p><span class="badge">{$type}</span></p>
        </div>
        <div class="content">
            <h2>Email #{$current} of {$total}</h2>
            <div class="stats">
                <p><strong>Transport:</strong> {$type}</p>
                <p><strong>Timestamp:</strong> {$this->getCurrentTimestamp()}</p>
            </div>
            <p>Testing concurrent email sending with PHP Fibers and curl_multi.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function generateTextBody(int $current, int $total, string $type): string
    {
        return "ASYNC BENCHMARK TEST\n{$type}\n\nEmail #{$current} of {$total}\nTimestamp: {$this->getCurrentTimestamp()}";
    }

    private function getCurrentTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function displaySummary(): void
    {
        echo "\n" . str_repeat('=', 80) . "\n";
        echo "PERFORMANCE COMPARISON SUMMARY\n";
        echo str_repeat('=', 80) . "\n\n";

        $batches = array_keys($this->results['smtp'] ?? []);

        foreach ($batches as $count) {
            echo "📊 Batch Size: {$count} emails\n";
            echo str_repeat('-', 80) . "\n\n";

            $smtp = $this->results['smtp'][$count] ?? null;
            $brevoSync = $this->results['brevo_sync'][$count] ?? null;
            $brevoAsync = $this->results['brevo_async'][$count] ?? null;

            printf("%-25s | %-15s | %-15s | %-15s\n", "Metric", "SMTP", "Brevo Sync", "Brevo Async");
            echo str_repeat('-', 80) . "\n";

            if ($smtp && $brevoSync) {
                printf("%-25s | %-15s | %-15s | %-15s\n",
                    "Total Time",
                    number_format($smtp['total_time'], 3) . 's',
                    number_format($brevoSync['total_time'], 3) . 's',
                    $brevoAsync ? number_format($brevoAsync['total_time'], 3) . 's' : 'N/A'
                );

                printf("%-25s | %-15s | %-15s | %-15s\n",
                    "Avg per Email",
                    number_format($smtp['avg_time'], 3) . 's',
                    number_format($brevoSync['avg_time'], 3) . 's',
                    $brevoAsync ? number_format($brevoAsync['avg_time'], 3) . 's' : 'N/A'
                );

                printf("%-25s | %-15s | %-15s | %-15s\n",
                    "Success Rate",
                    "{$smtp['success']}/{$count}",
                    "{$brevoSync['success']}/{$count}",
                    $brevoAsync ? "{$brevoAsync['success']}/{$count}" : 'N/A'
                );

                echo "\n";

                // Calculate speedups
                $syncSpeedup = (($smtp['total_time'] - $brevoSync['total_time']) / $smtp['total_time']) * 100;
                echo "  🔄 Brevo Sync vs SMTP: " . number_format($syncSpeedup, 1) . "% faster\n";

                if ($brevoAsync) {
                    $asyncSpeedup = (($smtp['total_time'] - $brevoAsync['total_time']) / $smtp['total_time']) * 100;
                    $asyncVsSync = (($brevoSync['total_time'] - $brevoAsync['total_time']) / $brevoSync['total_time']) * 100;
                    
                    echo "  ⚡ Brevo Async vs SMTP: " . number_format($asyncSpeedup, 1) . "% faster\n";
                    echo "  🚀 Brevo Async vs Sync: " . number_format($asyncVsSync, 1) . "% faster\n";
                    echo "\n";
                    echo "  💡 Async sends ALL {$count} emails concurrently (parallel HTTP requests)\n";
                }
            }

            echo "\n";
        }

        echo str_repeat('=', 80) . "\n";
        echo "KEY INSIGHTS\n";
        echo str_repeat('=', 80) . "\n\n";

        echo "1. 🔄 Brevo Sync (HTTP API)\n";
        echo "   - Faster than SMTP due to simpler protocol\n";
        echo "   - Still sequential (one request at a time)\n";
        echo "   - No SMTP handshake overhead\n\n";

        if (class_exists(\Fiber::class)) {
            echo "2. ⚡ Brevo Async (HTTP API + Fibers + curl_multi)\n";
            echo "   - TRUE concurrent sending\n";
            echo "   - All HTTP requests sent simultaneously\n";
            echo "   - Scales with batch size (more emails = bigger advantage)\n";
            echo "   - Requires PHP 8.1+ with Fiber support\n\n";
        } else {
            echo "2. ⚠️  Brevo Async - Not Available\n";
            echo "   - Requires PHP 8.1+ with Fiber support\n";
            echo "   - Upgrade PHP to see true async performance\n\n";
        }

        echo "3. 📧 SMTP (Baseline)\n";
        echo "   - Universal compatibility\n";
        echo "   - Inherently sequential\n";
        echo "   - Multiple round-trips per email\n\n";
    }
}

try {
    $benchmark = new AsyncBenchmark();
    $benchmark->run();
    
    echo "\n✅ Async benchmark completed!\n\n";
    exit(0);
} catch (\Throwable $e) {
    echo "\n❌ Benchmark failed: {$e->getMessage()}\n";
    echo "Stack trace:\n{$e->getTraceAsString()}\n\n";
    exit(1);
}

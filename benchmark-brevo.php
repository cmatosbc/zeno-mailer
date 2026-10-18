#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as LegacyException;
use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;

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

class BrevoVsSmtpBenchmark
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
        echo "=== Brevo API vs SMTP Benchmark ===\n\n";

        // Load from .env
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
        echo "   Go to https://app.brevo.com/senders to verify an email first.\n";
        echo "   You can use the same Gmail address if you verify it in Brevo.\n\n";
        
        $this->brevoSender = $this->prompt('Brevo verified sender email', $this->gmailUser);
        
        // Strip spaces from Gmail app password
        $this->gmailAppPassword = str_replace(' ', '', $env['GMAIL_APP_PASSWORD']);
        $this->brevoApiKey = $env['BREVO_API_KEY'];

        echo "\nConfiguration loaded:\n";
        echo "  Gmail (SMTP): {$this->gmailUser}\n";
        echo "  Brevo Sender: {$this->brevoSender}\n";
        echo "  Brevo API Key: " . substr($this->brevoApiKey, 0, 20) . "...\n";
        echo "  Recipient: {$this->recipientEmail}\n\n";
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
        echo str_repeat('=', 70) . "\n";
        echo "EMAIL BENCHMARK: SMTP (Gmail) vs Brevo HTTP API\n";
        echo str_repeat('=', 70) . "\n\n";

        $batches = [5, 20, 50];

        foreach ($batches as $count) {
            echo "\n" . str_repeat('-', 70) . "\n";
            echo "BATCH SIZE: {$count} emails\n";
            echo str_repeat('-', 70) . "\n\n";

            $this->benchmarkSmtp($count);
            echo "\n";
            $this->benchmarkBrevo($count);
            echo "\n";
        }

        $this->displaySummary();
    }

    private function benchmarkSmtp(int $count): void
    {
        echo "📧 Testing SMTP (Gmail)...\n";

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
                $mail->Body = $this->generateHtmlBody($i, $count, 'SMTP (Gmail)');
                $mail->AltBody = $this->generateTextBody($i, $count, 'SMTP (Gmail)');

                $mail->send();
                $successCount++;
                
                echo "  ✓ Email {$i}/{$count} sent\n";
            } catch (LegacyException $e) {
                $errors[] = "Email {$i}: " . $e->getMessage();
                echo "  ✗ Email {$i}/{$count} failed: {$e->getMessage()}\n";
            }

            if ($i < $count) {
                usleep(100000); // 100ms delay
            }
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $duration = $endTime - $startTime;
        $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024;
        $avgTime = $duration / $count;

        $this->results['smtp'][$count] = [
            'total_time' => $duration,
            'avg_time' => $avgTime,
            'memory_mb' => $memoryUsed,
            'success' => $successCount,
            'failed' => count($errors),
            'errors' => $errors,
        ];

        echo "\n";
        echo "  Total Time: " . number_format($duration, 3) . "s\n";
        echo "  Avg per Email: " . number_format($avgTime, 3) . "s\n";
        echo "  Memory Used: " . number_format($memoryUsed, 2) . " MB\n";
        echo "  Success: {$successCount}/{$count}\n";
        echo "  Failed: " . count($errors) . "\n";
    }

    private function benchmarkBrevo(int $count): void
    {
        echo "🚀 Testing Brevo HTTP API...\n";

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $successCount = 0;
        $errors = [];

        $dsn = 'brevo://' . urlencode($this->brevoApiKey) . '@default';

        try {
            $mailer = MailerBuilder::create()
                ->withDsn($dsn)
                ->enableCssInlining()
                ->build();

            for ($i = 1; $i <= $count; $i++) {
                try {
                    $envelope = new Envelope();
                    $envelope
                        ->setFrom($this->brevoSender, 'Benchmark Test')
                        ->addTo($this->recipientEmail, 'Test Recipient')
                        ->setSubject("Brevo API Test #{$i} of {$count}")
                        ->setHtmlBody($this->generateHtmlBody($i, $count, 'Brevo API'))
                        ->setTextBody($this->generateTextBody($i, $count, 'Brevo API'));

                    $result = $mailer->send($envelope);

                    if ($result->isSuccess()) {
                        $successCount++;
                        $messageId = $result->getMessageId();
                        echo "  ✓ Email {$i}/{$count} sent (ID: {$messageId})\n";
                    } else {
                        $errors[] = "Email {$i}: " . $result->getMessage();
                        echo "  ✗ Email {$i}/{$count} failed: {$result->getMessage()}\n";
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Email {$i}: " . $e->getMessage();
                    echo "  ✗ Email {$i}/{$count} failed: {$e->getMessage()}\n";
                }

                if ($i < $count) {
                    usleep(100000); // 100ms delay
                }
            }
        } catch (\Throwable $e) {
            echo "  ✗ Failed to initialize mailer: {$e->getMessage()}\n";
            $errors[] = "Initialization: " . $e->getMessage();
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $duration = $endTime - $startTime;
        $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024;
        $avgTime = $count > 0 ? $duration / $count : 0;

        $this->results['brevo'][$count] = [
            'total_time' => $duration,
            'avg_time' => $avgTime,
            'memory_mb' => $memoryUsed,
            'success' => $successCount,
            'failed' => count($errors),
            'errors' => $errors,
        ];

        echo "\n";
        echo "  Total Time: " . number_format($duration, 3) . "s\n";
        echo "  Avg per Email: " . number_format($avgTime, 3) . "s\n";
        echo "  Memory Used: " . number_format($memoryUsed, 2) . " MB\n";
        echo "  Success: {$successCount}/{$count}\n";
        echo "  Failed: " . count($errors) . "\n";
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
        .footer { background-color: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
        .stats { background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Benchmark Test Email</h1>
            <p><span class="badge">{$type}</span></p>
        </div>
        <div class="content">
            <h2>Email #{$current} of {$total}</h2>
            <div class="stats">
                <p><strong>Transport:</strong> {$type}</p>
                <p><strong>Timestamp:</strong> {$this->getCurrentTimestamp()}</p>
                <p><strong>PHP Version:</strong> {$this->getPhpVersion()}</p>
            </div>
            <p>This email is part of a performance benchmark comparing different email delivery methods.</p>
            <p>The modern architecture provides clean abstractions for switching between SMTP and HTTP APIs.</p>
        </div>
        <div class="footer">
            <p>Automated benchmark test • Zeno Mailer</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function generateTextBody(int $current, int $total, string $type): string
    {
        return <<<TEXT
BENCHMARK TEST EMAIL
{$type}

Email #{$current} of {$total}

Transport: {$type}
Timestamp: {$this->getCurrentTimestamp()}
PHP Version: {$this->getPhpVersion()}

This email is part of a performance benchmark comparing different email delivery methods.

---
Automated benchmark test • Zeno Mailer
TEXT;
    }

    private function getCurrentTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function getPhpVersion(): string
    {
        return PHP_VERSION;
    }

    private function displaySummary(): void
    {
        echo "\n" . str_repeat('=', 70) . "\n";
        echo "BENCHMARK SUMMARY\n";
        echo str_repeat('=', 70) . "\n\n";

        $batches = array_keys($this->results['smtp'] ?? []);

        echo sprintf(
            "%-15s | %-25s | %-25s\n",
            "Batch Size",
            "SMTP (Gmail)",
            "Brevo HTTP API"
        );
        echo str_repeat('-', 70) . "\n";

        foreach ($batches as $count) {
            $smtp = $this->results['smtp'][$count] ?? null;
            $brevo = $this->results['brevo'][$count] ?? null;

            if ($smtp && $brevo) {
                echo sprintf("%-15s |\n", "{$count} emails");
                
                echo sprintf(
                    "  Time:         | %-25s | %-25s\n",
                    number_format($smtp['total_time'], 3) . 's',
                    number_format($brevo['total_time'], 3) . 's'
                );
                
                echo sprintf(
                    "  Avg/Email:    | %-25s | %-25s\n",
                    number_format($smtp['avg_time'], 3) . 's',
                    number_format($brevo['avg_time'], 3) . 's'
                );
                
                echo sprintf(
                    "  Memory:       | %-25s | %-25s\n",
                    number_format($smtp['memory_mb'], 2) . ' MB',
                    number_format($brevo['memory_mb'], 2) . ' MB'
                );
                
                echo sprintf(
                    "  Success Rate: | %-25s | %-25s\n",
                    "{$smtp['success']}/{$count}",
                    "{$brevo['success']}/{$count}"
                );

                if ($smtp['total_time'] > 0 && $brevo['total_time'] > 0) {
                    $speedup = (($smtp['total_time'] - $brevo['total_time']) / $smtp['total_time']) * 100;
                    
                    if ($speedup > 0) {
                        $speedupText = sprintf("🚀 Brevo is %.1f%% faster", $speedup);
                    } else {
                        $speedupText = sprintf("SMTP is %.1f%% faster", abs($speedup));
                    }
                    
                    echo sprintf(
                        "  Performance:  | %-52s\n",
                        $speedupText
                    );
                }

                echo str_repeat('-', 70) . "\n";
            }
        }

        echo "\n📊 Key Insights:\n\n";

        $totalSmtpTime = 0;
        $totalBrevoTime = 0;
        $totalEmails = 0;

        foreach ($batches as $count) {
            if (isset($this->results['smtp'][$count]) && isset($this->results['brevo'][$count])) {
                $totalSmtpTime += $this->results['smtp'][$count]['total_time'];
                $totalBrevoTime += $this->results['brevo'][$count]['total_time'];
                $totalEmails += $count;
            }
        }

        if ($totalSmtpTime > 0 && $totalBrevoTime > 0) {
            $overallSpeedup = (($totalSmtpTime - $totalBrevoTime) / $totalSmtpTime) * 100;
            
            echo "  📈 Overall Performance:\n";
            echo "     Total emails sent: {$totalEmails}\n";
            echo "     SMTP total time: " . number_format($totalSmtpTime, 3) . "s\n";
            echo "     Brevo total time: " . number_format($totalBrevoTime, 3) . "s\n";
            
            if ($overallSpeedup > 0) {
                echo "     🚀 Brevo HTTP API is " . number_format($overallSpeedup, 1) . "% faster overall!\n\n";
                echo "  💡 Why Brevo is faster:\n";
                echo "     • No SMTP handshake overhead\n";
                echo "     • HTTP/2 connection reuse\n";
                echo "     • Optimized for bulk sending\n";
                echo "     • Direct API calls vs protocol negotiation\n";
            } else {
                echo "     ⚖️  Performance is comparable (difference: " . number_format(abs($overallSpeedup), 1) . "%)\n";
            }
        }

        echo "\n  ✅ Modern Architecture Benefits:\n";
        echo "     • Same code, just change DSN to switch transports\n";
        echo "     • Middleware pipeline (CSS inlining, dark mode, telemetry)\n";
        echo "     • Clean separation: Envelope → Middleware → Transport\n";
        echo "     • Type-safe with PHP 8.3 features\n";

        echo "\n  📬 Deliverability Tips:\n";
        echo "     • Check your SPAM/Junk folder for Brevo emails\n";
        echo "     • Brevo emails may be filtered initially until sender reputation builds\n";
        echo "     • Mark Brevo emails as 'Not Spam' to improve future delivery\n";
        echo "     • For production: verify your domain in Brevo dashboard\n";
        echo "     • Add SPF/DKIM records for better deliverability\n";

        echo "\n";
    }
}

try {
    $benchmark = new BrevoVsSmtpBenchmark();
    $benchmark->run();
    
    echo "\n✅ Benchmark completed successfully!\n\n";
    exit(0);
} catch (\Throwable $e) {
    echo "\n❌ Benchmark failed: {$e->getMessage()}\n";
    echo "Stack trace:\n{$e->getTraceAsString()}\n\n";
    exit(1);
}

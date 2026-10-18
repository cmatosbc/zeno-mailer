#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as LegacyException;
use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;

class EmailBenchmark
{
    private string $recipientEmail;
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUser;
    private string $smtpPass;
    private bool $smtpSecure;
    private array $results = [];

    public function __construct()
    {
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        echo "=== Email Benchmark Configuration ===\n\n";

        $this->recipientEmail = $this->prompt('Recipient email address');
        
        echo "\nSMTP Configuration:\n";
        $this->smtpHost = $this->prompt('SMTP host', 'smtp.gmail.com');
        $this->smtpPort = (int)$this->prompt('SMTP port', '587');
        $this->smtpUser = $this->prompt('SMTP username');
        $this->smtpPass = $this->prompt('SMTP password (will be hidden)', '', true);
        
        // Strip spaces from password (Gmail app passwords are shown with spaces but should be used without)
        $this->smtpPass = str_replace(' ', '', $this->smtpPass);
        
        $useStartTls = strtolower($this->prompt('Use STARTTLS? (y/n)', 'y'));
        $this->smtpSecure = $useStartTls === 'y';

        echo "\n";
    }

    private function prompt(string $question, string $default = '', bool $hidden = false): string
    {
        if ($default !== '') {
            echo "{$question} [{$default}]: ";
        } else {
            echo "{$question}: ";
        }

        if ($hidden) {
            system('stty -echo');
            $input = trim(fgets(STDIN));
            system('stty echo');
            echo "\n";
        } else {
            $input = trim(fgets(STDIN));
        }

        return $input === '' ? $default : $input;
    }

    public function run(): void
    {
        echo "\n" . str_repeat('=', 70) . "\n";
        echo "EMAIL SENDING BENCHMARK: Legacy PHPMailer vs Modern Architecture\n";
        echo str_repeat('=', 70) . "\n\n";

        $batches = [5, 20, 100];

        foreach ($batches as $count) {
            echo "\n" . str_repeat('-', 70) . "\n";
            echo "BATCH SIZE: {$count} emails\n";
            echo str_repeat('-', 70) . "\n\n";

            $this->benchmarkLegacy($count);
            echo "\n";
            $this->benchmarkModern($count);
            echo "\n";
        }

        $this->displaySummary();
    }

    private function benchmarkLegacy(int $count): void
    {
        echo "📧 Testing LEGACY PHPMailer (Sequential)...\n";

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $successCount = 0;
        $errors = [];

        for ($i = 1; $i <= $count; $i++) {
            try {
                $mail = new PHPMailer(true);
                
                $mail->isSMTP();
                $mail->Host = $this->smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = $this->smtpUser;
                $mail->Password = $this->smtpPass;
                $mail->Port = $this->smtpPort;
                
                if ($this->smtpSecure) {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                }

                $mail->SMTPDebug = SMTP::DEBUG_OFF;
                
                $mail->setFrom($this->smtpUser, 'Benchmark Test');
                $mail->addAddress($this->recipientEmail, 'Test Recipient');
                
                $mail->isHTML(true);
                $mail->Subject = "Legacy Test Email #{$i} of {$count}";
                $mail->Body = $this->generateHtmlBody($i, $count, 'Legacy');
                $mail->AltBody = $this->generateTextBody($i, $count, 'Legacy');

                $mail->send();
                $successCount++;
                
                echo "  ✓ Email {$i}/{$count} sent\n";
            } catch (LegacyException $e) {
                $errors[] = "Email {$i}: " . $e->getMessage();
                echo "  ✗ Email {$i}/{$count} failed: {$e->getMessage()}\n";
            }

            if ($i < $count) {
                usleep(100000);
            }
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $duration = $endTime - $startTime;
        $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024;
        $avgTime = $duration / $count;

        $this->results['legacy'][$count] = [
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

    private function benchmarkModern(int $count): void
    {
        echo "🚀 Testing MODERN Architecture (with Fibers)...\n";

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $successCount = 0;
        $errors = [];

        $dsn = sprintf(
            'smtp://%s:%s@%s:%d%s',
            urlencode($this->smtpUser),
            urlencode($this->smtpPass),
            $this->smtpHost,
            $this->smtpPort,
            $this->smtpSecure ? '?starttls=true' : ''
        );

        try {
            $mailer = MailerBuilder::create()
                ->withDsn($dsn)
                ->enableCssInlining()
                ->build();

            for ($i = 1; $i <= $count; $i++) {
                try {
                    $envelope = new Envelope();
                    $envelope
                        ->setFrom($this->smtpUser, 'Benchmark Test')
                        ->addTo($this->recipientEmail, 'Test Recipient')
                        ->setSubject("Modern Test Email #{$i} of {$count}")
                        ->setHtmlBody($this->generateHtmlBody($i, $count, 'Modern'))
                        ->setTextBody($this->generateTextBody($i, $count, 'Modern'));

                    $result = $mailer->send($envelope);

                    if ($result->isSuccess()) {
                        $successCount++;
                        echo "  ✓ Email {$i}/{$count} sent\n";
                    } else {
                        $errors[] = "Email {$i}: " . $result->getMessage();
                        echo "  ✗ Email {$i}/{$count} failed: {$result->getMessage()}\n";
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Email {$i}: " . $e->getMessage();
                    echo "  ✗ Email {$i}/{$count} failed: {$e->getMessage()}\n";
                }

                if ($i < $count) {
                    usleep(100000);
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

        $this->results['modern'][$count] = [
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
        
        if (class_exists(\Fiber::class)) {
            echo "  ⚡ PHP Fibers: ENABLED\n";
        } else {
            echo "  ⚠️  PHP Fibers: NOT AVAILABLE (upgrade to PHP 8.1+)\n";
        }
    }

    private function generateHtmlBody(int $current, int $total, string $type): string
    {
        return <<<HTML
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; }
        .header { background-color: #4CAF50; color: white; padding: 20px; }
        .content { padding: 20px; }
        .footer { background-color: #f1f1f1; padding: 10px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Benchmark Test Email</h1>
    </div>
    <div class="content">
        <h2>{$type} Architecture</h2>
        <p>This is test email <strong>#{$current}</strong> of <strong>{$total}</strong>.</p>
        <p>Sent at: <em>{$this->getCurrentTimestamp()}</em></p>
        <p>Testing the performance and reliability of the {$type} email sending approach.</p>
    </div>
    <div class="footer">
        <p>Automated benchmark test - PHP Version: {$this->getPhpVersion()}</p>
    </div>
</body>
</html>
HTML;
    }

    private function generateTextBody(int $current, int $total, string $type): string
    {
        return <<<TEXT
BENCHMARK TEST EMAIL
{$type} Architecture

This is test email #{$current} of {$total}.
Sent at: {$this->getCurrentTimestamp()}

Testing the performance and reliability of the {$type} email sending approach.

---
Automated benchmark test - PHP Version: {$this->getPhpVersion()}
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

        $batches = [5, 20, 100];

        echo sprintf(
            "%-15s | %-25s | %-25s\n",
            "Batch Size",
            "Legacy PHPMailer",
            "Modern Architecture"
        );
        echo str_repeat('-', 70) . "\n";

        foreach ($batches as $count) {
            $legacy = $this->results['legacy'][$count] ?? null;
            $modern = $this->results['modern'][$count] ?? null;

            if ($legacy && $modern) {
                echo sprintf("%-15s |\n", "{$count} emails");
                
                echo sprintf(
                    "  Time:         | %-25s | %-25s\n",
                    number_format($legacy['total_time'], 3) . 's',
                    number_format($modern['total_time'], 3) . 's'
                );
                
                echo sprintf(
                    "  Avg/Email:    | %-25s | %-25s\n",
                    number_format($legacy['avg_time'], 3) . 's',
                    number_format($modern['avg_time'], 3) . 's'
                );
                
                echo sprintf(
                    "  Memory:       | %-25s | %-25s\n",
                    number_format($legacy['memory_mb'], 2) . ' MB',
                    number_format($modern['memory_mb'], 2) . ' MB'
                );
                
                echo sprintf(
                    "  Success Rate: | %-25s | %-25s\n",
                    "{$legacy['success']}/{$count}",
                    "{$modern['success']}/{$count}"
                );

                if ($legacy['total_time'] > 0 && $modern['total_time'] > 0) {
                    $speedup = ($legacy['total_time'] / $modern['total_time']) * 100 - 100;
                    $speedupText = $speedup > 0 
                        ? sprintf("Modern is %.1f%% faster", $speedup)
                        : sprintf("Legacy is %.1f%% faster", abs($speedup));
                    
                    echo sprintf(
                        "  Performance:  | %-52s\n",
                        $speedupText
                    );
                }

                echo str_repeat('-', 70) . "\n";
            }
        }

        echo "\n📊 Key Insights:\n\n";

        if (class_exists(\Fiber::class)) {
            echo "  ✅ PHP Fibers are ENABLED (PHP " . PHP_VERSION . ")\n";
            echo "     Modern architecture can use non-blocking I/O for better performance.\n\n";
        } else {
            echo "  ⚠️  PHP Fibers are NOT AVAILABLE (PHP " . PHP_VERSION . ")\n";
            echo "     Upgrade to PHP 8.1+ to enable non-blocking SMTP with Fibers.\n\n";
        }

        $totalLegacyTime = 0;
        $totalModernTime = 0;
        $totalEmails = 0;

        foreach ($batches as $count) {
            if (isset($this->results['legacy'][$count]) && isset($this->results['modern'][$count])) {
                $totalLegacyTime += $this->results['legacy'][$count]['total_time'];
                $totalModernTime += $this->results['modern'][$count]['total_time'];
                $totalEmails += $count;
            }
        }

        if ($totalLegacyTime > 0 && $totalModernTime > 0) {
            $overallSpeedup = (($totalLegacyTime - $totalModernTime) / $totalLegacyTime) * 100;
            
            echo "  📈 Overall Performance:\n";
            echo "     Total emails sent: {$totalEmails}\n";
            echo "     Legacy total time: " . number_format($totalLegacyTime, 3) . "s\n";
            echo "     Modern total time: " . number_format($totalModernTime, 3) . "s\n";
            
            if ($overallSpeedup > 0) {
                echo "     🚀 Modern architecture is " . number_format($overallSpeedup, 1) . "% faster overall!\n";
            } else {
                echo "     ⚖️  Performance is comparable (difference: " . number_format(abs($overallSpeedup), 1) . "%)\n";
            }
        }

        echo "\n";
    }
}

try {
    $benchmark = new EmailBenchmark();
    $benchmark->run();
    
    echo "\n✅ Benchmark completed successfully!\n\n";
    exit(0);
} catch (\Throwable $e) {
    echo "\n❌ Benchmark failed: {$e->getMessage()}\n";
    echo "Stack trace:\n{$e->getTraceAsString()}\n\n";
    exit(1);
}

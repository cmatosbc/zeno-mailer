# Email Benchmark Tool

Compare the performance of **Legacy PHPMailer** vs **Modern Architecture** with real-world email sending.

## Quick Start

### Run the Benchmark

```bash
php benchmark.php
```

Or if executable:

```bash
./benchmark.php
```

### What It Does

The benchmark tool will:

1. **Prompt for configuration:**
   - Recipient email address (where test emails will be sent)
   - SMTP server settings (host, port, username, password)
   - Security options (STARTTLS)

2. **Run tests in 3 batches:**
   - 5 emails
   - 20 emails
   - 100 emails

3. **Compare both approaches:**
   - **Legacy PHPMailer** - Traditional sequential sending
   - **Modern Architecture** - With concurrent HTTP batch sending

4. **Measure:**
   - Total execution time
   - Average time per email
   - Memory usage
   - Success/failure rates
   - Overall performance difference

## Example Output

```
=== Email Benchmark Configuration ===

Recipient email address: test@example.com

SMTP Configuration:
SMTP host [smtp.gmail.com]: smtp.gmail.com
SMTP port [587]: 587
SMTP username: your-email@gmail.com
SMTP password (will be hidden): ********
Use STARTTLS? (y/n) [y]: y

======================================================================
EMAIL SENDING BENCHMARK: Legacy PHPMailer vs Modern Architecture
======================================================================

----------------------------------------------------------------------
BATCH SIZE: 5 emails
----------------------------------------------------------------------

📧 Testing LEGACY PHPMailer (Sequential)...
  ✓ Email 1/5 sent
  ✓ Email 2/5 sent
  ✓ Email 3/5 sent
  ✓ Email 4/5 sent
  ✓ Email 5/5 sent

  Total Time: 8.234s
  Avg per Email: 1.647s
  Memory Used: 2.45 MB
  Success: 5/5
  Failed: 0

🚀 Testing MODERN Architecture (with concurrent HTTP)...
  ✓ Email 1/5 sent
  ✓ Email 2/5 sent
  ✓ Email 3/5 sent
  ✓ Email 4/5 sent
  ✓ Email 5/5 sent

  Total Time: 7.891s
  Avg per Email: 1.578s
  Memory Used: 2.12 MB
  Success: 5/5
  Failed: 0
  ⚡ Concurrent HTTP: ENABLED

----------------------------------------------------------------------
BATCH SIZE: 20 emails
----------------------------------------------------------------------

[... similar output ...]

======================================================================
BENCHMARK SUMMARY
======================================================================

Batch Size      | Legacy PHPMailer          | Modern Architecture      
----------------------------------------------------------------------
5 emails        |
  Time:         | 8.234s                    | 7.891s                   
  Avg/Email:    | 1.647s                    | 1.578s                   
  Memory:       | 2.45 MB                   | 2.12 MB                  
  Success Rate: | 5/5                       | 5/5                      
  Performance:  | Modern is 4.2% faster                                
----------------------------------------------------------------------

📊 Key Insights:

  ✅ Concurrent HTTP batch sending is ENABLED (PHP 8.1.0)
     Modern architecture can send batches concurrently for better performance.

  📈 Overall Performance:
     Total emails sent: 125
     Legacy total time: 205.432s
     Modern total time: 198.123s
     🚀 Modern architecture is 3.6% faster overall!

✅ Benchmark completed successfully!
```

## Configuration Examples

### Gmail

```
SMTP host: smtp.gmail.com
SMTP port: 587
SMTP username: your-email@gmail.com
SMTP password: your-app-password (not your regular password!)
Use STARTTLS: y
```

**Note:** For Gmail, you need to use an [App Password](https://support.google.com/accounts/answer/185833), not your regular password.

### Office 365 / Outlook

> **Note:** Microsoft 365 has disabled basic authentication (username/password) for SMTP.
> ZenoMailer does not support XOAUTH2 yet, so M365 SMTP is currently unavailable -
> use an API transport (Brevo, Postmark, SES) or Gmail app passwords instead.

### SendGrid

```
SMTP host: smtp.sendgrid.net
SMTP port: 587
SMTP username: apikey
SMTP password: your-sendgrid-api-key
Use STARTTLS: y
```

### Mailgun

```
SMTP host: smtp.mailgun.org
SMTP port: 587
SMTP username: postmaster@your-domain.mailgun.org
SMTP password: your-mailgun-smtp-password
Use STARTTLS: y
```

## What Gets Measured

### Time Metrics
- **Total Time** - Complete execution time for the batch
- **Avg per Email** - Average time to send one email
- **Performance Comparison** - Percentage difference between approaches

### Resource Metrics
- **Memory Usage** - RAM consumed during execution
- **Success Rate** - Number of successfully sent emails
- **Failure Rate** - Number of failed emails with error messages

### Technology Detection
- **Concurrent HTTP** - Batch sending runs requests concurrently when `async=true`
- **Version Info** - Shows PHP version being used

## Understanding Results

### Expected Performance

**With `async=true` (concurrent HTTP):**
- Modern architecture should be **5-15% faster** for small batches
- Performance gains increase with larger batches
- Lower memory footprint due to better resource management

**With sequential sending (`async=false`):**
- Performance should be **comparable** between both approaches
- Modern architecture still benefits from cleaner code structure
- Memory usage may be slightly better with modern approach

### Factors Affecting Performance

1. **SMTP Server Response Time** - Slower servers reduce performance differences
2. **Network Latency** - Higher latency makes both approaches slower
3. **Email Size** - Larger emails (with attachments) take longer
4. **Server Load** - SMTP server throttling can affect results
5. **Async mode** - Concurrent HTTP batch sending provides the biggest performance boost

## Troubleshooting

### "Authentication failed"

**Solution:** Check your SMTP credentials. For Gmail, use an App Password instead of your regular password.

### "Connection timeout"

**Solution:** 
- Verify SMTP host and port are correct
- Check firewall settings
- Try a different port (25, 465, or 587)

### "Too many connections"

**Solution:** Some SMTP servers limit connection rates. Add delays between batches or reduce batch sizes.

## Advanced Usage

### Custom Batch Sizes

Edit `benchmark.php` and modify the `$batches` array:

```php
$batches = [5, 20, 100];  // Change to your preferred sizes
```

### Add Attachments

Modify the email generation to include attachments for realistic testing:

```php
$envelope->addAttachment('/path/to/test-file.pdf', 'document.pdf');
```

### Test Different Transports

The benchmark currently uses SMTP. You can modify it to test HTTP APIs:

```php
// In benchmarkModern(), replace DSN with:
$dsn = 'brevo://YOUR_API_KEY@default';
// or
$dsn = 'postmark://YOUR_TOKEN@default';
```

## Best Practices

1. **Use a test email** - Don't spam real users with benchmark emails
2. **Start small** - Begin with 5 emails to verify configuration
3. **Check spam folders** - Test emails may be flagged as spam
4. **Run multiple times** - Network conditions vary; average multiple runs
5. **Monitor SMTP limits** - Respect your SMTP provider's rate limits

## Interpreting Results

### Modern is Significantly Faster (>10%)
✅ **Great!** You're benefiting from concurrent HTTP and optimized architecture.

### Performance is Similar (<5% difference)
✅ **Normal.** SMTP latency often dominates. Modern architecture still provides better code structure.

### Legacy is Faster
⚠️ **Investigate.** This is unusual. Check:
- PHP version (8.1+ recommended)
- Network conditions
- SMTP server performance
- System load

## Next Steps

After benchmarking:

1. **Review the results** - Understand where time is spent
2. **Consider HTTP APIs** - For better performance, try Brevo, Postmark, or SES
3. **Optimize your code** - Use the modern architecture for new projects
4. **Monitor production** - Enable telemetry middleware for ongoing monitoring

## Support

For issues or questions:
- Check the main [Usage Guide](docs/USAGE.md)
- Review [MIGRATION.md](MIGRATION.md) for migration guidance
- See `/examples` for usage examples

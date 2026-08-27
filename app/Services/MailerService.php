<?php
declare(strict_types=1);

/**
 * Development-friendly mailer.
 *
 * MAIL_DRIVER (in .env) selects the transport:
 *   log (default) - messages are written as .eml files under storage/mail
 *                   and are viewable in Admin -> Dev Mailbox (perfect for
 *                   localhost: nothing external is needed)
 *   mail           - PHP mail() (sendmail/Mercury on XAMPP, etc.)
 *   smtp           - raw-socket SMTP client for local catch-all servers
 *                   such as MailHog / Papercut / smtp4dev
 *                   (MAIL_HOST=127.0.0.1, MAIL_PORT=1025)
 *
 * send() never throws: delivery failures are logged and false is returned so
 * OTP flows are never broken by a mail problem.
 */
final class MailerService
{
    public static function driver(): string
    {
        $d = strtolower(trim((string) env('MAIL_DRIVER', 'log')));
        return in_array($d, ['log', 'mail', 'smtp'], true) ? $d : 'log';
    }

    public static function from(): string
    {
        return trim((string) env('MAIL_FROM', 'no-reply@commserve.test'));
    }

    public static function send(string $to, string $subject, string $body): bool
    {
        $to = trim($to);
        if ($to === '') return false;
        try {
            return match (self::driver()) {
                'mail' => self::viaMail($to, $subject, $body),
                'smtp' => self::viaSmtp($to, $subject, $body),
                default => self::viaLog($to, $subject, $body),
            };
        } catch (Throwable $e) {
            error_log('CommServe mailer: ' . $e->getMessage());
            return false;
        }
    }

    /** Write an .eml file to storage/mail (always viewable in the Dev Mailbox). */
    public static function viaLog(string $to, string $subject, string $body): bool
    {
        $dir = self::mailDir();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $eml = "From: " . self::from() . "\r\n"
             . "To: $to\r\n"
             . "Subject: $subject\r\n"
             . "Date: " . date('r') . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "\r\n"
             . $body . "\r\n";
        $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        return (bool)@file_put_contents($file, $eml);
    }

    private static function viaMail(string $to, string $subject, string $body): bool
    {
        $headers = "From: " . self::from() . "\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        if (@mail($to, $subject, $body, $headers)) return true;
        // Fall back to the log driver so the message is never lost locally.
        return self::viaLog($to, $subject, $body);
    }

    /** Minimal SMTP client (no TLS required; suits local catch-all servers). */
    private static function viaSmtp(string $to, string $subject, string $body): bool
    {
        $host = (string) env('MAIL_HOST', '127.0.0.1');
        $port = (int) env('MAIL_PORT', 1025);
        $fp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 3);
        if (!$fp) throw new RuntimeException("SMTP connect failed: $errstr");
        stream_set_timeout($fp, 3);
        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $cmd = static function (string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };
        $from = self::from();
        $read();                                   // banner
        $cmd('HELO localhost');                    // 250
        $cmd("MAIL FROM:<$from>");                 // 250
        $rcpt = $cmd("RCPT TO:<$to>");             // 250/251
        if (!str_starts_with($rcpt, '2')) throw new RuntimeException('RCPT rejected: ' . trim($rcpt));
        $cmd('DATA');                              // 354
        $msg = "From: $from\r\nTo: $to\r\nSubject: $subject\r\nDate: " . date('r') . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$body\r\n.";
        $resp = $cmd($msg);                        // 250
        $cmd('QUIT');
        fclose($fp);
        if (!str_starts_with($resp, '2')) throw new RuntimeException('DATA rejected: ' . trim($resp));
        return true;
    }

    public static function mailDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/mail';
    }

    /** Newest-first list of logged messages for the Dev Mailbox page. */
    public static function inbox(int $limit = 50): array
    {
        $dir = self::mailDir();
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/*.eml') ?: [];
        rsort($files);
        $out = [];
        foreach (array_slice($files, 0, $limit) as $f) {
            $raw = (string)@file_get_contents($f);
            $head = trim((string)strstr($raw, "\r\n\r\n", true) ?: '');
            $body = trim((string)strstr($raw, "\r\n\r\n") ?: '');
            $out[] = [
                'file' => basename($f),
                'to' => self::header($head, 'To'),
                'subject' => self::header($head, 'Subject'),
                'date' => self::header($head, 'Date'),
                'body' => trim(str_replace("\r\n", "\n", $body)),
            ];
        }
        return $out;
    }

    public static function clearInbox(): int
    {
        $dir = self::mailDir();
        if (!is_dir($dir)) return 0;
        $n = 0;
        foreach (glob($dir . '/*.eml') ?: [] as $f) { if (@unlink($f)) $n++; }
        return $n;
    }

    private static function header(string $head, string $name): string
    {
        if (preg_match('/^' . $name . ':\s*(.+)$/im', $head, $m)) return trim($m[1]);
        return '';
    }
}

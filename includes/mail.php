<?php
// Minimal SMTP client (no Composer): sends one HTML+text email through the server in config 'mail'.
// Used for the "Forgot password?" code. Gmail needs an App Password (Google Account > Security > 2-Step Verification).

declare(strict_types=1);

function mail_configured(): bool
{
    $m = config('mail');
    return $m['host'] !== '' && $m['user'] !== '' && $m['pass'] !== '';
}

/** Why the last send_mail() failed (for tools/test-mail.php; the web UI only shows a generic message). */
function mail_last_error(): string
{
    return $GLOBALS['setlo_mail_error'] ?? '';
}

/** Send an email over SMTP. Returns false (and logs why) on any failure; never throws. */
function send_mail(string $to, string $subject, string $html, string $text): bool
{
    $GLOBALS['setlo_mail_error'] = '';
    if (!mail_configured() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['setlo_mail_error'] = 'SMTP_USER / SMTP_PASS are empty (config/local.php), or the recipient address is invalid.';
        return false;
    }
    $m = config('mail');
    $from = $m['from'] !== '' ? $m['from'] : $m['user'];
    $fp = null;
    try {
        $fp = @stream_socket_client(
            ($m['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $m['host'] . ':' . $m['port'],
            $errno, $errstr, 15, STREAM_CLIENT_CONNECT
        );
        if (!$fp) {
            throw new RuntimeException("connect to {$m['host']}:{$m['port']} failed: $errstr");
        }
        stream_set_timeout($fp, 15);

        $read = function () use ($fp): array {
            $code = 0;
            $all = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $all .= $line;
                $code = (int) substr($line, 0, 3);
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return [$code, trim($all)];
        };
        $cmd = function (string $line, array $ok) use ($fp, $read): string {
            fwrite($fp, $line . "\r\n");
            [$code, $reply] = $read();
            if (!in_array($code, $ok, true)) {
                throw new RuntimeException('SMTP said: ' . $reply);
            }
            return $reply;
        };

        [$code, $greeting] = $read();
        if ($code !== 220) {
            throw new RuntimeException('SMTP greeting: ' . $greeting);
        }
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $cmd("EHLO $host", [250]);
        if ($m['secure'] === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('could not start TLS (is the openssl extension on?)');
            }
            $cmd("EHLO $host", [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($m['user']), [334]);
        $cmd(base64_encode($m['pass']), [235]);
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);

        $boundary = 'b' . bin2hex(random_bytes(12));
        $fromName = '=?UTF-8?B?' . base64_encode($m['from_name']) . '?=';
        $headers = [
            "From: $fromName <$from>",
            "To: <$to>",
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . preg_replace('/[^A-Za-z0-9.-]/', '', $host) . '>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        ];
        $part = fn (string $type, string $body) => "--$boundary\r\nContent-Type: $type; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body));
        $message = implode("\r\n", $headers) . "\r\n\r\n"
            . $part('text/plain', $text) . $part('text/html', $html) . "--$boundary--\r\n";
        // Base64 bodies have no lines starting with "." so no dot-stuffing is needed.
        fwrite($fp, $message . "\r\n.\r\n");
        [$code, $reply] = $read();
        if ($code !== 250) {
            throw new RuntimeException('message rejected: ' . $reply);
        }
        $cmd('QUIT', [221]);
        return true;
    } catch (Throwable $e) {
        $GLOBALS['setlo_mail_error'] = $e->getMessage();
        error_log('[setlo] mail: ' . $e->getMessage());
        return false;
    } finally {
        if (is_resource($fp)) {
            fclose($fp);
        }
    }
}

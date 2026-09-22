<?php
declare(strict_types=1);
namespace AV\Model;

class Mailer
{
    private array $cfg;
    private Logger $log;

    public function __construct(array $cfg, Logger $log)
    {
        $this->cfg = $cfg;
        $this->log = $log;
    }

    public function send(string $subject, string $body): bool
    {
        if (empty($this->cfg['enabled'])) {
            return false;
        }
        $to = $this->cfg['to'];
        $from = $this->cfg['from'];
        $prefix = $this->cfg['subject_prefix'] ?? '';

        if (!empty($this->cfg['smtp'])) {
            return $this->sendSmtp($to, $from, $prefix . $subject, $body);
        }

        $headers = "From: $from\r\n";
        $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
        $ok = @mail($to, $prefix . $subject, $body, $headers);
        if (!$ok) {
            $this->log->warn("Не удалось отправить email (mail()): $subject");
        }
        return (bool) $ok;
    }

    private function sendSmtp(string $to, string $from, string $subject, string $body): bool
    {
        $s = $this->cfg['smtp'];
        $host = $s['host'];
        $port = (int) ($s['port'] ?? 587);
        $secure = $s['secure'] ?? 'tls';
        $transport = $secure === 'ssl' ? 'ssl://' : '';
        $sock = @fsockopen($transport . $host, $port, $errno, $errstr, 10);
        if (!$sock) {
            $this->log->warn("SMTP connect failed: $errstr ($errno)");
            return false;
        }
        $this->smtpCmd($sock, null);
        $this->smtpCmd($sock, "EHLO localhost");
        if ($secure === 'tls') {
            $this->smtpCmd($sock, "STARTTLS");
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->log->warn("SMTP STARTTLS failed");
                fclose($sock);
                return false;
            }
            $this->smtpCmd($sock, "EHLO localhost");
        }
        $this->smtpCmd($sock, "AUTH LOGIN");
        $this->smtpCmd($sock, base64_encode($s['user']));
        $this->smtpCmd($sock, base64_encode($s['pass']));
        $this->smtpCmd($sock, "MAIL FROM:<$from>");
        $this->smtpCmd($sock, "RCPT TO:<$to>");
        $this->smtpCmd($sock, "DATA");
        fwrite($sock, "From: $from\r\nTo: $to\r\nSubject: $subject\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n$body\r\n.\r\n");
        $this->smtpCmd($sock, "QUIT");
        fclose($sock);
        return true;
    }

    private function smtpCmd($sock, ?string $cmd): string
    {
        if ($cmd !== null) {
            fwrite($sock, $cmd . "\r\n");
        }
        $resp = '';
        while (($line = fgets($sock, 515)) !== false) {
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $resp;
    }
}

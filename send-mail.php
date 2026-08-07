<?php
/**
 * UKCADStudio — contact form handler
 * -----------------------------------------------------------------------------
 * Sends every submission to the address in RECIPIENT below.
 *
 * TWO SENDING MODES
 *   1. SMTP  (recommended)  — set SMTP_ENABLED to true and fill in the details.
 *   2. mail() (fallback)    — used automatically if SMTP is disabled.
 *
 * GMAIL SETUP (because the recipient is a Gmail address)
 *   Gmail will NOT accept your normal account password from a script. You need
 *   an "App Password":
 *     1. Turn on 2-Step Verification:  https://myaccount.google.com/security
 *     2. Create an App Password:       https://myaccount.google.com/apppasswords
 *     3. Paste the 16-character password into SMTP_PASS below.
 *     4. Set SMTP_USER to the same Gmail address.
 *
 *   Leave SMTP_FROM as your own domain address if your host supports it, or set
 *   it to the Gmail address. Sending "From" an address you do not control causes
 *   SPF/DMARC failures and lands mail in spam — the visitor's address goes in
 *   Reply-To instead, which is the correct approach.
 *
 * SECURITY: never commit real credentials to a public repository. If you can,
 * move the values below into environment variables via getenv().
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// Never allow a PHP notice or warning to corrupt the JSON response body.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* ============================== CONFIGURATION ============================== */

const RECIPIENT      = 'raotipusultaan@gmail.com';
const RECIPIENT_NAME = 'UKCADStudio';

const SMTP_ENABLED = false;                    // <-- set to true once configured
const SMTP_HOST    = 'smtp.gmail.com';
const SMTP_PORT    = 587;                      // 587 = STARTTLS, 465 = implicit TLS
const SMTP_SECURE  = 'tls';                    // 'tls' for 587, 'ssl' for 465
const SMTP_USER    = 'raotipusultaan@gmail.com';
const SMTP_PASS    = 'PASTE-YOUR-16-CHAR-APP-PASSWORD-HERE';

const SMTP_FROM      = 'raotipusultaan@gmail.com';
const SMTP_FROM_NAME = 'UKCADStudio Website';

const SUCCESS_REDIRECT = 'thank-you.html';     // used only when JavaScript is off
const RATE_LIMIT_MAX   = 5;                    // max submissions per IP...
const RATE_LIMIT_SECS  = 3600;                 // ...per this many seconds
const MIN_FILL_SECONDS = 3;                    // reject forms submitted faster

/* ============================================================================ */

// ---------------------------------------------------------------- helpers

function wants_json(): bool
{
    $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return strtolower($xhr) === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function respond(bool $ok, string $message, int $status = 200): never
{
    if (wants_json()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ok) {
        header('Location: ' . SUCCESS_REDIRECT);
        exit;
    }

    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Message not sent</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 20px">'
       . '<h1 style="font-size:1.4rem">We could not send your message</h1>'
       . '<p style="color:#475569">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p style="color:#475569">Please email us directly at '
       . '<a href="mailto:' . RECIPIENT . '">' . RECIPIENT . '</a>.</p>'
       . '<p><a href="contact.html">Back to the contact page</a></p></div>';
    exit;
}

/** Strip CR/LF so user input can never inject extra mail headers. */
function clean_header(string $value): string
{
    return trim(str_replace(["\r", "\n", "%0a", "%0d", "\0"], '', $value));
}

function field(string $name, int $maxLength = 2000): string
{
    $raw = $_POST[$name] ?? '';
    if (!is_string($raw)) {
        return '';
    }
    $value = trim($raw);
    if (function_exists('mb_substr')) {
        $value = mb_substr($value, 0, $maxLength);
    } else {
        $value = substr($value, 0, $maxLength);
    }
    return $value;
}

/** Character count that works whether or not the mbstring extension is present. */
function str_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function client_ip(): string
{
    return (string) filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
}

/** File-based rate limiting. Fails open if the directory is not writable. */
function rate_limited(): bool
{
    $dir = sys_get_temp_dir() . '/ukcad-form';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }

    $file = $dir . '/' . hash('sha256', client_ip()) . '.json';
    $now  = time();
    $hits = [];

    if (is_readable($file)) {
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (is_array($decoded)) {
            $hits = array_filter($decoded, static fn($t) => is_int($t) && ($now - $t) < RATE_LIMIT_SECS);
        }
    }

    if (count($hits) >= RATE_LIMIT_MAX) {
        return true;
    }

    $hits[] = $now;
    @file_put_contents($file, json_encode(array_values($hits)), LOCK_EX);
    return false;
}

// ---------------------------------------------------------------- SMTP client

final class SmtpMailer
{
    private $socket;

    public function __construct(
        private string $host,
        private int $port,
        private string $secure,
        private string $user,
        private string $pass,
        private int $timeout = 20
    ) {}

    public function send(
        string $fromEmail, string $fromName,
        string $toEmail, string $toName,
        string $subject, string $textBody, string $htmlBody,
        string $replyTo = '', string $replyToName = ''
    ): bool {
        $transport = $this->secure === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);

        $this->socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno, $errstr, $this->timeout,
            STREAM_CLIENT_CONNECT, $context
        );

        if (!$this->socket) {
            error_log("SMTP connect failed: {$errstr} ({$errno})");
            return false;
        }
        stream_set_timeout($this->socket, $this->timeout);

        try {
            $this->expect('220');
            $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $this->cmd("EHLO {$hostname}", '250');

            if ($this->secure === 'tls') {
                $this->cmd('STARTTLS', '220');
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (!stream_socket_enable_crypto($this->socket, true, $crypto)) {
                    throw new RuntimeException('Unable to start TLS');
                }
                $this->cmd("EHLO {$hostname}", '250');
            }

            if ($this->user !== '') {
                $this->cmd('AUTH LOGIN', '334');
                $this->cmd(base64_encode($this->user), '334');
                $this->cmd(base64_encode($this->pass), '235');
            }

            $this->cmd('MAIL FROM:<' . $fromEmail . '>', '250');
            $this->cmd('RCPT TO:<' . $toEmail . '>', '250');
            $this->cmd('DATA', '354');
            $this->write($this->buildMessage(
                $fromEmail, $fromName, $toEmail, $toName,
                $subject, $textBody, $htmlBody, $replyTo, $replyToName
            ) . "\r\n.\r\n");
            $this->expect('250');
            $this->write("QUIT\r\n");

            return true;
        } catch (Throwable $e) {
            error_log('SMTP error: ' . $e->getMessage());
            return false;
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
        }
    }

    private function buildMessage(
        string $fromEmail, string $fromName, string $toEmail, string $toName,
        string $subject, string $text, string $html, string $replyTo, string $replyToName
    ): string {
        $boundary = 'bnd_' . bin2hex(random_bytes(12));
        $encName  = static fn(string $n): string => '=?UTF-8?B?' . base64_encode($n) . '?=';

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $encName($fromName) . ' <' . $fromEmail . '>',
            'To: ' . $encName($toName) . ' <' . $toEmail . '>',
            'Subject: ' . $encName($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        if ($replyTo !== '') {
            $label = $replyToName !== '' ? $encName($replyToName) . ' ' : '';
            $headers[] = 'Reply-To: ' . $label . '<' . $replyTo . '>';
        }

        $body = "--{$boundary}\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text)) . "\r\n"
              . "--{$boundary}\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html)) . "\r\n"
              . "--{$boundary}--";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function write(string $data): void
    {
        if (fwrite($this->socket, $data) === false) {
            throw new RuntimeException('Failed writing to SMTP socket');
        }
    }

    private function cmd(string $command, string $expected): void
    {
        $this->write($command . "\r\n");
        $this->expect($expected);
    }

    private function expect(string $code): void
    {
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if (!str_starts_with($response, $code)) {
            throw new RuntimeException("Expected {$code}, got: " . trim($response));
        }
    }
}

// ---------------------------------------------------------------- request flow

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(false, 'This endpoint only accepts form submissions.', 405);
}

// Spam trap: hidden field that only bots fill in.
if (field('website') !== '') {
    respond(true, 'Thank you — your enquiry has been sent.');
}

// Speed trap: a human cannot complete the form this fast.
$ts = (int) field('ts', 20);
if ($ts > 0 && (time() - $ts) < MIN_FILL_SECONDS) {
    respond(false, 'That was submitted a little too quickly. Please try again.', 429);
}

if (rate_limited()) {
    respond(false, 'Too many enquiries from this connection. Please try again later or email us directly.', 429);
}

$name     = field('name', 120);
$email    = field('email', 190);
$phone    = field('phone', 40);
$postcode = field('postcode', 20);
$service  = field('service', 120);
$message  = field('message', 5000);
$consent  = field('consent', 10) !== '';
$pageFrom = field('page', 200);

$errors = [];
if ($name === '')                                          { $errors[] = 'your name'; }
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'a valid email address'; }
if ($message === '' || str_len($message) < 10)           { $errors[] = 'a short description of your project'; }
if (!$consent)                                             { $errors[] = 'your consent to be contacted'; }

if ($errors) {
    respond(false, 'Please provide ' . implode(', ', $errors) . '.', 422);
}

$email = clean_header($email);
$name  = clean_header($name);

// ---------------------------------------------------------------- compose

$subject = 'Website enquiry — ' . ($service !== '' ? $service : 'General') . ' — ' . $name;

$rows = [
    'Name'            => $name,
    'Email'           => $email,
    'Phone'           => $phone !== '' ? $phone : '—',
    'Project postcode'=> $postcode !== '' ? $postcode : '—',
    'Service required'=> $service !== '' ? $service : '—',
    'Submitted from'  => $pageFrom !== '' ? $pageFrom : '—',
    'IP address'      => client_ip(),
    'Received'        => date('d/m/Y H:i') . ' (server time)',
];

$text = "New enquiry from the UKCADStudio website\n"
      . str_repeat('=', 44) . "\n\n";
foreach ($rows as $label => $value) {
    $text .= str_pad($label . ':', 20) . $value . "\n";
}
$text .= "\nProject details\n" . str_repeat('-', 44) . "\n" . $message . "\n";

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#111827;max-width:640px">'
      . '<h2 style="color:#0f172a;border-bottom:3px solid #2563eb;padding-bottom:10px">New website enquiry</h2>'
      . '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:14px">';
foreach ($rows as $label => $value) {
    $html .= '<tr>'
           . '<td style="background:#f8fafc;border:1px solid #e5e7eb;font-weight:bold;width:170px">' . $esc($label) . '</td>'
           . '<td style="border:1px solid #e5e7eb">' . $esc($value) . '</td>'
           . '</tr>';
}
$html .= '</table>'
       . '<h3 style="color:#0f172a;margin-top:24px">Project details</h3>'
       . '<div style="background:#f8fafc;border-left:3px solid #c8a96a;padding:14px 16px;white-space:pre-wrap;font-size:14px">'
       . nl2br($esc($message)) . '</div>'
       . '<p style="color:#64748b;font-size:12px;margin-top:22px">Reply directly to this email to respond to '
       . $esc($name) . '.</p></div>';

// ---------------------------------------------------------------- send

$sent = false;

if (SMTP_ENABLED) {
    $mailer = new SmtpMailer(SMTP_HOST, SMTP_PORT, SMTP_SECURE, SMTP_USER, SMTP_PASS);
    $sent = $mailer->send(
        SMTP_FROM, SMTP_FROM_NAME,
        RECIPIENT, RECIPIENT_NAME,
        $subject, $text, $html,
        $email, $name
    );
} else {
    $boundary = 'bnd_' . bin2hex(random_bytes(12));
    $headers  = implode("\r\n", [
        'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM . '>',
        'Reply-To: ' . $name . ' <' . $email . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: UKCADStudio-Form',
    ]);
    $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$text}\r\n"
          . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n"
          . "--{$boundary}--";

    $sent = @mail(RECIPIENT, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . SMTP_FROM);
}

if (!$sent) {
    error_log('UKCADStudio form: delivery failed for ' . $email);
    respond(false, 'Your message could not be delivered right now. Please email us directly at ' . RECIPIENT . '.', 500);
}

respond(true, 'Thank you — your enquiry has been sent. We will reply within one working day.');

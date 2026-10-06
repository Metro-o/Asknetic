<?php
/**
 * Contact form endpoint for contact.html.
 *
 * Accepts POST fields name, email, message and bot_field (honeypot), validates them and
 * sends the enquiry via authenticated SMTP (PHPMailer) to a fixed recipient. Always replies with JSON:
 *   200 {"success": true}
 *   405 / 403 / 422 / 500 {"success": false, "message": "..."}
 *
 * SMTP credentials are NOT stored here. They are read from a private config file outside the
 * web root and/or environment variables; see private/README.md for setup.
 *
 * Requires PHP 7.4+ and PHPMailer 6.x or 7.x.
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

const RECIPIENT_EMAIL = 'info@askneticgroup.co.tz';
const MAIL_SUBJECT    = 'New Website Enquiry – Asknetic Group';
const MAIL_TIMEZONE   = 'Africa/Dar_es_Salaam';
const MAX_LENGTHS     = ['name' => 100, 'email' => 254, 'message' => 5000];
const CONFIG_KEYS     = [
    'SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_ENCRYPTION',
    'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME', 'ALLOWED_ORIGINS',
];

// Never print PHP errors to the visitor; they still go to the server's error log.
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function fail(int $status, string $message): void
{
    respond($status, ['success' => false, 'message' => $message]);
}

function logError(string $message): void
{
    error_log('[asknetic contact] ' . $message);
}

/** Directory outside the web root that holds mail-config.php and PHPMailer. */
function privateDir(): string
{
    $dir = getenv('ASKNETIC_PRIVATE_DIR');
    // Default: /home/ACCOUNT/asknetic-private when this file is /home/ACCOUNT/public_html/api/contact.php
    return rtrim($dir !== false && $dir !== '' ? $dir : dirname(__DIR__, 2) . '/asknetic-private', '/\\');
}

/** Settings from the private config file, overridden by environment variables of the same name. */
function loadConfig(): array
{
    $file = privateDir() . '/mail-config.php';
    $config = is_file($file) ? require $file : [];
    if (!is_array($config)) {
        $config = [];
    }
    foreach (CONFIG_KEYS as $key) {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            $config[$key] = $env;
        }
    }
    return $config;
}

/** Loads PHPMailer from Composer (private/vendor) or a manual upload (private/PHPMailer/src). */
function loadPhpMailer(): bool
{
    $dir = privateDir();
    if (is_file($dir . '/vendor/autoload.php')) {
        require_once $dir . '/vendor/autoload.php';
    } elseif (is_file($dir . '/PHPMailer/src/PHPMailer.php')) {
        require_once $dir . '/PHPMailer/src/Exception.php';
        require_once $dir . '/PHPMailer/src/PHPMailer.php';
        require_once $dir . '/PHPMailer/src/SMTP.php';
    }
    return class_exists(PHPMailer::class);
}

function hostOf(string $url): string
{
    $host = parse_url(strpos($url, '//') === false ? '//' . $url : $url, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
}

/**
 * Same-origin check. Browsers send Origin on POST (and Referer by default), so a request made from
 * another website's page is rejected. Requests with neither header (or "Origin: null") cannot be
 * attributed and are allowed through; the remaining validation and honeypot still apply to them.
 */
function isAllowedOrigin(array $config): bool
{
    $source = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($source === '' || $source === 'null') {
        $source = $_SERVER['HTTP_REFERER'] ?? '';
    }
    if ($source === '') {
        return true;
    }
    $allowed = [hostOf($_SERVER['HTTP_HOST'] ?? '')];
    foreach (explode(',', (string) ($config['ALLOWED_ORIGINS'] ?? '')) as $extra) {
        $allowed[] = hostOf(trim($extra));
    }
    $host = hostOf($source);
    return $host !== '' && in_array($host, array_filter($allowed), true);
}

/** Trimmed string value of a POST field ('' if missing or not a string). */
function postField(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

/** Length in characters, or -1 if the value is not valid UTF-8. */
function charLength(string $value): int
{
    $count = preg_match_all('/./su', $value);
    return $count === false ? -1 : $count;
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        fail(405, 'Method not allowed.');
    }

    $config = loadConfig();

    if (!isAllowedOrigin($config)) {
        fail(403, 'Request not allowed.');
    }

    // Honeypot: real visitors never see this field. Bots get a normal-looking success and no email is sent.
    if (postField('bot_field') !== '') {
        respond(200, ['success' => true]);
    }

    $name    = postField('name');
    $email   = postField('email');
    $message = str_replace(["\r\n", "\r"], "\n", postField('message'));
    // Drop control characters other than newline and tab from the message.
    $message = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message);

    $valid = true;
    foreach (['name' => $name, 'email' => $email, 'message' => $message] as $key => $value) {
        $length = charLength($value);
        if ($length < 1 || $length > MAX_LENGTHS[$key]) {
            $valid = false;
        }
    }
    // Name and email end up in mail headers (Reply-To): no line breaks or other control characters.
    if (preg_match('/[\x00-\x1F\x7F]/', $name . $email)) {
        $valid = false;
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $valid = false;
    }
    if (!$valid) {
        fail(422, 'Please check your details and try again.');
    }

    $smtpHost   = (string) ($config['SMTP_HOST'] ?? '');
    $smtpPort   = (int) ($config['SMTP_PORT'] ?? 0);
    $smtpUser   = (string) ($config['SMTP_USERNAME'] ?? '');
    $smtpPass   = (string) ($config['SMTP_PASSWORD'] ?? '');
    $encryption = strtolower((string) ($config['SMTP_ENCRYPTION'] ?? ''));
    $fromEmail  = (string) ($config['SMTP_FROM_EMAIL'] ?? $smtpUser);
    $fromName   = (string) ($config['SMTP_FROM_NAME'] ?? 'Asknetic Group Website');

    if ($smtpHost === '' || $smtpPort < 1 || $smtpUser === '' || $smtpPass === ''
        || !in_array($encryption, ['ssl', 'tls'], true)
        || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
        logError('SMTP configuration missing or incomplete (check mail-config.php in the private folder or the environment variables).');
        fail(500, 'Unable to send your message.');
    }
    if (!loadPhpMailer()) {
        logError('PHPMailer not found in the private folder (vendor/autoload.php or PHPMailer/src).');
        fail(500, 'Unable to send your message.');
    }

    $sentAt = (new DateTimeImmutable('now', new DateTimeZone(MAIL_TIMEZONE)))->format('j F Y, H:i T');

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1f2937">'
        . '<h2 style="font-size:18px;margin:0 0 16px">New website enquiry</h2>'
        . '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse">'
        . '<tr><td style="font-weight:bold;vertical-align:top">Name</td><td>' . escapeHtml($name) . '</td></tr>'
        . '<tr><td style="font-weight:bold;vertical-align:top">Email</td><td><a href="mailto:' . escapeHtml($email) . '">' . escapeHtml($email) . '</a></td></tr>'
        . '<tr><td style="font-weight:bold;vertical-align:top">Submitted</td><td>' . escapeHtml($sentAt) . '</td></tr>'
        . '</table>'
        . '<h3 style="font-size:15px;margin:20px 0 8px">Message</h3>'
        . '<div style="border-left:3px solid #f97316;padding-left:12px">' . nl2br(escapeHtml($message), false) . '</div>'
        . '<p style="font-size:12px;color:#6b7280;margin-top:24px">Sent from the contact form on askneticgroup.co.tz. Reply to this email to respond to the sender.</p>'
        . '</div>';

    $text = "New website enquiry\n\n"
        . "Name: {$name}\n"
        . "Email: {$email}\n"
        . "Submitted: {$sentAt}\n\n"
        . "Message:\n{$message}\n\n"
        . "-- \nSent from the contact form on askneticgroup.co.tz. Reply to this email to respond to the sender.\n";

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $smtpHost;
    $mail->Port       = $smtpPort;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;
    $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPDebug  = 0;
    $mail->Timeout    = 10; // the browser gives up after 15 s
    $mail->CharSet    = PHPMailer::CHARSET_UTF8;

    // From is always the authenticated ASKNETIC mailbox (SPF/DKIM/DMARC); the visitor is only Reply-To.
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress(RECIPIENT_EMAIL);
    $mail->addReplyTo($email, $name);

    $mail->isHTML(true);
    $mail->Subject = MAIL_SUBJECT;
    $mail->Body    = $html;
    $mail->AltBody = $text;

    $mail->send();
    respond(200, ['success' => true]);
} catch (Throwable $e) {
    $detail = isset($mail) && $mail instanceof PHPMailer && $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
    logError(get_class($e) . ': ' . $detail);
    fail(500, 'Unable to send your message.');
}

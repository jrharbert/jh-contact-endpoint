<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function jsonResponse(bool $success, string $error = '', int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo $success
        ? json_encode(['success' => true])
        : json_encode(['success' => false, 'error' => $error]);
    exit;
}

// ---------------------------------------------------------------------------
// Load environment
// ---------------------------------------------------------------------------

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$turnstileEnabled = filter_var($_ENV['TURNSTILE_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

// ---------------------------------------------------------------------------
// CORS
// ---------------------------------------------------------------------------

$allowedOrigins = ['https://joshuaharbert.com'];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$originAllowed = in_array($origin, $allowedOrigins, true)
    || (!$turnstileEnabled && (bool) preg_match('/^https?:\/\/localhost(:\d+)?$/', $origin));

if ($originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.', 405);
}

// ---------------------------------------------------------------------------
// Rate limiting — file-based, max 10 submissions per IP per hour
// ---------------------------------------------------------------------------

$ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rlDir    = sys_get_temp_dir() . '/jh_contact_rate_limits';
$rlFile   = $rlDir . '/' . md5($ip) . '.json';
$rlMax    = 10;
$rlWindow = 3600; // seconds
$now      = time();

if (!is_dir($rlDir)) {
    mkdir($rlDir, 0700, true);
}

$timestamps = [];
if (file_exists($rlFile)) {
    $stored = json_decode((string) file_get_contents($rlFile), true);
    if (is_array($stored)) {
        // Discard entries outside the rolling window
        $timestamps = array_values(array_filter($stored, fn($t) => ($now - $t) < $rlWindow));
    }
}

if (count($timestamps) >= $rlMax) {
    jsonResponse(false, 'Too many requests. Please try again later.', 429);
}

// ---------------------------------------------------------------------------
// Input validation
// ---------------------------------------------------------------------------

$name             = trim((string) ($_POST['name']                  ?? ''));
$email            = trim((string) ($_POST['email']                 ?? ''));
$message          = trim((string) ($_POST['message']               ?? ''));
$turnstileToken   =       (string) ($_POST['cf-turnstile-response'] ?? '');

if ($name === '') {
    jsonResponse(false, 'Name is required.', 422);
}
if (strlen($name) > 100) {
    jsonResponse(false, 'Name must be 100 characters or fewer.', 422);
}

if ($email === '') {
    jsonResponse(false, 'Email is required.', 422);
}
if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    jsonResponse(false, 'A valid email address is required.', 422);
}

if ($message === '') {
    jsonResponse(false, 'Message is required.', 422);
}
if (strlen($message) > 5000) {
    jsonResponse(false, 'Message must be 5000 characters or fewer.', 422);
}

// Strip newlines from name so it cannot be injected into mail headers
$name = str_replace(["\r", "\n"], ' ', $name);

// ---------------------------------------------------------------------------
// Cloudflare Turnstile verification
// ---------------------------------------------------------------------------

if ($turnstileEnabled) {
    if ($turnstileToken === '') {
        jsonResponse(false, 'Please complete the security check.', 422);
    }

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => $_ENV['TURNSTILE_SECRET_KEY'] ?? '',
            'response' => $turnstileToken,
            'remoteip' => $ip,
        ]),
        CURLOPT_TIMEOUT        => 10,
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);

    if ($raw === false) {
        jsonResponse(false, 'Could not verify security check. Please try again.', 500);
    }

    $turnstileResult = json_decode((string) $raw, true);
    if (!($turnstileResult['success'] ?? false)) {
        jsonResponse(false, 'Security check failed. Please try again.', 422);
    }
}

// ---------------------------------------------------------------------------
// Send email via PHPMailer
// ---------------------------------------------------------------------------

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = $_ENV['MAIL_HOST']     ?? 'smtp.gmail.com';
    $mail->Port       = (int) ($_ENV['MAIL_PORT'] ?? 587);
    $mail->SMTPAuth   = true;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Username   = $_ENV['MAIL_USERNAME'] ?? '';
    $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? '';

    $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'] ?? '', 'Contact Form');
    $mail->addAddress($_ENV['MAIL_USERNAME']  ?? '');
    $mail->addReplyTo($email, $name);

    $mail->Subject = 'Contact form: ' . $name;
    $mail->isHTML(false);
    $mail->Body = implode("\n", [
        'Name:    ' . $name,
        'Email:   ' . $email,
        '',
        'Message:',
        $message,
    ]);

    $mail->send();

} catch (Exception $e) {
    // Don't expose internal mail errors to the caller
    error_log('PHPMailer error: ' . $mail->ErrorInfo);
    jsonResponse(false, 'Failed to send message. Please try again later.', 500);
}

// ---------------------------------------------------------------------------
// Record this submission for rate limiting and return success
// ---------------------------------------------------------------------------

$timestamps[] = $now;
file_put_contents($rlFile, json_encode($timestamps));

jsonResponse(true);

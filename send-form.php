<?php
declare(strict_types=1);

function loadLocalEnvironment(string $path): void
{
    if (!is_readable($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) || getenv($name) !== false) continue;
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        putenv($name . '=' . $value);
    }
}

// Real server environment variables take precedence; .env is a local/config fallback.
loadLocalEnvironment(__DIR__ . DIRECTORY_SEPARATOR . '.env');

session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'use_strict_mode' => true]);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');

const FORM_ACTIONS = ['contact' => 'contact_form', 'quote' => 'quote_form'];
const RATE_LIMIT_MAX = 3;
const RATE_LIMIT_WINDOW = 600;
$turnstileVerified = false;

function respond(int $status, bool $success, string $message, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function envValue(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : trim($value);
}

function logRejection(string $reason, string $formType = 'unknown'): void
{
    error_log(sprintf('CoderVex form rejected reason=%s form=%s', $reason, preg_replace('/[^a-z_-]/', '', $formType)));
}

function reject(int $status, string $reason, string $message, string $formType = 'unknown', array $extra = []): never
{
    global $turnstileVerified;
    if ($turnstileVerified) $extra['resetTurnstile'] = true;
    logRejection($reason, $formType);
    respond($status, false, $message, $extra);
}

function inputString(string $key): string
{
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw)) return '';
    return trim(str_replace(["\r\n", "\r"], "\n", $raw));
}

function tooLong(string $value, int $max): bool { return mb_strlen($value, 'UTF-8') > $max; }
function headerSafe(string $value): bool { return !preg_match('/[\r\n\x00]/u', $value); }

function clientIp(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $trusted = array_filter(array_map('trim', explode(',', envValue('TRUSTED_PROXIES'))));
    if (!in_array($remote, $trusted, true)) return $remote;
    $forwarded = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    $candidate = trim(explode(',', $forwarded)[0] ?? '');
    return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : $remote;
}

function enforceRateLimit(string $ip, string $formType): void
{
    $directory = envValue('FORM_RATE_LIMIT_DIR', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'codervex-form-limits');
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        reject(503, 'rate_store_unavailable', 'We could not process your message right now. Please try again later.', $formType);
    }
    $file = $directory . DIRECTORY_SEPARATOR . hash('sha256', $ip) . '.json';
    $handle = fopen($file, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        reject(503, 'rate_store_unavailable', 'We could not process your message right now. Please try again later.', $formType);
    }
    $raw = stream_get_contents($handle);
    $entries = is_string($raw) ? json_decode($raw, true) : [];
    $entries = is_array($entries) ? $entries : [];
    $cutoff = time() - RATE_LIMIT_WINDOW;
    $entries = array_values(array_filter($entries, static fn($time): bool => is_int($time) && $time > $cutoff));
    if (count($entries) >= RATE_LIMIT_MAX) {
        flock($handle, LOCK_UN); fclose($handle);
        reject(429, 'rate_limited', 'Too many messages were submitted. Please try again in about 10 minutes.', $formType);
    }
    $entries[] = time();
    rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($entries)); fflush($handle);
    flock($handle, LOCK_UN); fclose($handle);
}

function verifyTurnstile(string $token, string $expectedAction, string $formType): void
{
    $secret = envValue('TURNSTILE_SECRET_KEY');
    $hostname = envValue('TURNSTILE_EXPECTED_HOSTNAME');
    if ($secret === '' || $hostname === '') {
        reject(503, 'turnstile_not_configured', 'We could not verify your submission right now. Please try again later.', $formType);
    }
    if (envValue('APP_ENV') === 'testing' && envValue('TURNSTILE_TEST_RESPONSE') !== '') {
        $result = json_decode(envValue('TURNSTILE_TEST_RESPONSE'), true);
        if ($token === '__invalid_test_token__' && is_array($result)) $result['success'] = false;
    } else {
        $payload = http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => clientIp()]);
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $payload, 'timeout' => 8, 'ignore_errors' => true]]);
        $response = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        $result = is_string($response) ? json_decode($response, true) : null;
    }
    if (!is_array($result) || ($result['success'] ?? false) !== true) {
        reject(422, 'turnstile_failed', 'Verification failed or expired. Please complete the check and try again.', $formType, ['resetTurnstile' => true]);
    }
    if (!hash_equals($hostname, (string) ($result['hostname'] ?? ''))) {
        reject(422, 'turnstile_hostname_mismatch', 'Verification failed. Please refresh the page and try again.', $formType, ['resetTurnstile' => true]);
    }
    if (!hash_equals($expectedAction, (string) ($result['action'] ?? ''))) {
        reject(422, 'turnstile_action_mismatch', 'Verification failed. Please refresh the page and try again.', $formType, ['resetTurnstile' => true]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['config'])) {
    $siteKey = envValue('TURNSTILE_SITE_KEY');
    if ($siteKey === '') respond(503, false, 'Form verification is not configured.');
    if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    respond(200, true, '', ['siteKey' => $siteKey, 'csrfToken' => $_SESSION['csrf_token']]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, false, 'Only form submissions are accepted.');

$formType = inputString('form_type');
if (!isset(FORM_ACTIONS[$formType])) reject(422, 'invalid_form_type', 'Invalid form submission.');
$csrf = inputString('csrf_token');
if ($csrf === '' || !isset($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
    reject(403, 'csrf_failed', 'Your form session expired. Please refresh the page and try again.', $formType);
}
if (inputString('website_field') !== '') reject(422, 'honeypot_filled', 'We could not accept this submission.', $formType);

enforceRateLimit(clientIp(), $formType);
$turnstileToken = inputString('cf-turnstile-response');
if ($turnstileToken === '' || tooLong($turnstileToken, 2048)) {
    reject(422, 'turnstile_missing', 'Please complete the verification check and try again.', $formType, ['resetTurnstile' => true]);
}
verifyTurnstile($turnstileToken, FORM_ACTIONS[$formType], $formType);
$turnstileVerified = true;

$name = inputString('Name'); $email = inputString('Email'); $company = inputString('Company');
$website = inputString('Website'); $consent = inputString('Consent');
if ($name === '' || mb_strlen($name) < 2 || tooLong($name, 100) || !headerSafe($name)) reject(422, 'invalid_name', 'Please enter a valid full name (2–100 characters).', $formType);
if ($email === '' || tooLong($email, 254) || !headerSafe($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) reject(422, 'invalid_email', 'Please enter a valid email address.', $formType);
if (tooLong($company, 150) || !headerSafe($company)) reject(422, 'invalid_company', 'Company must be 150 characters or fewer.', $formType);
if (tooLong($website, 500) || ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL))) reject(422, 'invalid_website', 'Please enter a valid website URL, including https://.', $formType);
if ($consent !== 'Yes') reject(422, 'consent_missing', 'Please confirm that we may use your details to respond.', $formType);

$fields = ['Form' => $formType === 'quote' ? 'Project quote request' : 'Contact message', 'Name' => $name, 'Email' => $email, 'Company' => $company !== '' ? $company : 'Not provided', 'Website' => $website !== '' ? $website : 'Not provided'];
if ($formType === 'contact') {
    $service = inputString('Service'); $message = inputString('Message');
    if ($service === '' || tooLong($service, 120) || !headerSafe($service)) reject(422, 'invalid_service', 'Please select a valid service.', $formType);
    if ($message === '' || mb_strlen($message) < 10 || tooLong($message, 5000)) reject(422, 'invalid_message', 'Please enter a message between 10 and 5,000 characters.', $formType);
    $fields['Service'] = $service; $fields['Message'] = $message; $subject = 'CoderVex contact: ' . $service;
} else {
    $rawServices = $_POST['Services'] ?? []; $rawServices = is_array($rawServices) ? $rawServices : []; $services = [];
    foreach ($rawServices as $service) {
        if (!is_string($service) || trim($service) === '' || tooLong($service, 120) || !headerSafe($service)) reject(422, 'invalid_services', 'Please select valid project services.', $formType);
        $services[] = trim($service);
    }
    $budget = inputString('Budget'); $timeline = inputString('Timeline'); $details = inputString('Project_details');
    if ($services === [] || count($services) > 10) reject(422, 'services_missing', 'Please select at least one service.', $formType);
    if ($budget === '' || tooLong($budget, 80) || !headerSafe($budget)) reject(422, 'invalid_budget', 'Please select a valid budget range.', $formType);
    if ($timeline === '' || tooLong($timeline, 100) || !headerSafe($timeline)) reject(422, 'invalid_timeline', 'Please select a valid timeline.', $formType);
    if ($details === '' || mb_strlen($details) < 10 || tooLong($details, 6000)) reject(422, 'invalid_project_details', 'Please enter project details between 10 and 6,000 characters.', $formType);
    $fields['Services'] = implode(', ', $services); $fields['Budget'] = $budget; $fields['Timeline'] = $timeline; $fields['Project details'] = $details; $subject = 'CoderVex quote request from ' . $name;
}

$lines = [];
foreach ($fields as $label => $content) $lines[] = $label . ': ' . $content;
$body = implode("\n", $lines) . "\n\nSubmitted from: " . envValue('TURNSTILE_EXPECTED_HOSTNAME', 'codervex.com');
$safeName = preg_replace('/[^\p{L}\p{N} ._-]/u', '', $name) ?: 'Website visitor';
$headers = ['From: CoderVex Website <support@codervex.com>', 'Reply-To: ' . $safeName . ' <' . $email . '>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'X-Mailer: PHP/' . PHP_VERSION];
$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$mailSent = envValue('APP_ENV') === 'testing' || mail('support@codervex.com', $encodedSubject, $body, implode("\r\n", $headers));
if (!$mailSent) {
    error_log('CoderVex form mail delivery failed form=' . $formType);
    respond(500, false, 'We could not send your message right now. Please email support@codervex.com directly.');
}
respond(200, true, 'Thank you—your message has been sent successfully.');

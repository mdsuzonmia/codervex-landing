<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $status, bool $success, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function value(string $key, int $maxLength = 200): string
{
    $input = trim((string) ($_POST[$key] ?? ''));
    $input = str_replace(["\r\n", "\r"], "\n", $input);
    return mb_substr($input, 0, $maxLength);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'Only form submissions are accepted.');
}

// Hidden field: automated bots commonly fill it, real visitors do not.
if (value('website_field') !== '') {
    respond(200, true, 'Thank you. Your message has been received.');
}

$lastSubmission = (int) ($_SESSION['last_form_submission'] ?? 0);
if ($lastSubmission > 0 && time() - $lastSubmission < 20) {
    respond(429, false, 'Please wait a moment before sending another message.');
}

$formType = value('form_type', 30);
$name = value('Name', 100);
$email = value('Email', 190);
$company = value('Company', 150);
$website = value('Website', 300);
$consent = value('Consent', 10);

if (!in_array($formType, ['contact', 'quote'], true)) {
    respond(422, false, 'Invalid form submission.');
}
if ($name === '' || mb_strlen($name) < 2) {
    respond(422, false, 'Please enter your full name.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
    respond(422, false, 'Please enter a valid email address.');
}
if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
    respond(422, false, 'Please enter a valid website URL, including https://.');
}
if ($consent !== 'Yes') {
    respond(422, false, 'Please confirm that we may use your details to respond.');
}

$lines = [
    'Form: ' . ($formType === 'quote' ? 'Project quote request' : 'Contact message'),
    'Name: ' . $name,
    'Email: ' . $email,
    'Company: ' . ($company !== '' ? $company : 'Not provided'),
    'Website: ' . ($website !== '' ? $website : 'Not provided'),
];

if ($formType === 'contact') {
    $service = value('Service', 120);
    $message = value('Message', 5000);
    if ($service === '' || $message === '') {
        respond(422, false, 'Please select a service and enter your message.');
    }
    $lines[] = 'Service: ' . $service;
    $lines[] = "\nMessage:\n" . $message;
    $subject = 'CoderVex contact: ' . $service;
} else {
    $services = $_POST['Services'] ?? [];
    if (!is_array($services)) {
        $services = [$services];
    }
    $services = array_slice(array_values(array_filter(array_map(
        static fn($item): string => mb_substr(trim((string) $item), 0, 120),
        $services
    ))), 0, 10);
    $budget = value('Budget', 80);
    $timeline = value('Timeline', 100);
    $details = value('Project_details', 6000);
    if ($services === [] || $budget === '' || $timeline === '' || $details === '') {
        respond(422, false, 'Please complete the required project fields.');
    }
    $lines[] = 'Services: ' . implode(', ', $services);
    $lines[] = 'Budget: ' . $budget;
    $lines[] = 'Timeline: ' . $timeline;
    $lines[] = "\nProject details:\n" . $details;
    $subject = 'CoderVex quote request from ' . $name;
}

$recipient = 'support@codervex.com';
$safeName = preg_replace('/[^\p{L}\p{N} ._-]/u', '', $name) ?: 'Website visitor';
$headers = [
    'From: CoderVex Website <support@codervex.com>',
    'Reply-To: ' . $safeName . ' <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . PHP_VERSION,
];

$body = implode("\n", $lines) . "\n\nSubmitted from: " . ($_SERVER['HTTP_HOST'] ?? 'codervex.com');
$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

if (!mail($recipient, $encodedSubject, $body, implode("\r\n", $headers))) {
    error_log('CoderVex form mail delivery failed for form type: ' . $formType);
    respond(500, false, 'We could not send your message right now. Please email support@codervex.com directly.');
}

$_SESSION['last_form_submission'] = time();
respond(200, true, 'Thank you—your message has been sent successfully.');

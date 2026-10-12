<?php

declare(strict_types=1);

const LEAD_FIELDS = ['painPoint', 'name', 'workEmail', 'company', 'phone'];
const LEAD_HEADERS = ['Timestamp', 'Pain Point', 'Name', 'Work Email', 'Company', 'Phone'];

function normalizeLeadValue(mixed $value, int $maximumLength = 200): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
    return function_exists('mb_substr') ? mb_substr($value, 0, $maximumLength) : substr($value, 0, $maximumLength);
}

function validateLead(array $input): array
{
    $lead = [
        'painPoint' => normalizeLeadValue($input['painPoint'] ?? ''),
        'name' => normalizeLeadValue($input['name'] ?? ''),
        'workEmail' => strtolower(normalizeLeadValue($input['workEmail'] ?? '')),
        'company' => normalizeLeadValue($input['company'] ?? ''),
        'phone' => normalizeLeadValue($input['phone'] ?? '', 40),
    ];

    $errors = [];
    foreach (LEAD_FIELDS as $field) {
        if ($lead[$field] === '') {
            $errors[$field] = 'This field is required.';
        }
    }

    if ($lead['workEmail'] !== '' && filter_var($lead['workEmail'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['workEmail'] = 'Enter a valid email address.';
    }

    if ($lead['phone'] !== '') {
        $digitCount = preg_match_all('/\d/', $lead['phone']);
        if ($digitCount < 6 || !preg_match('/^[0-9+().\-\s]+$/', $lead['phone'])) {
            $errors['phone'] = 'Enter a valid phone number.';
        }
    }

    return ['valid' => $errors === [], 'lead' => $lead, 'errors' => $errors];
}

function escapeCsvValue(string $value): string
{
    return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
}

function leadToCsvRow(array $lead, string $timestamp): array
{
    return [
        $timestamp,
        escapeCsvValue($lead['painPoint']),
        escapeCsvValue($lead['name']),
        escapeCsvValue($lead['workEmail']),
        escapeCsvValue($lead['company']),
        escapeCsvValue($lead['phone']),
    ];
}

function appendLeadToCsv(string $path, array $lead, string $timestamp): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare lead storage.');
    }

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open lead storage.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock lead storage.');
        }

        clearstatcache(true, $path);
        if (filesize($path) === 0) {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, LEAD_HEADERS, ',', '"', '');
        } else {
            fseek($handle, -1, SEEK_END);
            if (fread($handle, 1) !== "\n") {
                fseek($handle, 0, SEEK_END);
                if (fwrite($handle, "\n") === false) {
                    throw new RuntimeException('Unable to prepare lead storage.');
                }
            }
        }

        fseek($handle, 0, SEEK_END);
        if (fputcsv($handle, leadToCsvRow($lead, $timestamp), ',', '"', '') === false) {
            throw new RuntimeException('Unable to save lead.');
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function buildResendIdempotencyKey(string $submissionId): string
{
    return 'splendify-consultation-lead/' . $submissionId;
}

function normalizeRecipientEmail(string $value): string
{
    $value = trim($value);
    foreach ([['"', '"'], ["'", "'"], ['“', '”'], ['‘', '’']] as [$openingQuote, $closingQuote]) {
        if (str_starts_with($value, $openingQuote) && str_ends_with($value, $closingQuote) && strlen($value) > strlen($openingQuote) + strlen($closingQuote)) {
            return trim(substr($value, strlen($openingQuote), -strlen($closingQuote)));
        }
    }

    return $value;
}

function getResendConfiguration(?array $environment = null): array
{
    $readValue = static function (string $name) use ($environment): string {
        $value = $environment === null ? getenv($name) : ($environment[$name] ?? '');
        return is_string($value) ? trim($value) : '';
    };

    return [
        'apiKey' => $readValue('RESEND_API_KEY'),
        'from' => $readValue('RESEND_FROM_EMAIL'),
        'to' => normalizeRecipientEmail($readValue('LEAD_NOTIFICATION_EMAIL')),
    ];
}

function escapeEmailHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function buildLeadNotificationPayload(array $lead, string $timestamp, string $from, string $to): array
{
    $rows = [
        'Submission timestamp' => $timestamp,
        'Pain point' => $lead['painPoint'],
        'Name' => $lead['name'],
        'Work email' => $lead['workEmail'],
        'Company' => $lead['company'],
        'Phone' => $lead['phone'],
    ];
    $htmlRows = '';
    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr><th align="left" style="padding:6px 12px 6px 0">' . escapeEmailHtml($label) . '</th><td style="padding:6px 0">' . escapeEmailHtml($value) . '</td></tr>';
    }

    return [
        'from' => $from,
        'to' => [$to],
        'subject' => 'New Splendify Consultation Lead - ' . $lead['name'],
        'html' => '<h2>New Splendify Consultation Lead</h2><table>' . $htmlRows . '</table>',
    ];
}

function postResendRequest(string $url, array $headers, string $body): array
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);
        return ['status' => $status, 'body' => is_string($responseBody) ? $responseBody : ''];
    }

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $responseBody = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status = (int) $matches[1];
            break;
        }
    }

    return ['status' => $status, 'body' => is_string($responseBody) ? $responseBody : ''];
}

function sendResendNotification(array $lead, string $timestamp, string $submissionId, ?array $environment = null, ?callable $httpPost = null): array
{
    $configuration = getResendConfiguration($environment);
    if ($configuration['apiKey'] === '' || $configuration['from'] === '' || $configuration['to'] === '') {
        return ['sent' => false, 'reason' => 'not_configured', 'status' => null, 'errorName' => null, 'errorMessage' => null];
    }

    if (filter_var($configuration['to'], FILTER_VALIDATE_EMAIL) === false) {
        return [
            'sent' => false,
            'reason' => 'invalid_recipient_configuration',
            'status' => null,
            'errorName' => 'invalid_recipient_configuration',
            'errorMessage' => 'Lead notification recipient is not a valid email address.',
        ];
    }

    $payload = buildLeadNotificationPayload($lead, $timestamp, $configuration['from'], $configuration['to']);
    $request = $httpPost ?? 'postResendRequest';
    $response = $request('https://api.resend.com/emails', [
        'Authorization: Bearer ' . $configuration['apiKey'],
        'Content-Type: application/json',
        'Idempotency-Key: ' . buildResendIdempotencyKey($submissionId),
    ], json_encode($payload, JSON_UNESCAPED_SLASHES));

    $responseData = json_decode($response['body'] ?? '', true);
    if (($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300 && is_array($responseData) && isset($responseData['id'])) {
        return ['sent' => true, 'reason' => null, 'status' => (int) $response['status'], 'errorName' => null, 'errorMessage' => null];
    }

    $errorName = is_array($responseData) ? normalizeLeadValue($responseData['name'] ?? $responseData['code'] ?? $responseData['error'] ?? '', 100) : '';
    $errorMessage = is_array($responseData) ? normalizeLeadValue($responseData['message'] ?? '', 200) : '';
    return [
        'sent' => false,
        'reason' => ($response['status'] ?? 0) === 0 ? 'network_error' : 'api_error',
        'status' => (int) ($response['status'] ?? 0),
        'errorName' => $errorName === '' ? null : $errorName,
        'errorMessage' => $errorMessage === '' ? null : $errorMessage,
    ];
}

function processLeadSubmission(array $input, string $csvPath, string $submissionId, string $timestamp, ?array $environment = null, ?callable $httpPost = null): array
{
    $validation = validateLead($input);
    if (!$validation['valid']) {
        return ['accepted' => false, 'errors' => $validation['errors']];
    }

    appendLeadToCsv($csvPath, $validation['lead'], $timestamp);
    return [
        'accepted' => true,
        'lead' => $validation['lead'],
        'notification' => sendResendNotification($validation['lead'], $timestamp, $submissionId, $environment, $httpPost),
    ];
}

function sendJsonResponse(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        sendJsonResponse(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        sendJsonResponse(400, ['ok' => false, 'message' => 'Please submit the form again.']);
    }

    $submissionId = normalizeLeadValue($payload['submissionId'] ?? '', 64);
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $submissionId) !== 1) {
        sendJsonResponse(400, ['ok' => false, 'message' => 'Please submit the form again.']);
    }

    try {
        $result = processLeadSubmission($payload, __DIR__ . '/../data/leads.csv', $submissionId, date(DATE_ATOM));
    } catch (Throwable) {
        sendJsonResponse(500, ['ok' => false, 'message' => 'We could not save your enquiry. Please try again.']);
    }

    if (!$result['accepted']) {
        sendJsonResponse(422, ['ok' => false, 'message' => 'Please check the highlighted fields.', 'errors' => $result['errors']]);
    }

    if (!$result['notification']['sent']) {
        $diagnostic = 'Lead notification was not sent (reason: ' . $result['notification']['reason'];
        if ($result['notification']['status'] !== null) {
            $diagnostic .= ', status: ' . $result['notification']['status'];
        }
        if ($result['notification']['errorName'] !== null) {
            $diagnostic .= ', resend error: ' . $result['notification']['errorName'];
        }
        if ($result['notification']['errorMessage'] !== null) {
            $diagnostic .= ', message: ' . $result['notification']['errorMessage'];
        }
        error_log($diagnostic . ').');
    }

    sendJsonResponse(201, ['ok' => true, 'message' => 'Thanks — your enquiry has been received.', 'notificationSent' => $result['notification']['sent']]);
}

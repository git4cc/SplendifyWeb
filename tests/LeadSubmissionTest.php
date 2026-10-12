<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/lead-submission.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assertTrueValue(bool $actual, string $message): void
{
    assertSameValue(true, $actual, $message);
}

function readCsvRows(string $path): array
{
    return array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), file($path, FILE_IGNORE_NEW_LINES));
}

function assertCsvHeaderOnce(string $path, string $message): void
{
    $headerCount = 0;
    foreach (readCsvRows($path) as $row) {
        $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0] ?? '');
        if ($row === LEAD_HEADERS) {
            $headerCount++;
        }
    }
    assertSameValue(1, $headerCount, $message);
}

$validLead = [
    'painPoint' => 'Want to generate more customer leads',
    'name' => 'Jane Tan',
    'workEmail' => 'jane@example.com',
    'company' => 'Example Wellness',
    'phone' => '+65 8123 4567',
];

$result = validateLead($validLead);
assertTrueValue($result['valid'], 'A complete valid lead should be accepted.');
assertSameValue('jane@example.com', $result['lead']['workEmail'], 'Email should be normalized.');

$invalid = validateLead(array_replace($validLead, ['workEmail' => 'not-an-email']));
assertSameValue(false, $invalid['valid'], 'An invalid email should be rejected.');
assertTrueValue(isset($invalid['errors']['workEmail']), 'The invalid email should have a field error.');

$acceptedPhoneFormats = [
    '84284956',
    '+65 8428 4956',
    '+1 415 555 1234',
    '8428-4956',
];

foreach ($acceptedPhoneFormats as $phone) {
    $phoneResult = validateLead(array_replace($validLead, ['phone' => $phone]));
    assertTrueValue($phoneResult['valid'], "Phone format {$phone} should be accepted.");
    assertSameValue($phone, $phoneResult['lead']['phone'], "Phone format {$phone} should be preserved.");
}

$invalidPhone = validateLead(array_replace($validLead, ['phone' => 'abc123']));
assertSameValue(false, $invalidPhone['valid'], 'Alphabetic phone values should be rejected.');
assertTrueValue(isset($invalidPhone['errors']['phone']), 'Invalid phone values should have a field error.');

$formulaLead = array_replace($validLead, ['name' => '=HYPERLINK("https://malicious.example")']);
$row = leadToCsvRow($formulaLead, '2026-09-23T12:00:00+08:00');
assertSameValue("'=HYPERLINK(\"https://malicious.example\")", $row[2], 'CSV formula-like values must be escaped.');

$environment = [
    'RESEND_API_KEY' => 're_test_key',
    'RESEND_FROM_EMAIL' => 'Splendify Leads <leads@example.test>',
    'LEAD_NOTIFICATION_EMAIL' => 'team@example.test',
];

// UNIT / REGRESSION: accidental shell or smart-quote wrapping must not reach Resend.
$recipientConfigurations = [
    'alan.teo@splendify.io' => 'alan.teo@splendify.io',
    "'alan.teo@splendify.io'" => 'alan.teo@splendify.io',
    '"alan.teo@splendify.io"' => 'alan.teo@splendify.io',
    '‘alan.teo@splendify.io’' => 'alan.teo@splendify.io',
    '“alan.teo@splendify.io”' => 'alan.teo@splendify.io',
];
foreach ($recipientConfigurations as $configuredRecipient => $expectedRecipient) {
    $configuration = getResendConfiguration(array_replace($environment, ['LEAD_NOTIFICATION_EMAIL' => $configuredRecipient]));
    assertSameValue($expectedRecipient, $configuration['to'], "Recipient {$configuredRecipient} should be normalized.");
}

$displayNameSender = getResendConfiguration(array_replace($environment, ['RESEND_FROM_EMAIL' => 'Splendify Leads <leads@splendify.io>']));
assertSameValue('Splendify Leads <leads@splendify.io>', $displayNameSender['from'], 'Display-name sender format must be preserved.');

$submissionId = '550e8400-e29b-41d4-a716-446655440000';
$emailPayload = buildLeadNotificationPayload($validLead, '2026-09-23T12:00:00+08:00', $environment['RESEND_FROM_EMAIL'], $environment['LEAD_NOTIFICATION_EMAIL']);
assertSameValue('New Splendify Consultation Lead - Jane Tan', $emailPayload['subject'], 'Notification subject should identify the lead.');
assertTrueValue(str_contains($emailPayload['html'], 'Example Wellness'), 'Notification payload should include the company.');
assertSameValue('splendify-consultation-lead/' . $submissionId, buildResendIdempotencyKey($submissionId), 'Idempotency key must be derived from the accepted submission identifier.');

$missingConfiguration = sendResendNotification($validLead, '2026-09-23T12:00:00+08:00', $submissionId, [], static fn () => throw new RuntimeException('The HTTP client must not run without configuration.'));
assertSameValue(false, $missingConfiguration['sent'], 'Missing Resend configuration should not report an email as sent.');
assertSameValue('not_configured', $missingConfiguration['reason'], 'Missing credentials should be distinguishable for development.');

$capturedRequest = null;
$sent = sendResendNotification($validLead, '2026-09-23T12:00:00+08:00', $submissionId, $environment, static function (string $url, array $headers, string $body) use (&$capturedRequest): array {
    $capturedRequest = compact('url', 'headers', 'body');
    return ['status' => 201, 'body' => '{"id":"email_123"}'];
});
assertSameValue(true, $sent['sent'], 'A successful Resend response should be reported as sent.');
assertTrueValue(in_array('Idempotency-Key: splendify-consultation-lead/' . $submissionId, $capturedRequest['headers'], true), 'Resend request must include the stable idempotency key.');
assertTrueValue(!str_contains($capturedRequest['body'], $environment['RESEND_API_KEY']), 'The API key must never be placed in the email payload.');
$capturedPayload = json_decode($capturedRequest['body'], true, flags: JSON_THROW_ON_ERROR);
assertSameValue('Splendify Leads <leads@example.test>', $capturedPayload['from'], 'The configured display-name sender must reach Resend unchanged.');
assertSameValue(['team@example.test'], $capturedPayload['to'], 'The configured recipient must be an email-address array.');

// UNIT / REGRESSION: quote-wrapped configuration is normalized before the outbound Resend payload is built.
$quotedRecipientRequest = null;
$quotedRecipientEnvironment = array_replace($environment, ['LEAD_NOTIFICATION_EMAIL' => '‘alan.teo@splendify.io’']);
$quotedRecipientSent = sendResendNotification($validLead, '2026-09-23T12:00:00+08:00', $submissionId, $quotedRecipientEnvironment, static function (string $url, array $headers, string $body) use (&$quotedRecipientRequest): array {
    $quotedRecipientRequest = compact('url', 'headers', 'body');
    return ['status' => 201, 'body' => '{"id":"email_quoted"}'];
});
assertSameValue(true, $quotedRecipientSent['sent'], 'A quote-wrapped recipient configuration should still send successfully.');
assertSameValue(['alan.teo@splendify.io'], json_decode($quotedRecipientRequest['body'], true, flags: JSON_THROW_ON_ERROR)['to'], 'Resend must receive the normalized recipient without wrapping quotes.');

// NEGATIVE / REAL_STORE: an invalid recipient configuration skips Resend while retaining the accepted CSV lead.
$invalidRecipientCalls = 0;
$invalidRecipientEnvironment = array_replace($environment, ['LEAD_NOTIFICATION_EMAIL' => 'not a valid email']);
$invalidRecipientFile = sys_get_temp_dir() . '/splendify-invalid-recipient-' . uniqid('', true) . '.csv';
$invalidRecipientProcess = processLeadSubmission($validLead, $invalidRecipientFile, $submissionId, '2026-09-23T12:00:00+08:00', $invalidRecipientEnvironment, static function () use (&$invalidRecipientCalls): array {
    $invalidRecipientCalls++;
    return ['status' => 201, 'body' => '{"id":"email_should_not_send"}'];
});
assertSameValue(true, $invalidRecipientProcess['accepted'], 'A valid lead must remain accepted when notification configuration is invalid.');
assertSameValue(false, $invalidRecipientProcess['notification']['sent'], 'Invalid recipient configuration must not report an email as sent.');
assertSameValue('invalid_recipient_configuration', $invalidRecipientProcess['notification']['reason'], 'Invalid recipient configuration should be diagnosed safely.');
assertSameValue(0, $invalidRecipientCalls, 'Invalid recipient configuration must not call Resend.');
assertTrueValue(is_file($invalidRecipientFile), 'CSV storage must preserve a lead when notification configuration is invalid.');
assertSameValue(2, count(readCsvRows($invalidRecipientFile)), 'The preserved CSV lead must remain readable.');
unlink($invalidRecipientFile);

$apiFailure = sendResendNotification($validLead, '2026-09-23T12:00:00+08:00', $submissionId, $environment, static fn (): array => ['status' => 403, 'body' => '{"name":"forbidden","message":"Sender is not verified."}']);
assertSameValue(false, $apiFailure['sent'], 'A rejected Resend response should not report an email as sent.');
assertSameValue(403, $apiFailure['status'], 'Resend HTTP status should be retained for safe server-side diagnostics.');
assertSameValue('forbidden', $apiFailure['errorName'], 'Resend error names should be retained for safe server-side diagnostics.');
assertSameValue('Sender is not verified.', $apiFailure['errorMessage'], 'Resend error messages should be retained for safe server-side diagnostics.');

$notificationCalls = 0;
$invalidProcess = processLeadSubmission(array_replace($validLead, ['workEmail' => 'invalid']), sys_get_temp_dir() . '/unused.csv', $submissionId, '2026-09-23T12:00:00+08:00', $environment, static function () use (&$notificationCalls): array {
    $notificationCalls++;
    return ['status' => 201, 'body' => '{"id":"email_123"}'];
});
assertSameValue(false, $invalidProcess['accepted'], 'Invalid leads should not be accepted.');
assertSameValue(0, $notificationCalls, 'Invalid leads must not call Resend.');

$failureFile = sys_get_temp_dir() . '/splendify-resend-failure-' . uniqid('', true) . '.csv';
$resendFailure = processLeadSubmission($validLead, $failureFile, $submissionId, '2026-09-23T12:00:00+08:00', $environment, static fn (): array => ['status' => 503, 'body' => '{"message":"unavailable"}']);
assertSameValue(true, $resendFailure['accepted'], 'A saved lead should remain accepted when Resend fails.');
assertSameValue(false, $resendFailure['notification']['sent'], 'Resend failures must be reported to server-side handling.');
assertTrueValue(is_file($failureFile), 'CSV storage should still persist a lead when Resend fails.');
unlink($failureFile);

$testFile = sys_get_temp_dir() . '/splendify-leads-' . uniqid('', true) . '.csv';
appendLeadToCsv($testFile, $validLead, '2026-09-23T12:00:00+08:00');
appendLeadToCsv($testFile, array_replace($validLead, ['name' => 'John Lim']), '2026-09-23T12:01:00+08:00');
$rows = readCsvRows($testFile);
assertSameValue(3, count($rows), 'Appending two leads should preserve the header and both rows.');
assertSameValue('John Lim', $rows[2][2], 'The second lead should be appended as the final row.');
unlink($testFile);

$header = "Timestamp,Pain Point,Name,Work Email,Company,Phone";
$existingRow = "2026-09-23T10:00:00+08:00,Existing pain,Existing Name,existing@example.com,Existing Co,+6511111111";

$noNewlineFile = sys_get_temp_dir() . '/splendify-no-newline-' . uniqid('', true) . '.csv';
file_put_contents($noNewlineFile, $header . "\n" . $existingRow);
appendLeadToCsv($noNewlineFile, $validLead, '2026-09-23T12:00:00+08:00');
$noNewlineRows = readCsvRows($noNewlineFile);
assertSameValue(3, count($noNewlineRows), 'A file without a trailing newline must append a separate row.');
assertSameValue('Existing Name', $noNewlineRows[1][2], 'Existing no-newline row must remain unchanged.');
assertSameValue('Jane Tan', $noNewlineRows[2][2], 'New row must not merge with the previous row.');
assertCsvHeaderOnce($noNewlineFile, 'No-newline fixture must retain exactly one header.');
unlink($noNewlineFile);

$deletedLastRowFile = sys_get_temp_dir() . '/splendify-deleted-last-row-' . uniqid('', true) . '.csv';
file_put_contents($deletedLastRowFile, $header);
appendLeadToCsv($deletedLastRowFile, $validLead, '2026-09-23T12:00:00+08:00');
$deletedLastRowRows = readCsvRows($deletedLastRowFile);
assertSameValue(2, count($deletedLastRowRows), 'A CSV after its last data row is deleted must accept a new row.');
assertSameValue('Jane Tan', $deletedLastRowRows[1][2], 'New row must follow a retained header without merging.');
assertCsvHeaderOnce($deletedLastRowFile, 'Deleted-last-row fixture must retain exactly one header.');
unlink($deletedLastRowFile);

$headerOnlyFile = sys_get_temp_dir() . '/splendify-header-only-' . uniqid('', true) . '.csv';
file_put_contents($headerOnlyFile, "\xEF\xBB\xBF" . $header . "\n");
appendLeadToCsv($headerOnlyFile, $validLead, '2026-09-23T12:00:00+08:00');
$headerOnlyRows = readCsvRows($headerOnlyFile);
assertSameValue(2, count($headerOnlyRows), 'A header-only CSV must receive one data row.');
assertSameValue('Jane Tan', $headerOnlyRows[1][2], 'Header-only CSV must append a readable data row.');
assertCsvHeaderOnce($headerOnlyFile, 'Header-only fixture must retain exactly one header.');
unlink($headerOnlyFile);

$emptyFile = sys_get_temp_dir() . '/splendify-empty-' . uniqid('', true) . '.csv';
touch($emptyFile);
appendLeadToCsv($emptyFile, $validLead, '2026-09-23T12:00:00+08:00');
$emptyRows = readCsvRows($emptyFile);
assertSameValue(2, count($emptyRows), 'An empty CSV must receive a header and one data row.');
assertSameValue('Jane Tan', $emptyRows[1][2], 'Empty CSV data row must be readable.');
assertCsvHeaderOnce($emptyFile, 'Empty fixture must create exactly one header.');
unlink($emptyFile);

$crlfFile = sys_get_temp_dir() . '/splendify-crlf-' . uniqid('', true) . '.csv';
file_put_contents($crlfFile, $header . "\r\n" . $existingRow);
appendLeadToCsv($crlfFile, $validLead, '2026-09-23T12:00:00+08:00');
$crlfRows = readCsvRows($crlfFile);
assertSameValue(3, count($crlfRows), 'A CRLF CSV without a trailing newline must append a separate row.');
assertSameValue('Existing Name', $crlfRows[1][2], 'Existing CRLF row must remain unchanged.');
assertSameValue('Jane Tan', $crlfRows[2][2], 'CRLF fixture must append a readable new row.');
assertCsvHeaderOnce($crlfFile, 'CRLF fixture must retain exactly one header.');
unlink($crlfFile);

$manualEditFile = sys_get_temp_dir() . '/splendify-manual-edit-' . uniqid('', true) . '.csv';
file_put_contents($manualEditFile, $header . "\n" . $existingRow . "\n");
appendLeadToCsv($manualEditFile, array_replace($validLead, ['name' => 'First Append']), '2026-09-23T12:00:00+08:00');
file_put_contents($manualEditFile, $header . "\n" . $existingRow);
appendLeadToCsv($manualEditFile, array_replace($validLead, ['name' => 'Second Append']), '2026-09-23T12:01:00+08:00');
$manualEditRows = readCsvRows($manualEditFile);
assertSameValue(3, count($manualEditRows), 'Multiple appends after a manual edit must keep separate rows.');
assertSameValue('Existing Name', $manualEditRows[1][2], 'Manual-edit fixture must preserve the retained row.');
assertSameValue('Second Append', $manualEditRows[2][2], 'Manual-edit fixture must append after the retained row.');
assertCsvHeaderOnce($manualEditFile, 'Manual-edit fixture must retain exactly one header.');
unlink($manualEditFile);

echo "Lead submission tests passed.\n";

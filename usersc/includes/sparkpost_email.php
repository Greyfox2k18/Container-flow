<?php
/**
 * Email Router — Container Tracking System
 * Routes sendSparkPostEmail() to either SparkPost or Postmark
 * based on the email_provider setting. Switch in Container Flow Settings.
 */

function sendSparkPostEmail($to_emails, $subject, $html_body, $text_body = null, $reply_to = null, $attachments = []) {
    $provider = getContainerSetting('email_provider', 'sparkpost');
    if ($provider === 'postmark') {
        return _sendViaPostmark($to_emails, $subject, $html_body, $text_body, $reply_to, $attachments);
    }
    return _sendViaSparkPost($to_emails, $subject, $html_body, $text_body, $reply_to, $attachments);
}

// ── SparkPost ─────────────────────────────────────────────────────────────────
function _sendViaSparkPost($to_emails, $subject, $html_body, $text_body = null, $reply_to = null, $attachments = []) {
    $api_key    = getContainerSetting('sparkpost_api_key', '');
    $from_email = getContainerSetting('sparkpost_from_email', 'noreply@mail.container-flow.com');
    $from_name  = getContainerSetting('sparkpost_from_name', 'Container Flow');

    if (empty($api_key)) return ['success' => false, 'message' => 'SparkPost API key not configured'];

    if (is_string($to_emails)) $to_emails = array_map('trim', explode(',', $to_emails));
    $to_emails = array_values(array_filter($to_emails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (empty($to_emails)) return ['success' => false, 'message' => 'No valid recipients'];

    $recipients = array_map(fn($e) => ['address' => ['email' => $e]], $to_emails);

    $content = [
        'from'    => $from_name ? "{$from_name} <{$from_email}>" : $from_email,
        'subject' => $subject,
        'html'    => $html_body,
    ];
    if ($text_body)  $content['text']     = $text_body;
    if ($reply_to)   $content['reply_to'] = $reply_to;
    if (!empty($attachments)) $content['attachments'] = $attachments;

    $payload = ['recipients' => $recipients, 'content' => $content];

    $ch = curl_init('https://api.sparkpost.com/api/v1/transmissions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $api_key,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $response    = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error  = curl_error($ch);
    curl_close($ch);

    if ($curl_error) return ['success' => false, 'message' => 'cURL error: ' . $curl_error];

    $data = json_decode($response, true);
    if ($http_status >= 200 && $http_status < 300 && isset($data['results']['id'])) {
        return ['success' => true, 'message' => 'Sent via SparkPost'];
    }
    $error = $data['errors'][0]['message'] ?? $data['errors'][0]['description'] ?? "HTTP {$http_status}";
    return ['success' => false, 'message' => 'SparkPost error: ' . $error];
}

// ── Postmark ──────────────────────────────────────────────────────────────────
function _sendViaPostmark($to_emails, $subject, $html_body, $text_body = null, $reply_to = null, $attachments = []) {
    $api_key    = getContainerSetting('postmark_api_key', '');
    $from_email = getContainerSetting('sparkpost_from_email', 'noreply@mail.container-flow.com');
    $from_name  = getContainerSetting('sparkpost_from_name', 'Container Flow');

    if (empty($api_key)) return ['success' => false, 'message' => 'Postmark API key not configured'];

    if (is_string($to_emails)) $to_emails = array_map('trim', explode(',', $to_emails));
    $to_emails = array_values(array_filter($to_emails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (empty($to_emails)) return ['success' => false, 'message' => 'No valid recipients'];

    $from_header  = $from_name ? "{$from_name} <{$from_email}>" : $from_email;
    $pm_attachments = array_map(fn($a) => ['Name' => $a['name'], 'Content' => $a['data'], 'ContentType' => $a['type']], $attachments);

    $errors = [];
    $sent   = 0;

    foreach ($to_emails as $recipient) {
        $payload = [
            'From'          => $from_header,
            'To'            => $recipient,
            'Subject'       => $subject,
            'HtmlBody'      => $html_body,
            'MessageStream' => 'outbound',
        ];
        if ($text_body)           $payload['TextBody']    = $text_body;
        if ($reply_to)            $payload['ReplyTo']     = $reply_to;
        if (!empty($pm_attachments)) $payload['Attachments'] = $pm_attachments;

        $ch = curl_init('https://api.postmarkapp.com/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Postmark-Server-Token: ' . $api_key,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response    = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error  = curl_error($ch);
        curl_close($ch);

        if ($curl_error) { $errors[] = "cURL: {$curl_error}"; continue; }

        $data = json_decode($response, true);
        if ($http_status === 200 && isset($data['MessageID'])) {
            $sent++;
        } else {
            $errors[] = "Failed for {$recipient}: " . ($data['Message'] ?? "HTTP {$http_status}");
        }
    }

    if ($sent > 0) {
        $msg = "Sent to {$sent} recipient(s) via Postmark";
        if (!empty($errors)) $msg .= '. Some failed: ' . implode('; ', $errors);
        return ['success' => true, 'message' => $msg];
    }
    return ['success' => false, 'message' => 'All sends failed: ' . implode('; ', $errors)];
}

<?php
/**
 * Postmark Email — Container Tracking System
 * Drop-in replacement for sparkpost_email.php.
 * Uses the same sendSparkPostEmail() function name so no other files need changing.
 *
 * Setup:
 *  1. Create account at postmarkapp.com
 *  2. Create a Server → get the Server API Token
 *  3. Add your sending domain (mail.container-flow.com) under Sender Signatures or Domains
 *  4. Add Postmark's DKIM record to Cloudflare (Postmark gives you the exact record)
 *  5. Update SPF in Cloudflare:
 *       TXT  mail.container-flow.com  "v=spf1 include:spf.mtasv.net ~all"
 *  6. Save the Server API Token in Container Flow settings (postmark_api_key)
 */

function sendSparkPostEmail($to_emails, $subject, $html_body, $text_body = null, $reply_to = null, $attachments = []) {
    $api_key    = getContainerSetting('postmark_api_key', '');
    $from_email = getContainerSetting('sparkpost_from_email', 'noreply@mail.container-flow.com');
    $from_name  = getContainerSetting('sparkpost_from_name', 'Container Flow');

    if (empty($api_key)) {
        error_log('Postmark: API key not configured');
        return ['success' => false, 'message' => 'Postmark API key not set in Container Flow settings'];
    }

    if (empty($to_emails)) {
        return ['success' => false, 'message' => 'No recipients provided'];
    }

    // Normalise recipients to array
    if (is_string($to_emails)) {
        $to_emails = array_map('trim', explode(',', $to_emails));
    }
    $to_emails = array_values(array_filter($to_emails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));

    if (empty($to_emails)) {
        return ['success' => false, 'message' => 'No valid recipient email addresses'];
    }

    // Build attachment array for Postmark
    $pm_attachments = [];
    foreach ($attachments as $att) {
        $pm_attachments[] = [
            'Name'        => $att['name'],
            'Content'     => $att['data'],  // already base64-encoded
            'ContentType' => $att['type'],
        ];
    }

    // Postmark accepts comma-separated To addresses for multiple recipients.
    // For mission-critical sends we send individually so each recipient gets
    // a proper delivery event and failures don't affect other recipients.
    $from_header = $from_name ? "{$from_name} <{$from_email}>" : $from_email;

    $errors   = [];
    $sent     = 0;

    foreach ($to_emails as $recipient) {
        $payload = [
            'From'        => $from_header,
            'To'          => $recipient,
            'Subject'     => $subject,
            'HtmlBody'    => $html_body,
            'MessageStream'=> 'outbound',  // use your transactional stream
        ];

        if ($text_body)          $payload['TextBody']  = $text_body;
        if ($reply_to)           $payload['ReplyTo']   = $reply_to;
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

        if ($curl_error) {
            $errors[] = "cURL error for {$recipient}: {$curl_error}";
            error_log("Postmark cURL error for {$recipient}: {$curl_error}");
            continue;
        }

        $data = json_decode($response, true);

        if ($http_status === 200 && isset($data['MessageID'])) {
            $sent++;
            error_log("Postmark: sent to {$recipient}, MessageID: {$data['MessageID']}");
        } else {
            $error_msg = $data['Message'] ?? "HTTP {$http_status}";
            $errors[]  = "Failed for {$recipient}: {$error_msg}";
            error_log("Postmark error for {$recipient}: {$error_msg} (HTTP {$http_status}) Response: {$response}");
        }
    }

    if ($sent > 0) {
        $msg = "Sent to {$sent} recipient(s)";
        if (!empty($errors)) $msg .= '. Some failed: ' . implode('; ', $errors);
        return ['success' => true, 'message' => $msg];
    }

    return [
        'success' => false,
        'message' => 'All sends failed: ' . implode('; ', $errors),
    ];
}

<?php
/**
 * Report Builder — built-in mail senders: SparkPost, Postmark, or UserSpice's
 * own email(). Settings come from the plugin's settings page (or the project
 * config's 'mail' entry); see RbReports::mailSettings().
 *
 * Attachments: [['name', 'content' (raw bytes), 'type', 'inline' => bool, 'cid' => string]].
 * Inline items are images referenced from the HTML as src="cid:<cid>".
 */
if (count(get_included_files()) == 1) die();

if (!class_exists('RbMail')) {
class RbMail {
    const PROVIDERS = ['userspice', 'sparkpost', 'postmark'];

    /** Tests replace this: function ($url, array $headers, $body) { return [httpStatus, responseBody]; } */
    public static $transport = null;

    /** Send. $s = mail settings. Returns ['success' => bool, 'message' => string]. */
    public static function send(array $s, array $to, $subject, $html, array $attachments = []) {
        $to = array_values(array_filter(array_map('trim', $to), function ($e) { return filter_var($e, FILTER_VALIDATE_EMAIL); }));
        if (!$to) return ['success' => false, 'message' => 'No valid recipients'];
        switch ($s['provider'] ?? 'userspice') {
            case 'sparkpost': return self::sparkpost($s, $to, $subject, $html, $attachments);
            case 'postmark':  return self::postmark($s, $to, $subject, $html, $attachments);
            default:          return self::userspice($to, $subject, $html, $attachments);
        }
    }

    /** Can this provider show inline (cid:) images? */
    public static function supportsInline(array $s) {
        return in_array($s['provider'] ?? '', ['sparkpost', 'postmark'], true);
    }

    private static function from(array $s) {
        $email = $s['from_email'] ?: 'noreply@' . (parse_url((string) ($s['base_url'] ?? ''), PHP_URL_HOST) ?: 'localhost');
        return [$email, (string) ($s['from_name'] ?? '')];
    }

    private static function sparkpost(array $s, array $to, $subject, $html, array $attachments) {
        if (empty($s['sparkpost_api_key'])) return ['success' => false, 'message' => 'SparkPost API key not set (Report Builder settings).'];
        [$fromEmail, $fromName] = self::from($s);
        $content = [
            'from'    => $fromName ? ['email' => $fromEmail, 'name' => $fromName] : $fromEmail,
            'subject' => $subject,
            'html'    => $html,
        ];
        if (!empty($s['reply_to'])) $content['reply_to'] = $s['reply_to'];
        foreach ($attachments as $a) {
            if (!empty($a['inline'])) $content['inline_images'][] = ['name' => $a['cid'], 'type' => $a['type'], 'data' => base64_encode($a['content'])];
            else                      $content['attachments'][]   = ['name' => $a['name'], 'type' => $a['type'], 'data' => base64_encode($a['content'])];
        }
        $payload = [
            'recipients' => array_map(function ($e) { return ['address' => ['email' => $e]]; }, $to),
            'content'    => $content,
            'options'    => ['transactional' => true],
        ];
        $host = ($s['sparkpost_region'] ?? 'us') === 'eu' ? 'https://api.eu.sparkpost.com' : 'https://api.sparkpost.com';
        [$status, $body] = self::http($host . '/api/v1/transmissions', ['Authorization: ' . $s['sparkpost_api_key']], $payload);
        $data = json_decode((string) $body, true);
        if ($status >= 200 && $status < 300 && isset($data['results']['id'])) return ['success' => true, 'message' => 'Sent via SparkPost'];
        $err = $data['errors'][0]['message'] ?? $data['errors'][0]['description'] ?? ($status ? "HTTP $status" : $body);
        return ['success' => false, 'message' => 'SparkPost: ' . $err];
    }

    private static function postmark(array $s, array $to, $subject, $html, array $attachments) {
        if (empty($s['postmark_token'])) return ['success' => false, 'message' => 'Postmark server token not set (Report Builder settings).'];
        [$fromEmail, $fromName] = self::from($s);
        $att = array_map(function ($a) {
            $x = ['Name' => !empty($a['inline']) ? $a['cid'] : $a['name'], 'Content' => base64_encode($a['content']), 'ContentType' => $a['type']];
            if (!empty($a['inline'])) $x['ContentID'] = 'cid:' . $a['cid'];
            return $x;
        }, $attachments);
        foreach ($to as $addr) {   // one message per recipient, like the Container Flow Postmark sender
            $payload = [
                'From' => $fromName ? sprintf('"%s" <%s>', str_replace('"', '', $fromName), $fromEmail) : $fromEmail,
                'To' => $addr, 'Subject' => $subject, 'HtmlBody' => $html, 'MessageStream' => 'outbound',
            ];
            if (!empty($s['reply_to'])) $payload['ReplyTo'] = $s['reply_to'];
            if ($att) $payload['Attachments'] = $att;
            [$status, $body] = self::http('https://api.postmarkapp.com/email', ['X-Postmark-Server-Token: ' . $s['postmark_token']], $payload);
            $data = json_decode((string) $body, true);
            if (!($status >= 200 && $status < 300 && (int) ($data['ErrorCode'] ?? 1) === 0)) {
                return ['success' => false, 'message' => 'Postmark (' . $addr . '): ' . ($data['Message'] ?? ($status ? "HTTP $status" : $body))];
            }
        }
        return ['success' => true, 'message' => 'Sent via Postmark'];
    }

    /** UserSpice email(): no attachments; inline images were already turned into data: URIs by the caller. */
    private static function userspice(array $to, $subject, $html, array $attachments) {
        if (!function_exists('email')) return ['success' => false, 'message' => "UserSpice email() isn't available."];
        foreach ($to as $addr) {
            if (!email($addr, $subject, $html)) return ['success' => false, 'message' => "UserSpice email() failed for $addr — check Admin → Email settings."];
        }
        $dropped = count(array_filter($attachments, function ($a) { return empty($a['inline']); }));
        return ['success' => true, 'message' => 'Sent via UserSpice email' . ($dropped ? " ($dropped CSV attachment(s) not supported — use SparkPost or Postmark)" : '')];
    }

    private static function http($url, array $headers, array $payload) {
        $headers = array_merge($headers, ['Content-Type: application/json', 'Accept: application/json']);
        $body = json_encode($payload);
        if (self::$transport) return call_user_func(self::$transport, $url, $headers, $body);
        if (!function_exists('curl_init')) return [0, 'PHP cURL extension is not installed'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                                CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return $err ? [0, 'cURL error: ' . $err] : [$status, $resp];
    }
}
}

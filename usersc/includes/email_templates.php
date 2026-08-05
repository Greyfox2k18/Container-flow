<?php
/**
 * Email Templates — Container Tracking System
 * Lets a supervisor edit the HTML of the inbound/outbound completion
 * notification emails from a settings page instead of editing PHP code.
 *
 * Templates are stored in the `email_templates` table (see
 * 01_email_templates_migration.sql). If no row exists yet, or the saved
 * html_body is empty, getEmailTemplate() falls back to the built-in
 * default below — so nothing breaks before you've saved anything.
 *
 * Variables available in the template body (use as {{variable_name}}):
 *   {{client_name}}      - customer name, or "No Client"
 *   {{container_number}} - e.g. MSKU1234567
 *   {{date}}             - today's date, e.g. "Wednesday, August 5, 2026"
 *   {{photo_count}}      - number of photos attached
 *   {{portal_url}}       - link to the client photo portal
 *   {{shipment_row}}     - a ready-made <tr> for Shipment #, blank if not set
 *   {{po_bol_row}}       - a ready-made <tr> for PO/BOL, blank if not set
 *   {{date_row}}         - a ready-made <tr> for receipt/ship date, blank if not set
 *   {{skipped_notice}}   - a ready-made warning block, blank if nothing was skipped
 *
 * Variables available in the subject line:
 *   {{client_name}}, {{container_number}}
 *
 * Loaded via a require_once near the top of container_functions.php —
 * don't require container_functions.php back from here, it would be
 * circular (this file gets pulled in partway through that one loading).
 */

/**
 * Fetch the saved template for 'inbound' or 'outbound', or the default
 * if nothing has been saved yet.
 * Returns ['subject' => string, 'html_body' => string].
 */
function getEmailTemplate($type) {
    $type = ($type === 'outbound') ? 'outbound' : 'inbound';

    try {
        $row = DB::getInstance()
            ->query("SELECT subject, html_body FROM email_templates WHERE template_key = ?", [$type])
            ->first();
        if ($row && trim($row->html_body) !== '') {
            $subject   = $row->subject;
            $html_body = $row->html_body;

            // Self-heal: if a previous save went through code that
            // auto-htmlspecialchars'd the HTML (tags became &lt;div&gt;
            // etc. instead of staying as real tags), decode it back here
            // so already-saved bad rows don't keep sending broken emails.
            if (strpos($html_body, '<') === false && strpos($html_body, '&lt;') !== false) {
                $html_body = html_entity_decode($html_body, ENT_QUOTES | ENT_HTML5);
            }

            return ['subject' => $subject, 'html_body' => $html_body];
        }
    } catch (\Throwable $e) {
        // table might not exist yet — fall through to default
    }

    return getDefaultEmailTemplate($type);
}

/**
 * Save (insert or update) a template. $updated_by is the acting user's id.
 */
function saveEmailTemplate($type, $subject, $html_body, $updated_by = null) {
    $type = ($type === 'outbound') ? 'outbound' : 'inbound';
    $db = DB::getInstance();

    $existing = $db->query("SELECT id FROM email_templates WHERE template_key = ?", [$type])->first();
    if ($existing) {
        return $db->update('email_templates', $existing->id, [
            'subject'    => $subject,
            'html_body'  => $html_body,
            'updated_by' => $updated_by,
        ]);
    }
    return $db->insert('email_templates', [
        'template_key' => $type,
        'subject'      => $subject,
        'html_body'    => $html_body,
        'updated_by'   => $updated_by,
    ]);
}

/**
 * Reset a template back to the built-in default by deleting the saved row.
 */
function resetEmailTemplate($type) {
    $type = ($type === 'outbound') ? 'outbound' : 'inbound';
    DB::getInstance()->query("DELETE FROM email_templates WHERE template_key = ?", [$type]);
}

/**
 * Replace {{key}} tokens in a template string with values from $vars.
 * Unknown tokens are left as-is (visible in the output) so typos are
 * obvious to the person editing the template rather than silently blank.
 */
function renderEmailTemplate($template_str, $vars) {
    $search  = array_map(fn($k) => '{{' . $k . '}}', array_keys($vars));
    $replace = array_values($vars);
    return str_replace($search, $replace, $template_str);
}

/**
 * The list of variables + short descriptions, used to render the
 * cheat-sheet on the edit page.
 */
function getEmailTemplateVariableDocs() {
    return [
        'client_name'      => 'Customer name, or "No Client"',
        'container_number' => 'e.g. MSKU1234567',
        'date'             => 'Today\'s date (e.g. Wednesday, August 5, 2026)',
        'photo_count'      => 'Number of photos attached',
        'portal_url'       => 'Link to the client photo portal',
        'shipment_row'     => 'Pre-built table row for Shipment #, blank if not set on the container',
        'po_bol_row'       => 'Pre-built table row for PO/BOL, blank if not set',
        'date_row'         => 'Pre-built table row for receipt/ship date, blank if not set',
        'skipped_notice'   => 'Pre-built warning block if any photos were too large to attach, otherwise blank',
    ];
}

function getDefaultEmailTemplate($type) {
    $type_intro = $type === 'outbound'
        ? 'Your container has shipped and the photos are attached to this email.'
        : 'Your container has arrived and the photos are attached to this email.';

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:620px;margin:0 auto;color:#1f2937;">'
        . '<div style="background:#1e3a5f;padding:22px 28px;">'
        . '<h2 style="margin:0 0 4px;font-size:19px;font-weight:700;color:#fff;">Container Photos Ready</h2>'
        . '<p style="margin:0;font-size:12px;color:#9bbdd6;">{{date}}</p>'
        . '</div>'
        . '<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;padding:22px 28px;">'
        . '<p style="margin:0 0 18px;font-size:14px;color:#374151;">' . $type_intro . '</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">'
        . '<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:7px 0;color:#6b7280;width:130px;">Container #</td><td style="padding:7px 0;font-weight:700;font-family:Courier New,monospace;">{{container_number}}</td></tr>'
        . '{{shipment_row}}'
        . '{{po_bol_row}}'
        . '{{date_row}}'
        . '<tr><td style="padding:7px 0;color:#6b7280;">Photos</td><td style="padding:7px 0;font-weight:600;">{{photo_count}} attached</td></tr>'
        . '</table>'
        . '{{skipped_notice}}'
        . '<div style="text-align:center;margin:18px 0;">'
        . '<a href="{{portal_url}}" style="display:inline-block;background:#1e3a5f;color:#fff;padding:12px 28px;text-decoration:none;font-weight:700;font-size:14px;border-radius:6px;">View &amp; Download All Photos Online</a>'
        . '</div>'
        . '</div>'
        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;padding:10px 28px;">'
        . '<p style="margin:0;font-size:11px;color:#9ca3af;">Automated notification from Container Flow</p>'
        . '</div></div>';

    return [
        'subject'   => '{{client_name}} — Container {{container_number}} Photos',
        'html_body' => $html,
    ];
}

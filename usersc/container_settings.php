<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';
if (!securePage($_SERVER['PHP_SELF'])) { die(); }
$csrf = Token::generate();
$BASE = $us_url_root;
?>
<style>
.settings-wrap{max-width:760px;margin:0 auto;padding:20px 0 40px;}
.settings-card{background:#fff;border-radius:8px;padding:24px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.07);}
.settings-card h4{margin:0 0 18px;font-size:15px;color:#1e3a5f;border-bottom:1px solid #f1f5f9;padding-bottom:10px;}
.settings-field{margin-bottom:16px;}
.settings-field label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;}
.settings-field input,.settings-field select{width:100%;border:1px solid #d1d5db;border-radius:6px;padding:8px 12px;font-size:14px;}
.hint{font-size:12px;color:#9ca3af;margin-top:4px;}
</style>
<div id="page-wrapper"><div class="container-fluid">
<div class="settings-wrap">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
    <h2 style="margin:0;">Settings</h2>
    <a href="container_dashboard.php" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> Dashboard</a>
</div>
<div id="saveAlert"></div>

<!-- Email -->
<div class="settings-card">
    <h4><i class="fa fa-envelope" style="color:#0067b8;margin-right:8px;"></i>Email Sending</h4>
    <div class="settings-field">
        <label>Active Email Provider</label>
        <select id="email_provider" style="max-width:220px;border:1px solid #d1d5db;border-radius:6px;padding:8px 12px;font-size:14px;">
            <option value="sparkpost" <?php echo getContainerSetting('email_provider','sparkpost')==='sparkpost'?'selected':''; ?>>SparkPost</option>
            <option value="postmark" <?php echo getContainerSetting('email_provider','sparkpost')==='postmark'?'selected':''; ?>>Postmark</option>
        </select>
        <div class="hint">Switch between providers without changing any other settings.</div>
    </div>
    <div class="settings-field"><label>From Email</label><input type="email" id="sparkpost_from_email" value="<?php echo htmlspecialchars(getContainerSetting('sparkpost_from_email','noreply@mail.container-flow.com')); ?>"></div>
    <div class="settings-field"><label>From Name</label><input type="text" id="sparkpost_from_name" value="<?php echo htmlspecialchars(getContainerSetting('sparkpost_from_name','Container Flow')); ?>"></div>
    <div class="settings-field"><label>Site URL</label><input type="text" id="site_url" value="<?php echo htmlspecialchars(getContainerSetting('site_url','https://container-flow.com')); ?>"></div>
    <hr style="margin:16px 0;border:none;border-top:1px solid #f1f5f9;">
    <p style="font-size:13px;color:#6b7280;margin:0 0 14px;">Configure one email provider — leave the other blank.</p>
    <div class="settings-field">
        <label><i class="fa fa-paper-plane-o" style="color:#f59e0b;margin-right:5px;"></i>Postmark Server API Token <span style="font-size:11px;color:#9ca3af;font-weight:400;">(recommended)</span></label>
        <input type="password" id="postmark_api_key" value="<?php echo htmlspecialchars(getContainerSetting('postmark_api_key','')); ?>" placeholder="Postmark Server API Token">
        <div class="hint">postmarkapp.com &rarr; your Server &rarr; API Tokens</div>
    </div>
    <div class="settings-field">
        <label><i class="fa fa-paper-plane-o" style="color:#9ca3af;margin-right:5px;"></i>SparkPost API Key <span style="font-size:11px;color:#9ca3af;font-weight:400;">(legacy)</span></label>
        <input type="password" id="sparkpost_api_key" value="<?php echo htmlspecialchars(getContainerSetting('sparkpost_api_key','')); ?>" placeholder="SparkPost API key">
    </div>
</div>
    <div class="settings-field"><label>From Email</label><input type="email" id="sparkpost_from_email" value="<?php echo htmlspecialchars(getContainerSetting('sparkpost_from_email','noreply@container-flow.com')); ?>"></div>
    <div class="settings-field"><label>From Name</label><input type="text" id="sparkpost_from_name" value="<?php echo htmlspecialchars(getContainerSetting('sparkpost_from_name','Container Flow')); ?>"></div>
    <div class="settings-field"><label>Site URL (for email links)</label><input type="text" id="site_url" value="<?php echo htmlspecialchars(getContainerSetting('site_url','https://container-flow.com')); ?>"></div>
</div>

<!-- Points -->
<div class="settings-card">
    <h4><i class="fa fa-star" style="color:#0067b8;margin-right:8px;"></i>Beta Testing Points</h4>
    <?php
    $pts = ['points_create_container'=>['Create a container',5],'points_upload_photo'=>['Upload a photo (per photo)',10],'points_delete_photo'=>['Delete a photo (deducted)',10],'points_complete_container'=>['Mark container Complete',20],'points_review_container'=>['Supervisor reviews & sends',0]];
    ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
    <?php foreach ($pts as $k=>[$l,$d]): ?>
    <div class="settings-field" style="margin:0;">
        <label><?php echo $l; ?></label>
        <div style="display:flex;align-items:center;gap:8px;">
            <input type="number" id="<?php echo $k; ?>" value="<?php echo (int)getContainerSetting($k,$d); ?>" min="0" max="9999" style="width:90px;">
            <span style="font-size:13px;color:#9ca3af;">pts</span>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <hr style="margin:16px 0;border:none;border-top:1px solid #e5e7eb;">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="settings-field" style="margin:0;">
            <label>Points needed for prize</label>
            <div style="display:flex;align-items:center;gap:8px;">
                <input type="number" id="points_reward_threshold" value="<?php echo (int)getContainerSetting('points_reward_threshold',1000); ?>" min="1" max="99999" style="width:110px;">
                <span style="font-size:13px;color:#9ca3af;">pts</span>
            </div>
        </div>
        <div class="settings-field" style="margin:0;">
            <label>Review link (shown in prize popup)</label>
            <input type="text" id="points_review_url" value="<?php echo htmlspecialchars(getContainerSetting('points_review_url','')); ?>" placeholder="https://g.page/...">
        </div>
    </div>
</div>

<!-- Client Portal -->
<div class="settings-card">
    <h4><i class="fa fa-building-o" style="color:#0067b8;margin-right:8px;"></i>Client Portal</h4>
    <div class="settings-field">
        <label>Client Permission ID</label>
        <input type="number" id="client_permission_id" value="<?php echo (int)getContainerSetting('client_permission_id',0); ?>" min="0" style="width:100px;">
        <div class="hint">Permission ID from UserSpice admin that identifies client users. Assign this permission to client accounts so they can access the portal.</div>
    </div>
</div>

<!-- Save button -->
<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <button type="button" class="btn btn-success btn-lg" id="saveBtn"><i class="fa fa-save"></i> Save Settings</button>
</div>

<!-- Digest section -->
<div class="settings-card" style="margin-top:22px;">
    <h4><i class="fa fa-calendar-check-o" style="color:#0067b8;margin-right:8px;"></i>Daily Digest</h4>
    <div style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;font-size:12px;font-family:monospace;color:#374151;margin-bottom:14px;">
        0 6 * * * php /var/www/container-flow.com/html/usersc/cron/daily_digest.php >> /var/www/container-flow.com/html/usersc/logs/daily_digest.log 2>&1
    </div>
    <button type="button" class="btn btn-primary" id="sendDigestBtn"><i class="fa fa-send"></i> Send Digest Now</button>
    <div id="digestResult" style="margin-top:10px;font-size:13px;"></div>
</div>

</div></div></div>
<script>
var CSRF = '<?php echo $csrf; ?>';
var BASE = '<?php echo $BASE; ?>';
document.getElementById('saveBtn').addEventListener('click', function() {
    var btn = this; btn.disabled = true;
    var fd = new FormData();
    fd.append('csrf', CSRF);
    ['email_provider','postmark_api_key','sparkpost_api_key','sparkpost_from_email','sparkpost_from_name','site_url',
     'points_create_container','points_upload_photo','points_delete_photo','points_complete_container',
     'points_review_container','points_reward_threshold','points_review_url','client_permission_id'].forEach(function(k){
        var el = document.getElementById(k); if (el) fd.append(k, el.value.trim());
    });
    fetch(BASE+'usersc/ajax/save_settings.php',{method:'POST',body:fd})
        .then(function(r){return r.json();})
        .then(function(d){
            var el = document.getElementById('saveAlert');
            el.innerHTML = '<div class="alert alert-'+(d.success?'success':'danger')+'">'+d.message+'</div>';
            setTimeout(function(){el.innerHTML='';},3000);
            btn.disabled = false;
        }).catch(function(){btn.disabled=false;});
});
document.getElementById('sendDigestBtn').addEventListener('click', function() {
    var btn = this; var res = document.getElementById('digestResult');
    btn.disabled = true; btn.textContent = 'Sending...';
    var fd = new FormData(); fd.append('csrf', CSRF);
    fetch(BASE+'usersc/ajax/trigger_digest.php',{method:'POST',body:fd})
        .then(function(r){return r.json();})
        .then(function(d){
            res.innerHTML = '<span style="color:'+(d.success||d.sent===false?'#15803d':'#b91c1c')+';font-weight:600;">'+(d.message||'Done')+'</span>';
            btn.disabled = false; btn.innerHTML = '<i class="fa fa-send"></i> Send Digest Now';
        }).catch(function(e){res.innerHTML='<span style="color:#b91c1c;">Error: '+e.message+'</span>'; btn.disabled=false;});
});
</script>
<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>

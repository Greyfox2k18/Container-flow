<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

$user_id = $user->data()->id;

// Only supervisors can access this page
if (!isSupervisor()) {
    Redirect::to('container_dashboard.php');
}

$container_id = Input::get('id');
if (!$container_id) {
    Redirect::to('container_dashboard.php');
}

$container = getContainerById($container_id);
if (!$container) {
    Redirect::to('container_dashboard.php');
}

$photos = getContainerPhotos($container_id);
$errors = [];
$success = '';

// Handle form submission
if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $recipients = trim(Input::get('recipients'));
        $subject = trim(Input::get('subject'));
        $message = trim(Input::get('message'));
        $include_photos = Input::get('include_photos') ? true : false;
        
        // Validation
        if (empty($recipients)) {
            $errors[] = 'Recipients are required.';
        }
        
        if (empty($subject)) {
            $errors[] = 'Subject is required.';
        }
        
        // Validate email addresses
        $email_array = array_map('trim', explode(',', $recipients));
        foreach ($email_array as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Invalid email address: {$email}";
            }
        }
        
        if (empty($errors)) {
            // Build email body
            $email_body = "<html><body>";
            $email_body .= "<h2>Container Information Report</h2>";
            $email_body .= "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
            $email_body .= "<tr><th>Container Number:</th><td>" . htmlspecialchars($container->container_number) . "</td></tr>";
            $email_body .= "<tr><th>Seal Number:</th><td>" . htmlspecialchars($container->seal_number ?? 'N/A') . "</td></tr>";
            $email_body .= "<tr><th>Type:</th><td>" . ucfirst($container->type) . "</td></tr>";
            $email_body .= "<tr><th>Status:</th><td>" . ucwords(str_replace('_', ' ', $container->status)) . "</td></tr>";
            $email_body .= "<tr><th>Created:</th><td>" . date('M d, Y H:i', strtotime($container->created_at)) . "</td></tr>";
            $email_body .= "</table>";
            
            if ($container->notes) {
                $email_body .= "<h3>Notes:</h3>";
                $email_body .= "<p>" . nl2br(htmlspecialchars($container->notes)) . "</p>";
            }
            
            if ($message) {
                $email_body .= "<h3>Message:</h3>";
                $email_body .= "<p>" . nl2br(htmlspecialchars($message)) . "</p>";
            }
            
            if ($include_photos && !empty($photos)) {
                $email_body .= "<h3>Photos:</h3>";
                $email_body .= "<p>Total photos: " . count($photos) . "</p>";
                
                global $photo_types;
                $photos_by_type = [];
                foreach ($photos as $photo) {
                    $photos_by_type[$photo->photo_type][] = $photo;
                }
                
                foreach ($photo_types[$container->type] as $type_key => $type_label) {
                    if (isset($photos_by_type[$type_key])) {
                        $email_body .= "<h4>{$type_label}</h4>";
                        foreach ($photos_by_type[$type_key] as $photo) {
                            $photo_url = 'http://' . $_SERVER['HTTP_HOST'] . $us_url_root . $photo->file_path;
                            $email_body .= "<p><a href='{$photo_url}'>{$photo->file_name}</a>";
                            if ($photo->description) {
                                $email_body .= " - " . htmlspecialchars($photo->description);
                            }
                            $email_body .= "</p>";
                        }
                    }
                }
                
                $view_url = 'http://' . $_SERVER['HTTP_HOST'] . $us_url_root . 'container_view.php?id=' . $container_id;
                $email_body .= "<p><a href='{$view_url}' style='display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;'>View Full Container Details</a></p>";
            }
            
            $email_body .= "<hr>";
            $email_body .= "<p style='color: #666; font-size: 12px;'>This email was sent from the Container Tracking System by " . htmlspecialchars($user->data()->fname . ' ' . $user->data()->lname) . "</p>";
            $email_body .= "</body></html>";
            
            // Send email using SparkPost
            require_once $abs_us_root.$us_url_root.'usersc/includes/sparkpost_email.php';
            $email_result = sendSparkPostEmail($recipients, $subject, $email_body);
            
            if ($email_result['success']) {
                // Log in database
                $db = DB::getInstance();
                $db->insert('container_notifications', [
                    'container_id' => $container_id,
                    'sent_by' => $user_id,
                    'recipients' => $recipients,
                    'subject' => $subject,
                    'message' => $message
                ]);
                
                logContainerActivity($container_id, $user_id, 'sent_email', "Sent email to: {$recipients}");
                
                $success = 'Email sent successfully via SparkPost!';
                if (isset($email_result['accepted'])) {
                    $success .= " ({$email_result['accepted']} recipients)";
                }
            } else {
                $errors[] = 'Failed to send email: ' . $email_result['message'];
            }
        }
    } else {
        $errors[] = 'Invalid CSRF token.';
    }
}

// Get previous notifications
$db = DB::getInstance();
$notifications = $db->query("SELECT n.*, u.fname, u.lname FROM container_notifications n 
                             LEFT JOIN users u ON n.sent_by = u.id 
                             WHERE n.container_id = ? 
                             ORDER BY n.sent_at DESC", [$container_id])->results();

// Default email subject
$default_subject = ucfirst($container->type) . " Container Report - " . $container->container_number;
?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="page-header">
                    Send Email Report
                    <div class="pull-right">
                        <a href="container_view.php?id=<?php echo $container->id; ?>" class="btn btn-info">
                            <i class="fa fa-eye"></i> View Container
                        </a>
                        <a href="container_dashboard.php" class="btn btn-default">
                            <i class="fa fa-arrow-left"></i> Back
                        </a>
                    </div>
                </h1>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul>
                <?php foreach ($errors as $error): ?>
                <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <?php echo $success; ?>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8">
                <!-- Email Form -->
                <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h3 class="panel-title">Compose Email</h3>
                    </div>
                    <div class="panel-body">
                        <form method="post" action="">
                            <input type="hidden" name="csrf" value="<?php echo Token::generate(); ?>">
                            
                            <div class="form-group">
                                <label for="recipients">Recipients * (comma-separated email addresses)</label>
                                <input type="text" class="form-control" id="recipients" 
                                       name="recipients" required 
                                       placeholder="email1@example.com, email2@example.com"
                                       value="<?php echo htmlspecialchars(Input::get('recipients')); ?>">
                                <small class="form-text text-muted">
                                    Enter multiple email addresses separated by commas
                                </small>
                            </div>

                            <div class="form-group">
                                <label for="subject">Subject *</label>
                                <input type="text" class="form-control" id="subject" 
                                       name="subject" required 
                                       value="<?php echo htmlspecialchars(Input::get('subject') ?: $default_subject); ?>">
                            </div>

                            <div class="form-group">
                                <label for="message">Additional Message (Optional)</label>
                                <textarea class="form-control" id="message" name="message" 
                                          rows="6" placeholder="Enter any additional information to include in the email"><?php echo htmlspecialchars(Input::get('message')); ?></textarea>
                            </div>

                            <div class="form-group">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="include_photos" value="1" 
                                               <?php echo Input::get('include_photos') || !Input::exists() ? 'checked' : ''; ?>>
                                        Include photo links in email
                                    </label>
                                </div>
                            </div>

                            <div class="form-group">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fa fa-envelope"></i> Send Email
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Email Preview -->
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Email Preview</h3>
                    </div>
                    <div class="panel-body">
                        <div style="border: 1px solid #ddd; padding: 20px; background-color: #f9f9f9;">
                            <h4>Container Information Report</h4>
                            <table class="table table-bordered" style="background-color: white;">
                                <tr>
                                    <th width="30%">Container Number:</th>
                                    <td><?php echo htmlspecialchars($container->container_number); ?></td>
                                </tr>
                                <tr>
                                    <th>Seal Number:</th>
                                    <td><?php echo htmlspecialchars($container->seal_number ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Type:</th>
                                    <td><?php echo ucfirst($container->type); ?></td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td><?php echo ucwords(str_replace('_', ' ', $container->status)); ?></td>
                                </tr>
                            </table>
                            
                            <?php if ($container->notes): ?>
                            <h5>Notes:</h5>
                            <p><?php echo nl2br(htmlspecialchars($container->notes)); ?></p>
                            <?php endif; ?>
                            
                            <p class="text-muted"><em>[Additional message will appear here]</em></p>
                            
                            <p class="text-muted"><em>[Photo links will appear here if enabled]</em></p>
                            <p><strong>Total Photos:</strong> <?php echo count($photos); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Container Summary -->
                <div class="panel panel-info">
                    <div class="panel-heading">
                        <h3 class="panel-title">Container Summary</h3>
                    </div>
                    <div class="panel-body">
                        <p><strong>Container:</strong> <?php echo htmlspecialchars($container->container_number); ?></p>
                        <p><strong>Type:</strong> <?php echo ucfirst($container->type); ?></p>
                        <p><strong>Photos:</strong> <?php echo count($photos); ?></p>
                        <p><strong>Status:</strong> 
                            <span class="label label-info">
                                <?php echo ucwords(str_replace('_', ' ', $container->status)); ?>
                            </span>
                        </p>
                    </div>
                </div>

                <!-- Previous Notifications -->
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Previous Notifications</h3>
                    </div>
                    <div class="panel-body" style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($notifications)): ?>
                        <p class="text-muted">No previous notifications sent.</p>
                        <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                        <div class="well well-sm">
                            <p><strong>Sent:</strong> <?php echo date('M d, Y H:i', strtotime($notif->sent_at)); ?></p>
                            <p><strong>By:</strong> <?php echo htmlspecialchars($notif->fname . ' ' . $notif->lname); ?></p>
                            <p><strong>To:</strong> <?php echo htmlspecialchars($notif->recipients); ?></p>
                            <p><strong>Subject:</strong> <?php echo htmlspecialchars($notif->subject); ?></p>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>

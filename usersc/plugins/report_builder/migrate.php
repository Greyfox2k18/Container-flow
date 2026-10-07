<?php
// For security purposes, it is MANDATORY that this page be wrapped in the following
// if statement. This prevents remote execution of this code.
include "plugin_info.php";
if (in_array($user->data()->id, $master_account) && pluginActive($plugin_name,true)){

$count = 0;
$db = DB::getInstance();

$checkQ = $db->query("SELECT id,updates FROM us_plugins WHERE plugin = ?",array($plugin_name));
$checkC = $checkQ->count();
if($checkC > 0){
  $plgRow = $checkQ->first();
  if($plgRow->updates == ''){
  $existing = [];
  }else{
  $existing = json_decode($plgRow->updates);
  }

  // Migrations, oldest first.

  // 00001 — report storage. The plugin owns these tables; the old
  // report_definitions/report_recipients/report_run_log tables (unused) are
  // left alone.
  $update = '00001';
  if(!in_array($update,$existing)){
    $db->query("CREATE TABLE IF NOT EXISTS plg_rb_reports (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(150) NOT NULL,
      description VARCHAR(255) NULL,
      active TINYINT(1) NOT NULL DEFAULT 0,
      layout_json MEDIUMTEXT NULL,
      schedule_frequency VARCHAR(10) NULL,
      schedule_day_of_week TINYINT NULL,
      schedule_day_of_month TINYINT NULL,
      schedule_hour TINYINT NOT NULL DEFAULT 6,
      attach_csv TINYINT(1) NOT NULL DEFAULT 0,
      scope_mode VARCHAR(10) NOT NULL DEFAULT 'creator',
      created_by INT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      last_sent_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS plg_rb_recipients (
      id INT AUTO_INCREMENT PRIMARY KEY,
      report_id INT NOT NULL,
      kind VARCHAR(12) NOT NULL DEFAULT 'email',
      email VARCHAR(255) NULL,
      user_id INT NULL,
      permission_id INT NULL,
      note VARCHAR(255) NULL,
      INDEX idx_report (report_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS plg_rb_run_log (
      id INT AUTO_INCREMENT PRIMARY KEY,
      report_id INT NOT NULL,
      trigger_type VARCHAR(12) NOT NULL,
      run_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      recipient_count INT NOT NULL DEFAULT 0,
      row_count INT NOT NULL DEFAULT 0,
      success TINYINT(1) NOT NULL DEFAULT 0,
      error_message TEXT NULL,
      INDEX idx_report_run (report_id, run_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if(!$db->error()){
      logger($user->data()->id,"Migrations","$update migration triggered for $plugin_name");
      $existing[] = $update;
      $count++;
    }else{
      logger($user->data()->id,"Migrations","$update migration FAILED for $plugin_name: ".$db->errorString());
    }
  }

  $new = json_encode($existing);
  $db->update('us_plugins',$plgRow->id,['updates'=>$new,'last_check'=>date("Y-m-d H:i:s")]);
  if(!$db->error()) {
    logger($user->data()->id,"Migrations","$count migration(s) successfully triggered for $plugin_name");
  } else {
   	logger($user->data()->id,"USPlugins","Failed to save updates, Error: ".$db->errorString());
  }
}//do not perform actions outside of this statement
}

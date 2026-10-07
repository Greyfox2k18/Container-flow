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

  // Migrations, oldest first. Stage 1 (datasets + query builder) needs no
  // schema changes; stage 2 adds layout_json/report_kind to report_definitions.

  $new = json_encode($existing);
  $db->update('us_plugins',$plgRow->id,['updates'=>$new,'last_check'=>date("Y-m-d H:i:s")]);
  if(!$db->error()) {
    logger($user->data()->id,"Migrations","$count migration(s) successfully triggered for $plugin_name");
  } else {
   	logger($user->data()->id,"USPlugins","Failed to save updates, Error: ".$db->errorString());
  }
}//do not perform actions outside of this statement
}

<?php
require_once("init.php");
//This file is called when "deleting" a plugin (not just deactivating it).
//For security purposes, it is MANDATORY that this page be wrapped in the following
//if statement. This prevents remote execution of this code.
if (in_array($user->data()->id, $master_account)){
include "plugin_info.php";

// Deliberately does NOT drop plg_rb_reports / plg_rb_recipients /
// plg_rb_run_log: deleting the plugin by mistake shouldn't wipe every saved
// report. To remove them for good, drop those three tables by hand.

} //do not perform actions outside of this statement

<?php
require_once("init.php");
//This file is called when "deleting" a plugin (not just deactivating it).
//For security purposes, it is MANDATORY that this page be wrapped in the following
//if statement. This prevents remote execution of this code.
if (in_array($user->data()->id, $master_account)){
include "plugin_info.php";

// Deliberately does NOT drop the report_* tables: they hold live report
// definitions, recipients and run history that predate this plugin.
// Drop them by hand if you really want them gone.

} //do not perform actions outside of this statement

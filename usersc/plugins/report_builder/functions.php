<?php
// Loaded on every page while the plugin is active. Only class/function
// definitions here; dataset files in usersc/report_datasets/ are not
// read until a report actually needs them.
if (count(get_included_files()) == 1) die();

require_once __DIR__ . '/assets/includes/rb_registry.php';
require_once __DIR__ . '/assets/includes/rb_query.php';
require_once __DIR__ . '/assets/includes/rb_render.php';
require_once __DIR__ . '/assets/includes/rb_reports.php';
require_once __DIR__ . '/assets/includes/rb_api.php';

// usersc/report_datasets/ — project-owned, so plugin updates never overwrite it.
RbRegistry::addDir(dirname(__DIR__, 2) . '/report_datasets');

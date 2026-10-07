<?php
/**
 * Built-in dataset — the UserSpice activity log (logs table, written by
 * logger()): logins, admin actions, plugin events… Works on any UserSpice
 * site. Admins only.
 */
if (count(get_included_files()) == 1) die();

rb_register_dataset('user_logs', [
    'label'       => 'User activity log',
    'description' => 'UserSpice logs table — who did what, when. Admins only.',
    'table'       => 'logs',
    'alias'       => 'l',
    'joins'       => [
        'u' => ['table' => 'users', 'on' => 'u.id = l.user_id'],
    ],
    'fields' => [
        'id'        => ['label' => 'Log ID', 'type' => 'number', 'aggregatable' => false, 'groupable' => false],
        'logdate'   => ['label' => 'When', 'type' => 'datetime'],
        'logtype'   => ['label' => 'Type', 'type' => 'text'],
        'lognote'   => ['label' => 'Details', 'type' => 'text', 'groupable' => false, 'sortable' => false],
        'ip'        => ['label' => 'IP Address', 'type' => 'text'],
        'user_id'   => ['label' => 'User ID', 'type' => 'number', 'aggregatable' => false],
        'user_name' => ['label' => 'User', 'type' => 'text', 'expr' => "CONCAT_WS(' ', u.fname, u.lname)", 'join' => 'u'],
        'username'  => ['label' => 'Username', 'type' => 'text', 'expr' => 'u.username', 'join' => 'u'],
    ],
    'default_date_field' => 'logdate',
    'default_fields'     => ['logdate', 'user_name', 'logtype', 'lognote'],
    'access' => function ($user_id) { return RbReports::isUserSpiceAdmin($user_id); },
]);

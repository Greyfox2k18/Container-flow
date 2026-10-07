<?php
/**
 * Built-in dataset — UserSpice user accounts. Works on any UserSpice site.
 * Admins only (master accounts / permission 2): it contains email addresses.
 * A project can replace it with its own usersc/report_datasets/*.php that
 * registers 'users', or turn built-ins off with 'builtin_datasets' => false.
 */
if (count(get_included_files()) == 1) die();

rb_register_dataset('users', [
    'label'       => 'Users',
    'description' => 'UserSpice accounts: login count, last login, join date. Admins only.',
    'table'       => 'users',
    'alias'       => 'u',
    'fields'      => [
        'id'         => ['label' => 'User ID', 'type' => 'number', 'aggregatable' => false, 'groupable' => false],
        'name'       => ['label' => 'Name', 'type' => 'text', 'expr' => "CONCAT_WS(' ', u.fname, u.lname)"],
        'username'   => ['label' => 'Username', 'type' => 'text', 'groupable' => false],
        'email'      => ['label' => 'Email', 'type' => 'text', 'groupable' => false],
        'active'     => ['label' => 'Active', 'type' => 'bool'],
        'logins'     => ['label' => 'Login Count', 'type' => 'number', 'groupable' => false],
        'last_login' => ['label' => 'Last Login', 'type' => 'datetime'],
        'join_date'  => ['label' => 'Joined', 'type' => 'datetime'],
    ],
    'default_date_field' => 'last_login',
    'default_fields'     => ['name', 'email', 'logins', 'last_login'],
    'access' => function ($user_id) { return RbReports::isUserSpiceAdmin($user_id); },
]);

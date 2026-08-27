<?php
require_once dirname(__DIR__) . '/app/helpers.php';
if (auth_user()) redirect(auth_user()['role'] === 'admin' ? url('admin/') : url('dashboard.php'));
redirect(url('login.php'));

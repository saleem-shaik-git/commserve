<?php
require_once dirname(__DIR__) . '/app/helpers.php';
if (auth_user()) redirect(auth_user()['role'] === 'admin' ? '/commserve/public/admin/' : '/commserve/public/dashboard.php');
redirect('/commserve/public/login.php');

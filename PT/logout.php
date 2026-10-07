<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';

$pdo = getDatabaseConnection();

if (isAuthenticated()) {
    logoutUser($pdo);
}

redirectTo('index.php?logged_out=1');

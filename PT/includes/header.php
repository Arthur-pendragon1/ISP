<?php
if (!isset($pageTitle)) {
    $pageTitle = 'ISP Management';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> | ISP Management</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'login-body' : 'app-body'; ?>">

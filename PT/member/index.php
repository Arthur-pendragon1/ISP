<?php
session_name('isp_management_member_session');
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/auth.php';

$pdo = getDatabaseConnection();

if (isset($_SESSION['customer']) && !empty($_SESSION['customer']['id'])) {
    redirectTo('dashboard.php');
}

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        $errorMessage = 'Username or email and password are required.';
    } else {
        $statement = $pdo->prepare(
            'SELECT * FROM customers WHERE (username = :username OR email = :email) AND status = :status LIMIT 1'
        );
        $statement->execute([':username' => $login, ':email' => $login, ':status' => 'active']);
        $customer = $statement->fetch();

        if ($customer && password_verify($password, $customer['password'])) {
            $_SESSION['customer'] = [
                'id' => (int) $customer['id'],
                'name' => $customer['name'],
                'customer_code' => $customer['customer_code'],
                'username' => $customer['username'],
                'email' => $customer['email'],
                'customer_role' => 'customer',
            ];
            session_regenerate_id(true);
            logCustomerActivity($pdo, (int) $customer['id'], 'customer_login', 'Customer logged in');
            redirectTo('dashboard.php');
        }

        $errorMessage = 'Invalid customer credentials.';
    }
}

$pageTitle = 'Customer Login';
include __DIR__ . '/../includes/header.php';
?>
<div class="login-wrapper">
    <div class="login-header">
        <h1>Customer Portal</h1>
        <p>Login to your customer account</p>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['login_required'])): ?>
        <div class="alert alert-error">Please log in to access the customer portal.</div>
    <?php endif; ?>

    <form method="post" action="index.php">
        <div class="form-group">
            <label for="login">Username or Email</label>
            <input class="form-control" id="login" name="login" value="<?php echo htmlspecialchars($_POST['login'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input class="form-control" id="password" name="password" type="password" required>
        </div>
        <button type="submit" class="btn btn-primary">Login</button>
    </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>


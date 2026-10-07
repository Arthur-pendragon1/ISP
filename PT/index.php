<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';

$pdo = getDatabaseConnection();

if (isAuthenticated()) {
    redirectTo('dashboard.php');
}

$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $errorMessage = 'Username and password are required.';
    } else {
        $statement = $pdo->prepare(
            'SELECT u.*, r.name AS role_name FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.username = :username LIMIT 1'
        );
        $statement->execute([':username' => $username]);
        $user = $statement->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] !== 'active') {
                $errorMessage = 'Your account is not active. Please contact your administrator.';
            } else {
                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'name' => $user['name'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role_id' => (int) $user['role_id'],
                    'role_name' => $user['role_name'],
                ];

                session_regenerate_id(true);

                $updateStatement = $pdo->prepare('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id');
                $updateStatement->execute([':id' => $user['id']]);

                logActivity($pdo, (int) $user['id'], 'login_success', 'User logged in successfully');

                redirectTo('dashboard.php');
            }
        } else {
            $errorMessage = 'Invalid username or password.';
        }
    }
}

$pageTitle = 'Login';
include __DIR__ . '/includes/header.php';
?>
<div class="login-wrapper">
    <div class="login-header">
        <h1>ISP Management</h1>
        <p>Sign in to continue</p>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['logged_out'])): ?>
        <div class="alert alert-success">You have been logged out successfully.</div>
    <?php endif; ?>

    <?php if (isset($_GET['login_required'])): ?>
        <div class="alert alert-error">Please log in to access the application.</div>
    <?php endif; ?>

    <form method="post" action="index.php" novalidate>
        <div class="form-group">
            <label for="username">Username</label>
            <input class="form-control" type="text" id="username" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input class="form-control" type="password" id="password" name="password" required>
        </div>

        <button type="submit" class="btn btn-primary">Login</button>
    </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

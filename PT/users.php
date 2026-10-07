<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';

$pdo = getDatabaseConnection();
requireAuth();
requirePermission('users.view');

if (isset($_GET['edit_id']) && !can('users.edit')) {
    denyAccess('Anda tidak memiliki izin untuk mengedit pengguna.');
}

$currentUser = currentUser();
$canWrite = can('users.create') || can('users.edit');
$pageTitle = 'User Management';
$successMessage = '';
$errorMessage = '';

if (isset($_GET['success'])) {
    $successMessage = match ($_GET['success']) {
        'user_created' => 'User berhasil ditambahkan.',
        'user_updated' => 'User berhasil diperbarui.',
        default => 'Operasi berhasil.'
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Security validation failed.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            requirePermission('users.create');
        } elseif ($action === 'update') {
            requirePermission('users.edit');
        } elseif ($action === 'delete') {
            requirePermission('users.delete');
        }

        if ($action === 'create' || $action === 'update') {
            if (!$canWrite) {
                $errorMessage = 'You do not have permission to modify users.';
            } else {
                $name = trim((string) ($_POST['name'] ?? ''));
                $username = trim((string) ($_POST['username'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'suspended'], true) ? $_POST['status'] : 'active';
                $roleId = (int) ($_POST['role_id'] ?? 0);
                $password = (string) ($_POST['password'] ?? '');

                if ($name === '' || $username === '' || $email === '') {
                    $errorMessage = 'Name, username, and email are required.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errorMessage = 'Please provide a valid email address.';
                } elseif ($roleId <= 0) {
                    $errorMessage = 'A valid role is required.';
                } else {
                    $roleCheck = $pdo->prepare('SELECT id, name FROM roles WHERE id = :id LIMIT 1');
                    $roleCheck->execute([':id' => $roleId]);
                    $roleRow = $roleCheck->fetch();
                    if (!$roleRow) {
                        $errorMessage = 'Selected role does not exist.';
                    } else {
                        $selectedRoleName = strtolower((string) $roleRow['name']);
                        $isCurrentUserSuperAdmin = hasRole(['super_admin']);

                        if (!$isCurrentUserSuperAdmin && $selectedRoleName === 'super_admin') {
                            $errorMessage = 'You are not allowed to assign the Super Admin role.';
                        } else {
                            $duplicateCheck = $pdo->prepare('SELECT id FROM users WHERE (username = :username OR email = :email) AND id != :id LIMIT 1');
                            $duplicateCheck->execute([
                                ':username' => $username,
                                ':email' => $email,
                                ':id' => $action === 'update' ? (int) ($_POST['user_id'] ?? 0) : 0,
                            ]);

                            if ($duplicateCheck->fetch()) {
                                $errorMessage = 'Username or email is already used.';
                            } elseif ($action === 'create' && ($password === '' || strlen($password) < 6)) {
                                $errorMessage = 'Password is required and must be at least 6 characters.';
                            } else {
                                if ($action === 'create') {
                                    $statement = $pdo->prepare(
                                        'INSERT INTO users (role_id, name, username, email, password, phone, status, created_at, updated_at) VALUES (:role_id, :name, :username, :email, :password, :phone, :status, NOW(), NOW())'
                                    );
                                    $statement->execute([
                                        ':role_id' => $roleId,
                                        ':name' => $name,
                                        ':username' => $username,
                                        ':email' => $email,
                                        ':password' => password_hash($password, PASSWORD_BCRYPT),
                                        ':phone' => $phone,
                                        ':status' => $status,
                                    ]);
                                    logActivity($pdo, (int) $currentUser['id'], 'user_created', 'User created: ' . $username);
                                    $successMessage = 'User berhasil ditambahkan.';
                                    header('Location: users.php?success=user_created');
                                    exit;
                                } else {
                                    $userId = (int) ($_POST['user_id'] ?? 0);
                                    $currentUserRow = $pdo->prepare('SELECT u.*, r.name AS role_name FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.id = :id LIMIT 1');
                                    $currentUserRow->execute([':id' => $userId]);
                                    $currentRow = $currentUserRow->fetch();

                                    if (!$currentRow) {
                                        $errorMessage = 'User not found.';
                                    } else {
                                        $targetRoleName = strtolower((string) ($currentRow['role_name'] ?? ''));
                                        if (!$isCurrentUserSuperAdmin && $targetRoleName === 'super_admin') {
                                            $errorMessage = 'You are not allowed to modify a Super Admin account.';
                                        } elseif (!$isCurrentUserSuperAdmin && (int) $userId === (int) $currentUser['id']) {
                                            $errorMessage = 'You cannot modify your own account from this page.';
                                        } else {
                                            $updateFields = [
                                                'role_id' => $roleId,
                                                'name' => $name,
                                                'username' => $username,
                                                'email' => $email,
                                                'phone' => $phone,
                                                'status' => $status,
                                                'updated_at' => 'NOW()',
                                            ];
                                            $query = 'UPDATE users SET role_id = :role_id, name = :name, username = :username, email = :email, phone = :phone, status = :status, updated_at = NOW()';

                                            if ($password !== '') {
                                                if (strlen($password) < 6) {
                                                    $errorMessage = 'Password must be at least 6 characters.';
                                                } else {
                                                    $query .= ', password = :password';
                                                    $updateFields['password'] = password_hash($password, PASSWORD_BCRYPT);
                                                }
                                            }

                                            if ($errorMessage === '') {
                                                $query .= ' WHERE id = :id';
                                                $updateFields[':id'] = $userId;
                                                $statement = $pdo->prepare($query);
                                                $statement->execute($updateFields);
                                                logActivity($pdo, (int) $currentUser['id'], 'user_updated', 'User updated: ' . $username);
                                                $successMessage = 'User berhasil diperbarui.';
                                                header('Location: users.php?success=user_updated');
                                                exit;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($action === 'delete') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $targetUserStatement = $pdo->prepare('SELECT u.*, r.name AS role_name FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.id = :id LIMIT 1');
            $targetUserStatement->execute([':id' => $userId]);
            $targetUser = $targetUserStatement->fetch();

            if ($userId <= 0) {
                $errorMessage = 'User not found.';
            } elseif ((int) $userId === (int) $currentUser['id']) {
                $errorMessage = 'You cannot delete your own account.';
            } elseif (!$targetUser) {
                $errorMessage = 'User not found.';
            } elseif (!hasRole(['super_admin']) && strtolower((string) ($targetUser['role_name'] ?? '')) === 'super_admin') {
                $errorMessage = 'You are not allowed to delete a Super Admin account.';
            } else {
                $deleteStatement = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $deleteStatement->execute([':id' => $userId]);
                logActivity($pdo, (int) $currentUser['id'], 'user_deleted', 'User deleted: ' . ($targetUser['username'] ?? 'unknown'));
                $successMessage = 'User berhasil dihapus.';
                header('Location: users.php?success=user_deleted');
                exit;
            }
        }
    }
}

$roles = $pdo->query('SELECT id, name FROM roles ORDER BY name ASC')->fetchAll();
$users = $pdo->query(
    'SELECT u.id, u.name, u.username, u.email, u.phone, u.status, u.last_login_at, u.created_at, r.name AS role_name FROM users u INNER JOIN roles r ON r.id = u.role_id ORDER BY u.id DESC'
)->fetchAll();

$editingUser = null;
if (isset($_GET['edit_id'])) {
    $editingUserStatement = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $editingUserStatement->execute([':id' => (int) $_GET['edit_id']]);
    $editingUser = $editingUserStatement->fetch();
}

$shouldOpenUserModal = (isset($_GET['edit_id']) && $editingUser) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action ?? '') !== '' && $errorMessage !== '');

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<div class="app-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="content">
        <div class="page-header">
            <h1>User Management</h1>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="modal-overlay <?php echo $shouldOpenUserModal ? 'is-open' : ''; ?>" id="user-modal" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
            <div class="modal">
                <div class="modal-header">
                    <h3 id="user-modal-title"><?php echo isset($_GET['edit_id']) ? 'Edit User' : 'Tambah User'; ?></h3>
                    <button type="button" class="modal-close" data-close-modal="user-modal" aria-label="Close user form">&times;</button>
                </div>

                <form method="post" action="users.php" id="user-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(createCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="<?php echo isset($_GET['edit_id']) ? 'update' : 'create'; ?>">
                    <?php if (isset($_GET['edit_id'])): ?>
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars((string) ($editingUser['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>

                    <div class="modal-body">
                        <div class="modal-grid">
                            <div class="form-group">
                                <label for="user_name">Name</label>
                                <input class="form-control" id="user_name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ($editingUser['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="user_username">Username</label>
                                <input class="form-control" id="user_username" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ($editingUser['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="user_email">Email</label>
                                <input class="form-control" id="user_email" name="email" type="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ($editingUser['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="user_phone">Phone</label>
                                <input class="form-control" id="user_phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ($editingUser['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="user_role_id">Role</label>
                                <select class="form-control" id="user_role_id" name="role_id" required>
                                    <option value="">Select role</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo (int) $role['id']; ?>" <?php echo ((string) ($_POST['role_id'] ?? ($editingUser['role_id'] ?? '')) === (string) $role['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="user_status">Status</label>
                                <select class="form-control" id="user_status" name="status">
                                    <option value="active" <?php echo (($_POST['status'] ?? ($editingUser['status'] ?? 'active')) === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo (($_POST['status'] ?? ($editingUser['status'] ?? 'active')) === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="suspended" <?php echo (($_POST['status'] ?? ($editingUser['status'] ?? 'active')) === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                            </div>
                            <div class="form-group full">
                                <label for="user_password"><?php echo isset($_GET['edit_id']) ? 'Password Baru (opsional)' : 'Password'; ?></label>
                                <input class="form-control" id="user_password" name="password" type="password" <?php echo isset($_GET['edit_id']) ? '' : 'required'; ?>>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-close-modal="user-modal">Batal</button>
                        <button type="submit" class="btn btn-primary" data-submit-button="user-form"><?php echo isset($_GET['edit_id']) ? 'Simpan Perubahan' : 'Simpan'; ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="page-header" style="margin-bottom:1rem;">
                <h2 style="margin:0;">User List</h2>
                <?php if ($canWrite): ?>
                    <button type="button" class="btn btn-primary" data-open-modal="user-modal" style="width:auto;">+ Tambah User</button>
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($users): ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($user['role_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo htmlspecialchars($user['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($user['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['last_login_at'] ?? 'Never', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php if ($canWrite): ?>
                                            <div class="action-group">
                                                <button type="button" class="btn btn-sm btn-outline" data-open-edit="user-modal" data-user-id="<?php echo (int) $user['id']; ?>">Edit</button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">No users found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

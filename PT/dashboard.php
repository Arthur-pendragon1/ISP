<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';

$pdo = getDatabaseConnection();
requireAuth();
requirePermission('dashboard.view');

$pageTitle = 'Dashboard';
$user = currentUser();
$totalUsers = (int) $pdo->query('SELECT COUNT(*) AS total FROM users')->fetch()['total'];
$activeUsers = (int) $pdo->query("SELECT COUNT(*) AS total FROM users WHERE status = 'active'")->fetch()['total'];
$totalLogs = (int) $pdo->query('SELECT COUNT(*) AS total FROM activity_logs')->fetch()['total'];
$recentLogs = $pdo->query(
    'SELECT al.*, u.name AS user_name FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id ORDER BY al.created_at DESC LIMIT 5'
)->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<div class="app-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="content">
        <div class="page-header">
            <h1>Dashboard</h1>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Users</h3>
                <div class="value"><?php echo htmlspecialchars((string) $totalUsers, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>

            <div class="stat-card">
                <h3>Active users</h3>
                <div class="value"><?php echo htmlspecialchars((string) $activeUsers, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>

            <div class="stat-card">
                <h3>Activity Logs</h3>
                <div class="value"><?php echo htmlspecialchars((string) $totalLogs, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <div class="card">
            <h2>Recent Activity</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentLogs): ?>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['user_name'] ?? 'System', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($log['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4">No activity yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

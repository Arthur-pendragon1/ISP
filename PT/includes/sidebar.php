<?php
$user = currentUser();
$currentRole = $user['role_name'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar">
    <nav class="sidebar-nav">
        <div class="nav-group-label">Dashboard</div>
        <a href="dashboard.php" class="nav-item <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>">Dashboard</a>

        <div class="nav-group-label">Customer Management</div>
        <a href="customers.php" class="nav-item <?php echo $currentPage === 'customers.php' ? 'active' : ''; ?>">Customers</a>

        <div class="nav-group-label">Product</div>
        <a href="packages.php" class="nav-item <?php echo $currentPage === 'packages.php' ? 'active' : ''; ?>">Packages</a>

        <div class="nav-group-label">Service</div>
        <a href="subscriptions.php" class="nav-item <?php echo $currentPage === 'subscriptions.php' ? 'active' : ''; ?>">Subscriptions</a>

        <div class="nav-group-label">System</div>
        <a href="users.php" class="nav-item <?php echo $currentPage === 'users.php' ? 'active' : ''; ?>">Users</a>
        <?php if (in_array($currentRole, ['super_admin', 'admin'], true)): ?>
            <a href="settings.php" class="nav-item <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">Settings</a>
        <?php endif; ?>
        <a href="activity_logs.php" class="nav-item <?php echo $currentPage === 'activity_logs.php' ? 'active' : ''; ?>">Activity Logs</a>
        <a href="logout.php" class="nav-item danger">Logout</a>
    </nav>
</aside>

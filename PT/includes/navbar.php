<?php
$currentUser = currentUser();
$currentRoleName = isset($currentUser['role_name']) ? ucfirst(str_replace('_', ' ', strtolower((string) $currentUser['role_name']))) : 'Viewer';
$currentUserName = $currentUser['name'] ?? 'User';
?>
<header class="topbar">
    <div class="brand-area">
        <button class="sidebar-toggle" type="button" aria-label="Toggle menu">☰</button>
        <div class="brand-mark">ISP</div>
        <a href="dashboard.php" class="brand-link">
            <span>ISP Management</span>
        </a>
    </div>

    <div class="topbar-actions">
        <span class="topbar-status"><span class="status-dot"></span> System online</span>

        <div class="profile-menu">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profile-dropdown">
                <span class="profile-avatar"><?php echo htmlspecialchars(strtoupper(substr($currentUserName, 0, 1)), ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="profile-meta">
                    <span class="profile-name"><?php echo htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="profile-role"><?php echo htmlspecialchars($currentRoleName, ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
                <span class="profile-caret">▾</span>
            </button>

            <div id="profile-dropdown" class="profile-dropdown" hidden>
                <div class="profile-dropdown-header">
                    <strong><?php echo htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="online-pill">● Online</span>
                </div>
                <div class="profile-dropdown-row">
                    <span>Role</span>
                    <strong><?php echo htmlspecialchars($currentRoleName, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <a href="dashboard.php" class="profile-dropdown-item">Profile</a>
                <?php if (can('settings.view')): ?>
                    <a href="settings.php" class="profile-dropdown-item">Settings</a>
                <?php endif; ?>
                <a href="logout.php" class="profile-dropdown-item danger">Logout</a>
            </div>
        </div>
    </div>
</header>

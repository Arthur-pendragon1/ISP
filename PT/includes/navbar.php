<?php
$currentUser = currentUser();
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
        <span class="welcome-text">Welcome, <?php echo htmlspecialchars($currentUser['name'] ?? 'User', ENT_QUOTES, 'UTF-8'); ?></span>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</header>

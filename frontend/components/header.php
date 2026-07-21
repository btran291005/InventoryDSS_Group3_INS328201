<?php
/**
 * File: frontend/components/header.php
 * Purpose: Shared page header (logo, page title, user info, logout link).
 *
 * Yêu cầu: file gọi include component này phải include TRƯỚC ĐÓ:
 *   app_config.php, database.php, Logger.php, Auth.php, Middleware.php (đã guard()).
 *
 * Biến tùy chọn có thể set TRƯỚC KHI include file này:
 *   $pageTitle    = 'Account Management'; // tiêu đề hiển thị trên header
 *   $breadcrumbs  = ['Administrative', 'Account Management']; // mảng string, tùy chọn
 */

if (!Auth::check()) {
    // Phòng hờ: header không tự guard theo role (đó là việc của Middleware ở đầu
    // file trang), nhưng tối thiểu phải có người đăng nhập mới render được user info.
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$pageTitle   = $pageTitle ?? 'InventoryDSS';
$breadcrumbs = $breadcrumbs ?? [];
?>
<header class="app-header">
    <div class="app-header-left">
        <h1 class="app-header-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if (!empty($breadcrumbs)): ?>
            <nav class="app-header-breadcrumbs">
                <?php foreach ($breadcrumbs as $i => $crumb): ?>
                    <?php if ($i > 0): ?><span class="crumb-sep">/</span><?php endif; ?>
                    <span class="crumb<?= $i === array_key_last($breadcrumbs) ? ' crumb-current' : '' ?>">
                        <?= htmlspecialchars($crumb, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>

    <div class="app-header-right">
        <div class="app-header-user">
            <span class="app-header-user-name"><?= htmlspecialchars(Auth::fullName() ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            <span class="app-header-user-role"><?= htmlspecialchars(Auth::roleName() ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <a href="<?= BASE_URL ?>/logout.php" class="app-header-logout" title="Log out">Log out</a>
    </div>
</header>
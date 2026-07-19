<?php
/**
 * File: frontend/admin/dashboard.php
 * Purpose: Admin "System Overview" dashboard - KPI tổng quan hệ thống, cảnh báo
 * tồn kho thấp ưu tiên theo doanh số (BR-04, BR-13), danh sách PO đang chờ
 * duyệt (rút gọn, link sang po_approval.php để duyệt thật), và audit log gần
 * đây (FR-ADM-07).
 * Related: FR-ADM-01, FR-ADM-06, FR-ADM-07, FR-SYS-02, FR-SYS-03, BR-04, BR-13, NFR-02
 *
 * Đây là bản đầy đủ thay thế stub Phase 2-3 - dữ liệu lấy 100% từ
 * AdminService::getSystemSummary() (Phase 4), không query DB trực tiếp ở
 * Controller. Layout dùng chung header/sidebar/footer component (Phase 5) +
 * Bootstrap 5 (đồng bộ với login.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/AdminService.php';

// BR-19 / NFR-03: chỉ Admin được vào trang này, chặn ở tầng server
Middleware::guard([ROLE_ADMIN]);

$adminService = new AdminService();
$summary      = $adminService->getSystemSummary();

$kpi            = $summary['kpi'];
$lowStockAlerts = $summary['low_stock_alerts'];
$pendingOrders  = $summary['pending_orders'];
$recentActivity = $summary['recent_activity'];

/** Định dạng datetime DB ('Y-m-d H:i:s') sang dạng "HH:MM DD/MM" ngắn gọn cho UI. */
function formatDashboardDateTime(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts === false ? $raw : date('H:i d/m', $ts);
}

/** Map action_type (UPPER_SNAKE_CASE) sang nhãn tiếng Việt ngắn gọn hiển thị trên UI. */
function formatActionLabel(string $actionType): string
{
    $map = [
        'LOGIN'                  => 'đã đăng nhập',
        'LOGOUT'                 => 'đã đăng xuất',
        'LOGIN_ROLE_MISMATCH'    => 'đăng nhập sai vai trò',
        'CREATE_SUPPLIER'        => 'đã tạo nhà cung cấp',
        'UPDATE_SUPPLIER'        => 'đã cập nhật nhà cung cấp',
        'DELETE_SUPPLIER'        => 'đã xóa nhà cung cấp',
        'CREATE_WAREHOUSE'       => 'đã tạo kho',
        'UPDATE_WAREHOUSE'       => 'đã cập nhật kho',
        'DELETE_WAREHOUSE'       => 'đã xóa kho',
        'UPDATE_ROLE_PERMISSIONS'=> 'đã cập nhật phân quyền',
    ];

    if (isset($map[$actionType])) {
        return $map[$actionType];
    }
    if (str_starts_with($actionType, 'APPROVE')) {
        return 'đã duyệt đơn hàng';
    }
    if (str_starts_with($actionType, 'REJECT')) {
        return 'đã từ chối đơn hàng';
    }
    if (str_starts_with($actionType, 'CREATE')) {
        return 'đã tạo bản ghi mới';
    }
    if (str_starts_with($actionType, 'UPDATE')) {
        return 'đã cập nhật dữ liệu';
    }
    if (str_starts_with($actionType, 'LOCK')) {
        return 'đã khóa tài khoản';
    }
    if (str_starts_with($actionType, 'UNLOCK')) {
        return 'đã mở khóa tài khoản';
    }

    return strtolower(str_replace('_', ' ', $actionType));
}

$pageTitle   = 'System Overview';
$breadcrumbs = ['Admin', 'Dashboard'];
$activeMenu  = 'dashboard';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Overview - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../components/header.php'; ?>

            <main class="app-main">

                <!-- Page intro + quick actions -->
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <h2 class="page-heading mb-1">System Overview</h2>
                        <p class="page-subheading mb-0">Tổng quan số liệu hệ thống theo thời gian thực.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="po_approval.php" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2">
                            Xem đơn chờ duyệt
                        </a>
                        <a href="audit_log.php" class="btn btn-brand btn-sm d-inline-flex align-items-center gap-2">
                            Xem Audit Log
                        </a>
                    </div>
                </div>

                <!-- KPI cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card">
                            <span class="kpi-label">Active Users</span>
                            <span class="kpi-value"><?= number_format($kpi['active_users']) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card">
                            <span class="kpi-label">Total Products</span>
                            <span class="kpi-value"><?= number_format($kpi['total_products']) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card">
                            <span class="kpi-label">Total Suppliers</span>
                            <span class="kpi-value"><?= number_format($kpi['total_suppliers']) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card <?= $kpi['pending_pos'] > 0 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">Pending POs</span>
                            <span class="kpi-value"><?= number_format($kpi['pending_pos']) ?></span>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Low stock alerts (BR-04, BR-13) -->
                    <div class="col-12 col-xl-7">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">
                                    Low Stock Alerts
                                    <?php if (!empty($lowStockAlerts)): ?>
                                        <span class="badge-count"><?= count($lowStockAlerts) ?></span>
                                    <?php endif; ?>
                                </h3>
                                <span class="panel-card-note">Ưu tiên sản phẩm bán chạy 30 ngày gần nhất</span>
                            </div>

                            <?php if (empty($lowStockAlerts)): ?>
                                <div class="empty-state">Không có sản phẩm nào chạm Reorder Point.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-borderless align-middle mb-0 data-table">
                                        <thead>
                                            <tr>
                                                <th>SKU</th>
                                                <th>Sản phẩm</th>
                                                <th class="text-end">Tồn kho</th>
                                                <th class="text-end">Reorder Point</th>
                                                <th class="text-end">Bán 30 ngày</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($lowStockAlerts as $item): ?>
                                                <tr>
                                                    <td class="text-muted"><?= htmlspecialchars($item['sku_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="fw-semibold"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-end">
                                                        <span class="stock-pill <?= (int) $item['current_stock'] <= 0 ? 'stock-pill-critical' : 'stock-pill-warn' ?>">
                                                            <?= number_format((int) $item['current_stock']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-end text-muted"><?= number_format((int) $item['reorder_point']) ?></td>
                                                    <td class="text-end text-muted"><?= number_format((int) $item['qty_sold_30d']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pending PO approvals (FR-ADM-06) -->
                    <div class="col-12 col-xl-5">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">
                                    Pending Approvals
                                    <?php if ($kpi['pending_pos'] > 0): ?>
                                        <span class="badge-count badge-count-warn"><?= $kpi['pending_pos'] ?></span>
                                    <?php endif; ?>
                                </h3>
                                <a href="po_approval.php" class="panel-card-link">Xem tất cả</a>
                            </div>

                            <?php if (empty($pendingOrders)): ?>
                                <div class="empty-state">Không có đơn hàng nào đang chờ duyệt.</div>
                            <?php else: ?>
                                <ul class="list-unstyled activity-list mb-0">
                                    <?php foreach ($pendingOrders as $po): ?>
                                        <li class="activity-item">
                                            <div class="activity-item-main">
                                                <span class="fw-semibold">PO #<?= (int) $po['po_id'] ?></span>
                                                <span class="text-muted">— <?= htmlspecialchars($po['supplier_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="activity-item-meta">
                                                <span>Tạo bởi <?= htmlspecialchars($po['created_by_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="activity-item-time"><?= formatDashboardDateTime($po['created_at']) ?></span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-0">
                    <!-- Recent activity / audit log (FR-ADM-07) -->
                    <div class="col-12">
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Recent Activity</h3>
                                <a href="audit_log.php" class="panel-card-link">Xem Audit Log đầy đủ</a>
                            </div>

                            <?php if (empty($recentActivity)): ?>
                                <div class="empty-state">Chưa có hoạt động nào được ghi nhận.</div>
                            <?php else: ?>
                                <ul class="list-unstyled activity-list mb-0">
                                    <?php foreach ($recentActivity as $log): ?>
                                        <li class="activity-item">
                                            <div class="activity-item-main">
                                                <span class="fw-semibold"><?= htmlspecialchars($log['account_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="text-muted"><?= htmlspecialchars(formatActionLabel($log['action_type']), ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php if (!empty($log['target_table'])): ?>
                                                    <span class="text-muted">(<?= htmlspecialchars($log['target_table'], ENT_QUOTES, 'UTF-8') ?><?= $log['target_id'] !== null ? ' #' . (int) $log['target_id'] : '' ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="activity-item-meta">
                                                <span class="activity-item-time"><?= formatDashboardDateTime($log['timestamp']) ?></span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../components/footer.php'; ?>
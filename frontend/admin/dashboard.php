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

$kpi              = $summary['kpi'];
$lowStockAlerts   = $summary['low_stock_alerts'];
$pendingOrders    = $summary['pending_orders'];
$recentActivity   = $summary['recent_activity'];
$activityTrend7d  = $summary['activity_trend_7d'];
$productMix       = $summary['product_mix'];
$poWorkflow       = $summary['po_workflow'];

/** Định dạng datetime DB ('Y-m-d H:i:s') sang dạng "HH:MM DD/MM" ngắn gọn cho UI. */
function formatDashboardDateTime(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts === false ? $raw : date('H:i d/m', $ts);
}

/** Map action_type (UPPER_SNAKE_CASE) to short English labels for UI display. */
function formatActionLabel(string $actionType): string
{
    $map = [
        'LOGIN'                  => 'logged in',
        'LOGOUT'                 => 'logged out',
        'LOGIN_ROLE_MISMATCH'    => 'selected the wrong role',
        'CREATE_SUPPLIER'        => 'created a supplier',
        'UPDATE_SUPPLIER'        => 'updated a supplier',
        'DELETE_SUPPLIER'        => 'deleted a supplier',
        'CREATE_WAREHOUSE'       => 'created a warehouse',
        'UPDATE_WAREHOUSE'       => 'updated a warehouse',
        'DELETE_WAREHOUSE'       => 'deleted a warehouse',
        'UPDATE_ROLE_PERMISSIONS'=> 'updated role permissions',
    ];

    if (isset($map[$actionType])) {
        return $map[$actionType];
    }
    if (str_starts_with($actionType, 'APPROVE')) {
        return 'approved an order';
    }
    if (str_starts_with($actionType, 'REJECT')) {
        return 'rejected an order';
    }
    if (str_starts_with($actionType, 'CREATE')) {
        return 'created a new record';
    }
    if (str_starts_with($actionType, 'UPDATE')) {
        return 'updated data';
    }
    if (str_starts_with($actionType, 'LOCK')) {
        return 'locked an account';
    }
    if (str_starts_with($actionType, 'UNLOCK')) {
        return 'unlocked an account';
    }

    return strtolower(str_replace('_', ' ', $actionType));
}

/**
 * Vẽ sparkline SVG đơn giản (polyline) từ 1 mảng số nguyên - dùng cho mini
 * chart trong KPI card. Không phụ thuộc thư viện ngoài (giữ trang nhẹ, không
 * cần thêm JS chart lib chỉ để vẽ 1 đường xu hướng nhỏ).
 *
 * @param int[]  $values Chuỗi giá trị (VD: activity_count 7 ngày)
 * @param string $color  Mã màu nét vẽ (hex)
 */
function renderSparkline(array $values, string $color): string
{
    $count = count($values);
    if ($count < 2) {
        return '';
    }

    $width  = 100;
    $height = 32;
    $max = max($values);
    $min = min($values);
    $range = ($max - $min) > 0 ? ($max - $min) : 1;

    $points = [];
    foreach ($values as $i => $v) {
        $x = $count > 1 ? ($i / ($count - 1)) * $width : 0;
        $y = $height - (($v - $min) / $range) * $height;
        $points[] = round($x, 2) . ',' . round($y, 2);
    }
    $pointsAttr = implode(' ', $points);
    $colorAttr = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');

    return '<svg class="kpi-sparkline" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none">'
         . '<polyline points="' . $pointsAttr . '" fill="none" stroke="' . $colorAttr . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />'
         . '</svg>';
}

/**
 * Vẽ donut chart SVG từ danh sách segment (mỗi segment có percentage) - dùng
 * cho "Product Mix". Trả về mảng segment kèm màu đã gán, để template dùng
 * chung cho cả vòng donut lẫn legend (đảm bảo màu khớp nhau).
 *
 * @param array $segments [['category_name'=>string,'sku_count'=>int,'percentage'=>float], ...]
 * @return array{svg: string, segments_with_color: array}
 */
function renderDonutChart(array $segments): array
{
    // Bảng màu cố định theo thứ tự - đủ 6 màu trước khi lặp lại, đồng bộ tinh
    // thần "brand + semantic" đã dùng trong theme_variables.css.
    $palette = ['#1e3a5f', '#c2410c', '#3b82f6', '#166534', '#92400e', '#9333ea'];

    $radius = 70;
    $circumference = 2 * M_PI * $radius;
    $cx = 90;
    $cy = 90;
    $strokeWidth = 26;

    $segmentsWithColor = [];
    $offset = 0;
    $circles = '';

    foreach ($segments as $i => $seg) {
        $color = $palette[$i % count($palette)];
        $pct = (float) $seg['percentage'];
        $dash = ($pct / 100) * $circumference;
        $gap = $circumference - $dash;

        $circles .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $radius . '" '
                  . 'fill="none" stroke="' . $color . '" stroke-width="' . $strokeWidth . '" '
                  . 'stroke-dasharray="' . round($dash, 2) . ' ' . round($gap, 2) . '" '
                  . 'stroke-dashoffset="' . round(-$offset, 2) . '" '
                  . 'transform="rotate(-90 ' . $cx . ' ' . $cy . ')" />';

        $offset += $dash;

        $segmentsWithColor[] = array_merge($seg, ['color' => $color]);
    }

    $svg = '<svg viewBox="0 0 180 180">' . $circles . '</svg>';

    return ['svg' => $svg, 'segments_with_color' => $segmentsWithColor];
}

/** Phân loại mức độ nghiêm trọng cho 1 dòng Recent Activity khi hiển thị ở Alerts panel. */
function classifyAlertSeverity(string $actionType): string
{
    if (str_starts_with($actionType, 'REJECT') || $actionType === 'LOGIN_ROLE_MISMATCH' || str_starts_with($actionType, 'LOCK')) {
        return 'danger';
    }
    if (str_starts_with($actionType, 'APPROVE') || str_starts_with($actionType, 'UPDATE')) {
        return 'warning';
    }
    return 'info';
}

/**
 * Format the 7-day delta into a KPI badge (used when the metric does not change
 * evenly day by day). Example: "+3 this week" or "No change this week".
 */
function formatDeltaBadge(int $delta, string $noun): string
{
    if ($delta === 0) {
        return 'No change this week';
    }
    return '+' . number_format($delta) . ' ' . $noun . ' this week';
}


// KPI card "phụ liệu": 2 kiểu khác nhau tùy bản chất biến động của từng KPI
// (xem AdminService::getSystemSummary()):
//   - pending_pos / transactions: phát sinh đều đặn hàng ngày -> sparkline
//     (kpi.trends.*) có ý nghĩa thống kê thật.
//   - active_users / total_products / total_suppliers: sự kiện hiếm/không
//     đều -> sparkline 7 điểm thường ra đường phẳng, THAY bằng delta số
//     (kpi.deltas.*: tổng số sự kiện trong 7 ngày qua).
$pendingPosCounts   = array_column($kpi['trends']['pending_pos'], 'count');
$transactionsCounts = array_column($kpi['trends']['transactions_30d'], 'count');

$activityCounts = array_column($activityTrend7d, 'count');

// Alerts panel: lấy từ recent_activity, chỉ hiển thị các hành động đáng chú ý
// (khác Recent Activity ở cuối trang vốn liệt kê MỌI hành động) - ưu tiên
// severity danger/warning trước, tối đa 3 dòng khớp mockup ("3 NEW").
$alertCandidates = array_filter($recentActivity, fn(array $log) => classifyAlertSeverity($log['action_type']) !== 'info');
$alertItems = array_slice(array_values($alertCandidates), 0, 3);
if (empty($alertItems)) {
    // Không có gì "đáng báo động" -> vẫn hiện tối đa 3 hoạt động gần nhất để panel không trống trơn.
    $alertItems = array_slice($recentActivity, 0, 3);
}

$donutData = renderDonutChart($productMix);

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
                        <p class="page-subheading mb-0">Real-time overview of system metrics.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="po_approval.php" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2">
                            View Pending POs
                        </a>
                        <a href="audit_log.php" class="btn btn-brand btn-sm d-inline-flex align-items-center gap-2">
                            Xem Audit Log
                        </a>
                    </div>
                </div>

                <!-- KPI cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-xl">
                        <div class="kpi-card">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Active Users</span>
                            </div>
                            <span class="kpi-value"><?= number_format($kpi['active_users']) ?></span>
                            <span class="kpi-delta"><?= htmlspecialchars(formatDeltaBadge($kpi['deltas']['active_users'], 'logins'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl">
                        <div class="kpi-card">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Total Products</span>
                            </div>
                            <span class="kpi-value"><?= number_format($kpi['total_products']) ?></span>
                            <span class="kpi-delta"><?= htmlspecialchars(formatDeltaBadge($kpi['deltas']['total_products'], 'new products'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl">
                        <div class="kpi-card">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Total Suppliers</span>
                            </div>
                            <span class="kpi-value"><?= number_format($kpi['total_suppliers']) ?></span>
                            <span class="kpi-delta"><?= htmlspecialchars(formatDeltaBadge($kpi['deltas']['total_suppliers'], 'new suppliers'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl">
                        <div class="kpi-card <?= $kpi['pending_pos'] > 0 ? 'kpi-card-warn' : '' ?>">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Pending POs</span>
                            </div>
                            <span class="kpi-value"><?= number_format($kpi['pending_pos']) ?></span>
                            <?= renderSparkline($pendingPosCounts, '#92400e') ?>
                        </div>
                    </div>
                    <div class="col-6 col-xl">
                        <div class="kpi-card">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Transactions (30d)</span>
                            </div>
                            <span class="kpi-value"><?= number_format($kpi['transactions_30d']) ?></span>
                            <?= renderSparkline($transactionsCounts, '#166534') ?>
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
                                <span class="panel-card-note">Priority products with the strongest sales in the last 30 days</span>
                            </div>

                            <?php if (empty($lowStockAlerts)): ?>
                                <div class="empty-state">No products are at the reorder point.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-borderless align-middle mb-0 data-table">
                                        <thead>
                                            <tr>
                                                <th>SKU</th>
                                                <th>Product</th>
                                                <th class="text-end">Stock</th>
                                                <th class="text-end">Reorder Point</th>
                                                <th class="text-end">Sold (30d)</th>
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
                                <a href="po_approval.php" class="panel-card-link">View all</a>
                            </div>

                            <?php if (empty($pendingOrders)): ?>
                                <div class="empty-state">No purchase orders are waiting for approval.</div>
                            <?php else: ?>
                                <ul class="list-unstyled activity-list mb-0">
                                    <?php foreach ($pendingOrders as $po): ?>
                                        <li class="activity-item">
                                            <div class="activity-item-main">
                                                <span class="fw-semibold">PO #<?= (int) $po['po_id'] ?></span>
                                                <span class="text-muted">— <?= htmlspecialchars($po['supplier_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="activity-item-meta">
                                                <span>Created by <?= htmlspecialchars($po['created_by_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="activity-item-time"><?= formatDashboardDateTime($po['created_at']) ?></span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- User Activity Trend (proxy: audit log 7 ngày) + Product Mix (category %) -->
                <div class="row g-3 mt-0">
                    <div class="col-12 col-xl-7">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">User Activity Trend</h3>
                                <span class="panel-card-note">Last 7 days based on audit log data</span>
                            </div>

                            <?php if (array_sum($activityCounts) === 0): ?>
                                <div class="empty-state">No activity has been recorded in the last 7 days.</div>
                            <?php else: ?>
                                <?php
                                    // --- Layout SVG có margin đủ 4 phía (trước đây area/line vẽ sát
                                    // mép 0..chartH -> chart bị "đè" xuống đáy card, không có chỗ cho
                                    // trục Y). $padTop/$padBottom chừa chỗ cho gridline+label, $padLeft
                                    // chừa chỗ cho số trục Y, $padRight chừa chỗ cho dot/số ở điểm cuối
                                    // không bị cắt viền.
                                    $chartW = 700; $chartH = 220;
                                    $padTop = 24; $padBottom = 12; $padLeft = 34; $padRight = 14;
                                    $plotW = $chartW - $padLeft - $padRight;
                                    $plotH = $chartH - $padTop - $padBottom;

                                    $maxCount = max($activityCounts) ?: 1;
                                    // Trục Y: 0 -> maxCount, chia làm 4 mốc (0, 1/4, 1/2, 3/4, max) - làm
                                    // tròn lên số nguyên gần nhất cho dễ đọc thay vì số lẻ.
                                    $axisMax = (int) max(4, ceil($maxCount / 4) * 4);
                                    $yTicks = [0, (int) round($axisMax * 0.25), (int) round($axisMax * 0.5), (int) round($axisMax * 0.75), $axisMax];

                                    $n = count($activityTrend7d);
                                    $stepX = $n > 1 ? $plotW / ($n - 1) : 0;

                                    $pts = [];
                                    foreach ($activityTrend7d as $i => $row) {
                                        $x = round($padLeft + ($i * $stepX), 1);
                                        $y = round($padTop + $plotH - (($row['count'] / $axisMax) * $plotH), 1);
                                        $pts[] = ['x' => $x, 'y' => $y, 'count' => $row['count'], 'label' => $row['label']];
                                    }
                                    $lineStr = implode(' ', array_map(fn($p) => $p['x'] . ',' . $p['y'], $pts));
                                    $areaStr = $padLeft . ',' . ($padTop + $plotH) . ' ' . $lineStr . ' ' . ($padLeft + $plotW) . ',' . ($padTop + $plotH);
                                ?>
                                <div class="activity-chart-wrap">
                                    <svg class="activity-chart-svg" viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" preserveAspectRatio="xMidYMid meet">
                                        <!-- Gridline ngang + nhãn trục Y -->
                                        <?php foreach ($yTicks as $tick): ?>
                                            <?php $tickY = round($padTop + $plotH - (($tick / $axisMax) * $plotH), 1); ?>
                                            <line x1="<?= $padLeft ?>" y1="<?= $tickY ?>" x2="<?= $padLeft + $plotW ?>" y2="<?= $tickY ?>" stroke="var(--surface-border)" stroke-width="1"></line>
                                            <text x="<?= $padLeft - 8 ?>" y="<?= $tickY + 3 ?>" text-anchor="end" class="activity-chart-axis-label"><?= $tick ?></text>
                                        <?php endforeach; ?>

                                        <polygon points="<?= htmlspecialchars($areaStr, ENT_QUOTES, 'UTF-8') ?>" fill="var(--brand-primary)" opacity="0.12"></polygon>
                                        <polyline points="<?= htmlspecialchars($lineStr, ENT_QUOTES, 'UTF-8') ?>" fill="none" stroke="var(--brand-primary)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"></polyline>

                                        <!-- Dot + số thật tại mỗi điểm - cho phép nhìn giá trị chính xác thay vì chỉ đoán qua hình dạng đường -->
                                        <?php foreach ($pts as $p): ?>
                                            <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="4" fill="#fff" stroke="var(--brand-primary)" stroke-width="2.5"></circle>
                                            <text x="<?= $p['x'] ?>" y="<?= max(12, $p['y'] - 10) ?>" text-anchor="middle" class="activity-chart-point-label"><?= (int) $p['count'] ?></text>
                                        <?php endforeach; ?>
                                    </svg>
                                    <div class="activity-chart-labels">
                                        <?php foreach ($activityTrend7d as $row): ?>
                                            <span><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12 col-xl-5">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Product Mix</h3>
                            </div>

                            <?php if (empty($productMix)): ?>
                                <div class="empty-state">No products have been categorized yet.</div>
                            <?php else: ?>
                                <div class="product-mix-wrap">
                                    <div class="product-mix-donut">
                                        <?= $donutData['svg'] ?>
                                        <div class="product-mix-center">
                                            <span class="product-mix-center-value"><?= number_format($kpi['total_products']) ?></span>
                                            <span class="product-mix-center-label">SKUs</span>
                                        </div>
                                    </div>
                                    <div class="product-mix-legend">
                                        <?php foreach ($donutData['segments_with_color'] as $seg): ?>
                                            <div class="product-mix-legend-item">
                                                <span class="product-mix-legend-left">
                                                    <span class="product-mix-dot" style="background: <?= htmlspecialchars($seg['color'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                                                    <?= htmlspecialchars($seg['category_name'], ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                                <span class="product-mix-legend-pct"><?= number_format($seg['percentage'], 1) ?>%</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Purchase Order Workflow (đếm PO theo trạng thái thật: Draft/Pending/Approved/Delivered/Rejected) -->
                <div class="row g-3 mt-0">
                    <div class="col-12">
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Purchase Order Workflow</h3>
                                <span class="panel-card-note">Distribution of purchase orders by current status</span>
                            </div>

                            <?php $maxPoCount = max(array_column($poWorkflow, 'count')) ?: 1; ?>
                            <div class="po-workflow-chart">
                                <?php foreach ($poWorkflow as $col): ?>
                                    <?php $barHeightPct = $col['count'] > 0 ? max(6, round(($col['count'] / $maxPoCount) * 100)) : 2; ?>
                                    <div class="po-workflow-col">
                                        <div class="po-workflow-bar-track">
                                            <div class="po-workflow-bar" style="height: <?= $barHeightPct ?>%;">
                                                <span class="po-workflow-bar-value"><?= number_format($col['count']) ?></span>
                                            </div>
                                        </div>
                                        <span class="po-workflow-label"><?= htmlspecialchars($col['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-0">
                    <!-- Recent activity / audit log (FR-ADM-07) -->
                    <div class="col-12 col-xl-8">
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Recent Activity</h3>
                                <a href="audit_log.php" class="panel-card-link">View full audit log</a>
                            </div>

                            <?php if (empty($recentActivity)): ?>
                                <div class="empty-state">No activity has been recorded yet.</div>
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

                    <!-- Alerts panel: hành động đáng chú ý (danger/warning) trích từ audit log -->
                    <div class="col-12 col-xl-4">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">
                                    Alerts
                                    <?php if (!empty($alertItems)): ?>
                                        <span class="badge-count badge-count-warn"><?= count($alertItems) ?> NEW</span>
                                    <?php endif; ?>
                                </h3>
                            </div>

                            <?php if (empty($alertItems)): ?>
                                <div class="empty-state">No recent alerts.</div>
                            <?php else: ?>
                                <div class="alert-list">
                                    <?php foreach ($alertItems as $log): ?>
                                        <?php $severity = classifyAlertSeverity($log['action_type']); ?>
                                        <div class="alert-item">
                                            <div class="alert-item-top">
                                                <span class="alert-item-title severity-<?= $severity ?>">
                                                    <?= htmlspecialchars(ucfirst(formatActionLabel($log['action_type'])), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                                <span class="alert-item-time"><?= formatDashboardDateTime($log['timestamp']) ?></span>
                                            </div>
                                            <div class="alert-item-body">
                                                <?= htmlspecialchars($log['account_name'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($log['target_table'])): ?>
                                                    — <?= htmlspecialchars($log['target_table'], ENT_QUOTES, 'UTF-8') ?><?= $log['target_id'] !== null ? ' #' . (int) $log['target_id'] : '' ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../components/footer.php'; ?>
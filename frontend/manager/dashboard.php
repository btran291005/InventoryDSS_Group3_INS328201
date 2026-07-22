<?php
/**
 * File: frontend/manager/dashboard.php
 * Purpose: Dashboard tổng quan cho Manager - tồn kho, cảnh báo tồn thấp,
 * top rủi ro hết hàng, giao dịch bán gần đây, số PO đang chờ duyệt.
 * Related: FR-MGR-01, FR-MGR-02, FR-MGR-03, FR-MGR-12
 * Calls: ManagerService::getDashboardSummary()
 *
 * Thay thế bản stub tạm thời (chỉ test Auth/Middleware ở Phase 2-3).
 * ⚠️ "Doanh thu" (revenue) KHÔNG hiển thị được - products.unit_cost là giá
 * NHẬP (dùng cho giá trị PO), sales_transaction_details chưa có cột giá bán
 * lẻ. Dashboard hiển thị theo SỐ LƯỢNG giao dịch, không phải giá trị tiền -
 * đúng như getDashboardSummary()['note_revenue'] đã ghi rõ.
 *
 * Style/layout đồng bộ frontend/admin/dashboard.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/ManagerService.php';

Middleware::guard([ROLE_MANAGER]);

$managerService = new ManagerService();
$summary = $managerService->getDashboardSummary();

$totalStockList  = $summary['total_stock_by_product'];
$lowStockAlerts  = $summary['low_stock_alerts'];
$stockoutRiskTop = $summary['stockout_risk_top10'];
$recentSales     = $summary['recent_sales'];
$pendingPoCount  = $summary['pending_po_count'];

// KPI tổng hợp cho card - tính trực tiếp từ dữ liệu đã có trong $summary,
// không query thêm (giữ đúng tinh thần NFR-02: hạn chế query dư thừa).
$totalSkuCount        = count($totalStockList);
$lowStockCount        = count($lowStockAlerts);
$stockoutRiskCount    = count($stockoutRiskTop);
$recentTransactionCount = count($recentSales);

/** Mốc "nguy cấp trong 24h" - khớp đúng ngưỡng đã dùng ở reorder/stockout_risk.php. */
const DASHBOARD_URGENT_RISK_HOURS = 24;

$pageTitle   = 'Manager Dashboard';
$breadcrumbs = ['Manager', 'Dashboard'];
$activeMenu  = 'dashboard';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - InventoryDSS</title>
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

                <div class="mb-4">
                    <h2 class="page-heading mb-1">Manager Dashboard</h2>
                    <p class="page-subheading mb-0">Tổng quan tồn kho, cảnh báo và đơn đặt hàng - cập nhật theo thời gian thực.</p>
                </div>

                <!-- KPI cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-xl">
                        <div class="kpi-card">
                            <span class="kpi-label">Tổng SKU đang theo dõi</span>
                            <span class="kpi-value"><?= number_format($totalSkuCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl">
                        <div class="kpi-card <?= $lowStockCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">Cảnh báo tồn thấp</span>
                            <span class="kpi-value"><?= number_format($lowStockCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl">
                        <div class="kpi-card <?= $stockoutRiskCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">Rủi ro hết hàng (Top 10)</span>
                            <span class="kpi-value"><?= number_format($stockoutRiskCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl">
                        <div class="kpi-card">
                            <span class="kpi-label">Giao dịch bán (7 ngày)</span>
                            <span class="kpi-value"><?= number_format($recentTransactionCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl">
                        <div class="kpi-card <?= $pendingPoCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">PO đang chờ Admin duyệt</span>
                            <span class="kpi-value"><?= number_format($pendingPoCount) ?></span>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Low-stock alerts (FR-MGR-03, BR-04, BR-13) -->
                    <div class="col-12 col-xl-7">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Cảnh báo tồn thấp</h3>
                                <a href="reorder/reorder_suggestions.php" class="panel-card-link">Xem gợi ý đặt hàng &rarr;</a>
                            </div>

                            <?php if (empty($lowStockAlerts)): ?>
                                <div class="empty-state">Không có sản phẩm nào dưới Reorder Point.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table data-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Sản phẩm</th>
                                                <th class="text-end">Tồn kho</th>
                                                <th class="text-end">Reorder Point</th>
                                                <th class="text-end">Bán TB/ngày (7d)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($lowStockAlerts, 0, 8) as $alert): ?>
                                                <tr>
                                                    <td>
                                                        <span class="fw-semibold"><?= htmlspecialchars($alert['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        <div class="text-muted small"><?= htmlspecialchars($alert['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    </td>
                                                    <td class="text-end fw-semibold"><?= number_format((int) $alert['current_quantity']) ?></td>
                                                    <td class="text-end text-muted"><?= number_format((int) $alert['reorder_point']) ?></td>
                                                    <td class="text-end text-muted"><?= number_format((int) $alert['sales_volume_7d']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if (count($lowStockAlerts) > 8): ?>
                                    <div class="text-muted small mt-2">và <?= count($lowStockAlerts) - 8 ?> sản phẩm khác...</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Top Stock-out Risk (FR-MGR-12) -->
                    <div class="col-12 col-xl-5">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Top Rủi ro hết hàng</h3>
                                <a href="reorder/stockout_risk.php" class="panel-card-link">Xem đầy đủ &rarr;</a>
                            </div>

                            <?php if (empty($stockoutRiskTop)): ?>
                                <div class="empty-state">Không có sản phẩm nào đang có nguy cơ hết hàng.</div>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-2">
                                    <?php foreach (array_slice($stockoutRiskTop, 0, 5) as $risk): ?>
                                        <?php $isUrgent = (float) $risk['risk_hours'] <= DASHBOARD_URGENT_RISK_HOURS; ?>
                                        <div class="d-flex justify-content-between align-items-center py-1" style="border-bottom: 1px solid var(--surface-border-soft);">
                                            <div>
                                                <div class="fw-semibold small"><?= htmlspecialchars($risk['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted" style="font-size: .74rem;"><?= htmlspecialchars($risk['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <?php if ($isUrgent): ?>
                                                <span class="stock-pill stock-pill-critical"><?= number_format((float) $risk['risk_hours'], 1) ?>h</span>
                                            <?php else: ?>
                                                <span class="stock-pill stock-pill-warn"><?= number_format((float) $risk['risk_hours'], 1) ?>h</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent sales transactions (7 ngày) -->
                <div class="row g-3 mt-0">
                    <div class="col-12">
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Giao dịch bán gần đây</h3>
                                <span class="panel-card-note">7 ngày qua &middot; <?= number_format($recentTransactionCount) ?> giao dịch</span>
                            </div>

                            <?php if (empty($recentSales)): ?>
                                <div class="empty-state">Chưa có giao dịch bán hàng nào trong 7 ngày qua.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table data-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Mã giao dịch</th>
                                                <th>Kho</th>
                                                <th>Thời gian</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($recentSales, 0, 10) as $sale): ?>
                                                <tr>
                                                    <td class="fw-semibold">#<?= (int) $sale['transaction_id'] ?></td>
                                                    <td class="text-muted">Kho #<?= (int) $sale['warehouse_id'] ?></td>
                                                    <td class="text-muted small"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($sale['transaction_time'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if (count($recentSales) > 10): ?>
                                    <div class="text-muted small mt-2">và <?= count($recentSales) - 10 ?> giao dịch khác trong 7 ngày qua...</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../components/footer.php'; ?>
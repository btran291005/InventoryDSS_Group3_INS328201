<?php
/**
 * File: frontend/manager/dashboard.php
 * Purpose: Dashboard tổng quan cho Manager - tồn kho, cảnh báo tồn thấp,
 * top rủi ro hết hàng, gợi ý đặt hàng (BR-05), xu hướng bán, trạng thái PO
 * của Manager, sự cố thiếu hàng đang mở.
 * Related: FR-MGR-01, FR-MGR-02, FR-MGR-03, FR-MGR-06, FR-MGR-07, FR-MGR-12
 * Calls: ManagerService::getDashboardOverview()
 *
 * ⚠️ "Doanh thu" (revenue) KHÔNG hiển thị được - products.unit_cost là giá
 * NHẬP (dùng cho giá trị PO), sales_transaction_details chưa có cột giá bán
 * lẻ. Dashboard hiển thị theo SỐ LƯỢNG giao dịch, không phải giá trị tiền.
 *
 * ⚠️ Bố cục lấy cảm hứng từ 1 mockup UI ("GS25 IntelliStock") do người dùng
 * cung cấp, nhưng ĐÃ ĐIỀU CHỈNH để chỉ hiển thị số liệu THẬT có trong hệ
 * thống - các widget không có dữ liệu backing thật trong mockup gốc (Forecast
 * Accuracy %, Category Fulfillment %, Stock Level Heatmap, Critical Expiry
 * countdown) đã được BỎ, không bịa số. Style/layout đồng bộ
 * frontend/admin/dashboard.php (tái dùng nguyên các class CSS đã có:
 * kpi-card, panel-card, activity-chart-*, po-workflow-*, alert-item, status-badge).
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
$overview = $managerService->getDashboardOverview(Auth::id());

$totalStockList       = $overview['total_stock_by_product'];
$lowStockAlerts        = $overview['low_stock_alerts'];
$stockoutRiskTop       = $overview['stockout_risk_top10'];
$pendingPoCount        = $overview['pending_po_count'];
$reorderSuggestions    = $overview['reorder_suggestions'];
$salesTrend7d          = $overview['sales_trend_7d'];
$poStatusDistribution  = $overview['po_status_distribution'];
$openShortages         = $overview['open_shortages'];
$openShortageCount     = $overview['open_shortage_count'];
$myPendingOrders       = $overview['my_pending_orders'];

$totalSkuCount     = count($totalStockList);
$lowStockCount     = count($lowStockAlerts);
$stockoutRiskCount = count($stockoutRiskTop);

/** Mốc "nguy cấp trong 24h" - khớp đúng ngưỡng đã dùng ở FR-MGR-12. */
const DASHBOARD_URGENT_RISK_HOURS = 24;
/** Mốc "ưu tiên cao" (chưa khẩn cấp nhưng cần chú ý trong vòng 48h). */
const DASHBOARD_HIGH_PRIORITY_RISK_HOURS = 48;

// Map product_id -> risk_hours (Top 10 rủi ro hết hàng) để gắn nhãn ưu tiên
// cho card gợi ý đặt hàng bên dưới - tái dùng dữ liệu đã có, không tính lại.
$riskHoursByProduct = [];
foreach ($stockoutRiskTop as $risk) {
    $riskHoursByProduct[(int) $risk['product_id']] = (float) $risk['risk_hours'];
}

/**
 * Mức ưu tiên bổ sung hàng cho 1 sản phẩm, dựa trên risk_hours thật (nếu sản
 * phẩm đó cũng nằm trong Top 10 rủi ro hết hàng) - KHÔNG bịa thêm chỉ số mới.
 * @return array{0: string, 1: string} [nhãn, class status-badge]
 */
function restockPriorityLabel(?float $riskHours): array
{
    if ($riskHours === null) {
        return ['Bình thường', 'status-badge-info'];
    }
    if ($riskHours <= DASHBOARD_URGENT_RISK_HOURS) {
        return ['Khẩn cấp', 'status-badge-danger'];
    }
    if ($riskHours <= DASHBOARD_HIGH_PRIORITY_RISK_HOURS) {
        return ['Ưu tiên cao', 'status-badge-warning'];
    }
    return ['Bình thường', 'status-badge-info'];
}

/** Sparkline SVG đơn giản từ 1 mảng số nguyên - giống hệt bản dùng ở admin/dashboard.php. */
function renderDashboardSparkline(array $values, string $color): string
{
    $count = count($values);
    if ($count < 2) {
        return '';
    }
    $width = 100;
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

$salesTrendCounts = array_column($salesTrend7d, 'count');

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

                <!-- Page intro + Quick Actions (thay cho search/live-sync bar của mockup gốc -
                     hệ thống chưa có live sync/search toàn cục, không dựng giả) -->
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <h2 class="page-heading mb-1">Manager Dashboard</h2>
                        <p class="page-subheading mb-0">Tổng quan tồn kho, gợi ý đặt hàng và trạng thái đơn hàng - cập nhật theo thời gian thực.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="purchase_order/po-status.php" class="btn btn-outline-secondary btn-sm">Theo dõi PO</a>
                        <a href="reorder%20%26%20forecast/reorder_suggestions.php" class="btn btn-brand btn-sm">Gợi ý đặt hàng</a>
                    </div>
                </div>

                <!-- KPI cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-xl">
                        <div class="kpi-card">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Tổng SKU đang theo dõi</span>
                            </div>
                            <span class="kpi-value"><?= number_format($totalSkuCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl">
                        <div class="kpi-card <?= $lowStockCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Cảnh báo tồn thấp</span>
                            </div>
                            <span class="kpi-value"><?= number_format($lowStockCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl">
                        <div class="kpi-card <?= $stockoutRiskCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Rủi ro hết hàng (Top 10)</span>
                            </div>
                            <span class="kpi-value"><?= number_format($stockoutRiskCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl">
                        <div class="kpi-card">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Giao dịch bán (7 ngày)</span>
                            </div>
                            <span class="kpi-value"><?= number_format(array_sum($salesTrendCounts)) ?></span>
                            <?= renderDashboardSparkline($salesTrendCounts, '#166534') ?>
                        </div>
                    </div>
                    <div class="col-6 col-xl">
                        <div class="kpi-card <?= $openShortageCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Sự cố thiếu hàng đang mở</span>
                            </div>
                            <span class="kpi-value"><?= number_format($openShortageCount) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Recommended Restocking (BR-05/FR-MGR-02) + Quick Actions -->
                <div class="row g-3 mb-0">
                    <div class="col-12 col-xl-8">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Gợi ý bổ sung hàng</h3>
                                <a href="reorder%20%26%20forecast/reorder_suggestions.php" class="panel-card-link">Xem tất cả &amp; tạo PO &rarr;</a>
                            </div>

                            <?php if ($reorderSuggestions['success'] !== true): ?>
                                <div class="empty-state"><?= htmlspecialchars($reorderSuggestions['message'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php elseif (empty($reorderSuggestions['suggestions'])): ?>
                                <div class="empty-state">Không có sản phẩm nào cần bổ sung hàng lúc này.</div>
                            <?php else: ?>
                                <div class="restock-grid">
                                    <?php foreach (array_slice($reorderSuggestions['suggestions'], 0, 8) as $s): ?>
                                        <?php
                                            [$priorityLabel, $priorityClass] = restockPriorityLabel($riskHoursByProduct[(int) $s['product_id']] ?? null);
                                        ?>
                                        <div class="restock-card">
                                            <div>
                                                <div class="restock-card-name"><?= htmlspecialchars($s['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="restock-card-sku"><?= htmlspecialchars($s['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <div>
                                                <span class="restock-card-qty"><?= number_format((int) $s['suggested_qty']) ?></span>
                                                <span class="restock-card-qty-label"> đơn vị</span>
                                            </div>
                                            <span class="status-badge <?= $priorityClass ?>"><?= $priorityLabel ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($reorderSuggestions['suggestions']) > 8): ?>
                                    <div class="text-muted small mt-2">và <?= count($reorderSuggestions['suggestions']) - 8 ?> sản phẩm khác...</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12 col-xl-4">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Thao tác nhanh</h3>
                            </div>
                            <div class="quick-action-grid">
                                <a href="reorder%20%26%20forecast/reorder_suggestions.php" class="quick-action-btn">Gợi ý đặt hàng</a>
                                <a href="purchase_order/po-status.php" class="quick-action-btn">Theo dõi PO</a>
                                <a href="shortage_incidents.php" class="quick-action-btn">Sự cố thiếu hàng</a>
                                <a href="vendor/product_pfm.php" class="quick-action-btn">Hiệu suất sản phẩm</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-0">
                    <!-- Top Stock-out Risk (FR-MGR-12) -->
                    <div class="col-12 col-xl-7">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">
                                    Top 10 rủi ro hết hàng
                                    <?php if (!empty($stockoutRiskTop)): ?>
                                        <span class="badge-count badge-count-warn"><?= count($stockoutRiskTop) ?></span>
                                    <?php endif; ?>
                                </h3>
                                <span class="panel-card-note">Xếp hạng theo tồn kho ÷ tốc độ bán trung bình 7 ngày</span>
                            </div>

                            <?php if (empty($stockoutRiskTop)): ?>
                                <div class="empty-state">Không có sản phẩm nào đang có nguy cơ hết hàng.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-borderless align-middle mb-0 data-table">
                                        <thead>
                                            <tr>
                                                <th>Sản phẩm</th>
                                                <th class="text-end">Tồn kho</th>
                                                <th class="text-end">Bán TB/ngày</th>
                                                <th>Mức độ rủi ro</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($stockoutRiskTop as $risk): ?>
                                                <?php
                                                    $riskHours = (float) $risk['risk_hours'];
                                                    $isUrgent = $riskHours <= DASHBOARD_URGENT_RISK_HOURS;
                                                    // % thanh hiển thị: quy đổi risk_hours (nhỏ = nguy cấp) thành thang
                                                    // 0-100% (lớn = nguy cấp) trên mốc tham chiếu 48h - CHỈ để vẽ thanh
                                                    // trực quan, số liệu thật vẫn là risk_hours hiển thị bên cạnh.
                                                    $riskPct = max(0, min(100, (int) round((1 - $riskHours / (DASHBOARD_HIGH_PRIORITY_RISK_HOURS)) * 100)));
                                                ?>
                                                <tr>
                                                    <td>
                                                        <span class="fw-semibold"><?= htmlspecialchars($risk['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        <div class="text-muted small"><?= htmlspecialchars($risk['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    </td>
                                                    <td class="text-end"><?= number_format((int) $risk['current_stock']) ?></td>
                                                    <td class="text-end text-muted"><?= number_format((float) $risk['avg_daily_sales_7d'], 1) ?></td>
                                                    <td style="min-width: 130px;">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <div class="progress" style="height: 6px; flex: 1;">
                                                                <div class="progress-bar <?= $isUrgent ? 'bg-danger' : 'bg-warning' ?>" style="width: <?= $riskPct ?>%;"></div>
                                                            </div>
                                                            <span class="stock-pill <?= $isUrgent ? 'stock-pill-critical' : 'stock-pill-warn' ?>"><?= number_format($riskHours, 1) ?>h</span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Alerts panel: ưu tiên nghiệp vụ Manager thật - sự cố thiếu hàng, PO
                         đang chờ duyệt, rủi ro hết hàng khẩn cấp - không lấy audit log
                         (đó là view của Admin, Manager không có quyền xem audit log - FR-ADM-07). -->
                    <div class="col-12 col-xl-5">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">
                                    Cảnh báo
                                    <?php
                                        $alertItems = [];
                                        foreach (array_slice($openShortages, 0, 3) as $incident) {
                                            $alertItems[] = [
                                                'severity' => 'danger',
                                                'title'    => 'Sự cố thiếu hàng',
                                                'body'     => htmlspecialchars($incident['product_name'], ENT_QUOTES, 'UTF-8')
                                                            . (!empty($incident['resolution_action']) ? ' — ' . htmlspecialchars($incident['resolution_action'], ENT_QUOTES, 'UTF-8') : ' — chưa có hướng xử lý'),
                                                'link'     => 'shortage_incidents.php',
                                            ];
                                        }
                                        if (count($alertItems) < 3) {
                                            foreach (array_slice($myPendingOrders, 0, 3 - count($alertItems)) as $po) {
                                                $alertItems[] = [
                                                    'severity' => 'warning',
                                                    'title'    => 'PO đang chờ Admin duyệt',
                                                    'body'     => 'PO #' . (int) $po['po_id'] . ' — ' . htmlspecialchars($po['supplier_name'], ENT_QUOTES, 'UTF-8'),
                                                    'link'     => 'purchase_order/po-status.php',
                                                ];
                                            }
                                        }
                                        if (count($alertItems) < 3) {
                                            foreach ($stockoutRiskTop as $risk) {
                                                if ((float) $risk['risk_hours'] > DASHBOARD_URGENT_RISK_HOURS) {
                                                    continue;
                                                }
                                                $alertItems[] = [
                                                    'severity' => 'danger',
                                                    'title'    => 'Sắp hết hàng',
                                                    'body'     => htmlspecialchars($risk['product_name'], ENT_QUOTES, 'UTF-8') . ' — còn ' . number_format((float) $risk['risk_hours'], 1) . 'h',
                                                    'link'     => '#',
                                                ];
                                                if (count($alertItems) >= 3) {
                                                    break;
                                                }
                                            }
                                        }
                                    ?>
                                    <?php if (!empty($alertItems)): ?>
                                        <span class="badge-count badge-count-warn"><?= count($alertItems) ?> MỚI</span>
                                    <?php endif; ?>
                                </h3>
                            </div>

                            <?php if (empty($alertItems)): ?>
                                <div class="empty-state">Không có cảnh báo nào.</div>
                            <?php else: ?>
                                <div class="alert-list">
                                    <?php foreach ($alertItems as $alert): ?>
                                        <a href="<?= htmlspecialchars($alert['link'], ENT_QUOTES, 'UTF-8') ?>" class="alert-item alert-item-link">
                                            <span class="alert-item-icon severity-<?= $alert['severity'] ?>" aria-hidden="true"></span>
                                            <div class="alert-item-content">
                                                <div class="alert-item-top">
                                                    <span class="alert-item-title severity-<?= $alert['severity'] ?>"><?= $alert['title'] ?></span>
                                                </div>
                                                <div class="alert-item-body"><?= $alert['body'] ?></div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Sales trend (7 ngày) + PO status (của Manager hiện tại) -->
                <div class="row g-3 mt-0">
                    <div class="col-12 col-xl-7">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Xu hướng bán hàng</h3>
                                <span class="panel-card-note">Số giao dịch/ngày, 7 ngày gần nhất</span>
                            </div>

                            <?php if (array_sum($salesTrendCounts) === 0): ?>
                                <div class="empty-state">Chưa có giao dịch bán hàng nào trong 7 ngày qua.</div>
                            <?php else: ?>
                                <?php
                                    $chartW = 700; $chartH = 220;
                                    $padTop = 24; $padBottom = 12; $padLeft = 34; $padRight = 14;
                                    $plotW = $chartW - $padLeft - $padRight;
                                    $plotH = $chartH - $padTop - $padBottom;

                                    $maxCount = max($salesTrendCounts) ?: 1;
                                    $axisMax = max(4, (int) ceil($maxCount / 4) * 4);
                                    $yTicks = [0, (int) round($axisMax * 0.25), (int) round($axisMax * 0.5), (int) round($axisMax * 0.75), $axisMax];

                                    $n = count($salesTrend7d);
                                    $pts = [];
                                    foreach ($salesTrend7d as $i => $row) {
                                        $x = $n > 1 ? $padLeft + ($i / ($n - 1)) * $plotW : $padLeft;
                                        $y = $padTop + $plotH - (($row['count'] / $axisMax) * $plotH);
                                        $pts[] = ['x' => round($x, 1), 'y' => round($y, 1), 'count' => $row['count']];
                                    }

                                    $linePath = '';
                                    if ($n > 0) {
                                        $linePath = 'M ' . $pts[0]['x'] . ',' . $pts[0]['y'];
                                        for ($i = 1; $i < $n; $i++) {
                                            $linePath .= ' L ' . $pts[$i]['x'] . ',' . $pts[$i]['y'];
                                        }
                                    }
                                    $areaPath = $linePath . ' L ' . ($padLeft + $plotW) . ',' . ($padTop + $plotH)
                                              . ' L ' . $padLeft . ',' . ($padTop + $plotH) . ' Z';
                                ?>
                                <div class="activity-chart-wrap">
                                    <svg class="activity-chart-svg" viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" preserveAspectRatio="xMidYMid meet">
                                        <defs>
                                            <linearGradient id="salesTrendAreaFill" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="0%" stop-color="var(--brand-primary)" stop-opacity="0.22"></stop>
                                                <stop offset="100%" stop-color="var(--brand-primary)" stop-opacity="0"></stop>
                                            </linearGradient>
                                        </defs>

                                        <?php foreach ($yTicks as $tick): ?>
                                            <?php $tickY = round($padTop + $plotH - (($tick / $axisMax) * $plotH), 1); ?>
                                            <line x1="<?= $padLeft ?>" y1="<?= $tickY ?>" x2="<?= $padLeft + $plotW ?>" y2="<?= $tickY ?>" stroke="var(--surface-border-soft)" stroke-width="1" stroke-dasharray="3 4"></line>
                                            <text x="<?= $padLeft - 8 ?>" y="<?= $tickY + 3 ?>" text-anchor="end" class="activity-chart-axis-label"><?= $tick ?></text>
                                        <?php endforeach; ?>

                                        <path d="<?= htmlspecialchars($areaPath, ENT_QUOTES, 'UTF-8') ?>" fill="url(#salesTrendAreaFill)"></path>
                                        <path d="<?= htmlspecialchars($linePath, ENT_QUOTES, 'UTF-8') ?>" fill="none" stroke="var(--brand-primary)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"></path>

                                        <?php foreach ($pts as $i => $p): ?>
                                            <?php $isLast = $i === $n - 1; ?>
                                            <?php if ($isLast): ?>
                                                <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="9" fill="var(--brand-primary)" opacity="0.15"></circle>
                                            <?php endif; ?>
                                            <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="<?= $isLast ? 5 : 3.5 ?>" fill="#fff" stroke="var(--brand-primary)" stroke-width="<?= $isLast ? 3 : 2 ?>"></circle>
                                            <text x="<?= $p['x'] ?>" y="<?= max(12, $p['y'] - 12) ?>" text-anchor="middle" class="activity-chart-point-label<?= $isLast ? ' activity-chart-point-label-current' : '' ?>"><?= (int) $p['count'] ?></text>
                                        <?php endforeach; ?>
                                    </svg>
                                    <div class="activity-chart-labels">
                                        <?php foreach ($salesTrend7d as $i => $row): ?>
                                            <span class="<?= $i === $n - 1 ? 'activity-chart-label-current' : '' ?>"><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- PO status (chỉ PO của Manager hiện tại - khác PO Workflow bên Admin) -->
                    <div class="col-12 col-xl-5">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Đơn đặt hàng của tôi</h3>
                                <a href="purchase_order/po-status.php" class="panel-card-link">Xem tất cả &rarr;</a>
                            </div>

                            <?php
                                $totalPoCount = array_sum(array_column($poStatusDistribution, 'count'));
                                $maxPoCount = max(array_column($poStatusDistribution, 'count')) ?: 1;
                                $poAxisMax = max(4, (int) ceil($maxPoCount / 4) * 4);
                            ?>
                            <?php if ($totalPoCount === 0): ?>
                                <div class="empty-state">Bạn chưa tạo đơn đặt hàng nào.</div>
                            <?php else: ?>
                                <div class="po-workflow-chart" style="height: 170px;">
                                    <?php foreach ($poStatusDistribution as $col): ?>
                                        <?php
                                            $barHeightPct = $col['count'] > 0 ? max(3, round(($col['count'] / $poAxisMax) * 100)) : 0;
                                            $statusColorClass = match ($col['status']) {
                                                'Rejected'  => 'po-workflow-bar-danger',
                                                'Delivered' => 'po-workflow-bar-success',
                                                'Approved'  => 'po-workflow-bar-info',
                                                'Pending'   => 'po-workflow-bar-warning',
                                                default     => 'po-workflow-bar-muted',
                                            };
                                        ?>
                                        <div class="po-workflow-col">
                                            <div class="po-workflow-bar-track">
                                                <div class="po-workflow-bar <?= $statusColorClass ?>" style="height: <?= $barHeightPct ?>%;">
                                                    <span class="po-workflow-bar-value"><?= number_format($col['count']) ?></span>
                                                </div>
                                            </div>
                                            <span class="po-workflow-label"><?= htmlspecialchars($col['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Low-stock alerts chi tiết (FR-MGR-03, BR-04, BR-13) -->
                <div class="row g-3 mt-0">
                    <div class="col-12">
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Danh sách cảnh báo tồn thấp</h3>
                                <span class="panel-card-note"><?= number_format($lowStockCount) ?> sản phẩm dưới Reorder Point</span>
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
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../components/footer.php'; ?>
<?php
/**
 * File: frontend/manager/product_pfm.php
 * Purpose: Product Performance Analysis - xếp hạng sản phẩm theo số lượng
 * bán, kèm turnover ratio (theo số lượng và theo giá trị COGS). Dùng
 * ManagerService::getProductPerformanceReport($fromDate, $toDate).
 * Related: FR-MGR-09
 *
 * QUAN TRỌNG (giữ đúng disclaimer từ ManagerService): "Revenue" hiển thị ở
 * đây thực chất là SỐ LƯỢNG bán, không phải doanh thu bằng tiền, vì hệ
 * thống chưa có cột giá bán lẻ - trang này PHẢI hiển thị rõ ghi chú này cho
 * Manager, không được ngầm hiểu là số liệu tài chính thật.
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

// Mặc định: 30 ngày gần nhất, cho phép Manager tự chọn khoảng ngày khác qua form GET
$fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
$toDate   = $_GET['to_date'] ?? date('Y-m-d 23:59:59');

$report = $managerService->getProductPerformanceReport($fromDate, $toDate . ' 23:59:59');

$pageTitle = 'Product Performance';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/sidebar.php';
?>

<h1 class="h4 mb-4">Product Performance Analysis</h1>

<div class="alert alert-secondary">
    <i class="bi bi-info-circle"></i>
    The system does not yet store retail selling price — figures below use
    <strong>quantity sold</strong> as a proxy for demand, and
    <strong>COGS ÷ stock value</strong> (using purchase cost) as a proxy for turnover rate.
    These are not real revenue/financial figures.
</div>

<form method="get" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <label class="form-label small mb-0">From</label>
        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
    </div>
    <div class="col-auto">
        <label class="form-label small mb-0">To</label>
        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars(substr($toDate, 0, 10)) ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    </div>
</form>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-success-subtle">
                <i class="bi bi-graph-up-arrow text-success"></i> Top Best-Sellers
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Product</th>
                            <th class="text-end">Qty Sold</th>
                            <th class="text-end">Turnover (value)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $top = array_slice($report['products'], 0, 10); ?>
                        <?php if (empty($top)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No sales data in this period.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($top as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['sku_code']) ?></td>
                                <td><?= htmlspecialchars($row['product_name']) ?></td>
                                <td class="text-end fw-bold text-success"><?= (int) $row['total_quantity_sold'] ?></td>
                                <td class="text-end">
                                    <?= $row['turnover_value_ratio'] !== null ? htmlspecialchars((string) $row['turnover_value_ratio']) : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-danger-subtle">
                <i class="bi bi-graph-down-arrow text-danger"></i> Slow-Moving Products
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Product</th>
                            <th class="text-end">Qty Sold</th>
                            <th class="text-end">Current Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $slowMoving = array_slice(array_reverse($report['products']), 0, 10);
                        ?>
                        <?php if (empty($slowMoving)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No data available.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($slowMoving as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['sku_code']) ?></td>
                                <td><?= htmlspecialchars($row['product_name']) ?></td>
                                <td class="text-end text-danger"><?= (int) $row['total_quantity_sold'] ?></td>
                                <td class="text-end"><?= (int) $row['current_stock'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">Full Ranking (<?= count($report['products']) ?> products)</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>SKU</th>
                            <th>Product</th>
                            <th class="text-end">Qty Sold</th>
                            <th class="text-end">Current Stock</th>
                            <th class="text-end">Turnover (qty)</th>
                            <th class="text-end">Turnover (value)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['products'] as $i => $row): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($row['sku_code']) ?></td>
                                <td><?= htmlspecialchars($row['product_name']) ?></td>
                                <td class="text-end"><?= (int) $row['total_quantity_sold'] ?></td>
                                <td class="text-end"><?= (int) $row['current_stock'] ?></td>
                                <td class="text-end">
                                    <?= $row['turnover_ratio_approx'] !== null ? htmlspecialchars((string) $row['turnover_ratio_approx']) : '—' ?>
                                </td>
                                <td class="text-end">
                                    <?= $row['turnover_value_ratio'] !== null ? htmlspecialchars((string) $row['turnover_value_ratio']) : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../components/footer.php'; ?>
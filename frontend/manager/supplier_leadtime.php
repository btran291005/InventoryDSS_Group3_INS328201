<?php
/**
 * File: frontend/manager/supplier_leadtime.php
 * Purpose: Báo cáo lead-time và tỉ lệ sai lệch giao hàng của từng nhà cung
 * cấp, kèm gợi ý nhà cung cấp tin cậy nhất khi chuẩn bị tạo PO mới. Dùng
 * ManagerService::listSuppliers()/getSupplierPerformance()/
 * getMostReliableSuppliers() (đã có sẵn).
 * Related: FR-MGR-11
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

$suppliers = $managerService->listSuppliers();
$mostReliable = $managerService->getMostReliableSuppliers(5);

// Gộp performance stats cho từng supplier hiển thị ở bảng chính
$performanceBySupplierId = [];
foreach ($suppliers as $supplier) {
    $stats = $managerService->getSupplierPerformance((int) $supplier['supplier_id']);
    $performanceBySupplierId[(int) $supplier['supplier_id']] = $stats;
}

$pageTitle = 'Supplier Lead-Time Report';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/sidebar.php';
?>

<h1 class="h4 mb-4">Supplier Lead-Time & Delivery Accuracy</h1>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">All Suppliers</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Contact</th>
                            <th class="text-end">Avg Lead-Time (days)</th>
                            <th class="text-end">Delivered Orders</th>
                            <th class="text-end">Discrepancy Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($suppliers)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No suppliers found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($suppliers as $supplier): ?>
                            <?php $stats = $performanceBySupplierId[(int) $supplier['supplier_id']] ?? false; ?>
                            <tr>
                                <td><?= htmlspecialchars($supplier['supplier_name']) ?></td>
                                <td><?= htmlspecialchars($supplier['contact_phone'] ?? '—') ?></td>
                                <td class="text-end"><?= htmlspecialchars((string) ($supplier['avg_lead_time_days'] ?? '—')) ?></td>
                                <td class="text-end"><?= $stats ? (int) $stats['total_delivered_orders'] : 0 ?></td>
                                <td class="text-end">
                                    <?php if ($stats && $stats['discrepancy_rate_percent'] !== null): ?>
                                        <?php $rate = (float) $stats['discrepancy_rate_percent']; ?>
                                        <span class="badge <?= $rate > 20 ? 'bg-danger' : ($rate > 5 ? 'bg-warning text-dark' : 'bg-success') ?>">
                                            <?= $rate ?>%
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">No delivered orders yet</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer text-muted small">
                Avg Lead-Time is a reference value set by Admin; Discrepancy Rate is calculated
                from actual delivered orders (BR-08/BR-09/BR-10).
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-star-fill text-warning"></i> Most Reliable Suppliers
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr><th>Supplier</th><th class="text-end">Discrepancy</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($mostReliable)): ?>
                            <tr><td colspan="2" class="text-center text-muted py-3">Not enough delivery history yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($mostReliable as $i => $supplier): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary me-1"><?= $i + 1 ?></span>
                                    <?= htmlspecialchars($supplier['supplier_name']) ?>
                                </td>
                                <td class="text-end"><?= htmlspecialchars((string) ($supplier['discrepancy_rate_percent'] ?? 0)) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer text-muted small">
                Recommended when creating a new Purchase Order, ranked by lowest discrepancy
                rate then lowest lead-time.
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../components/footer.php'; ?>
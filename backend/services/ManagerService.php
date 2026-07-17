// backend/services/ManagerService.php
/**
 * File: ManagerService.php
 * Purpose: Business logic for Manager actions — dashboard data aggregation,
 *          Top 10 stock-out risk calculation, reorder suggestions, PO submission,
 *          demand trend and product performance analytics.
 * Related: FR-MGR-01 through FR-MGR-11
 * Note: FR-MGR-12 formula (current_stock / avg_7day_sales) is implemented in
 *       method getStockoutRisk(). Keep this formula isolated and documented —
 *       it is the most likely requirement to change after user testing.
 */
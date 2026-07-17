// backend/models/Order.php
/**
 * File: Order.php
 * Purpose: CRUD for purchase_orders and purchase_order_details, including status
 *          transitions (Draft -> Pending -> Approved/Rejected -> Delivered).
 * Related: BR-07, BR-20 (record locked while Pending)
 */
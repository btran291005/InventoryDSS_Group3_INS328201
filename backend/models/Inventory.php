// backend/models/Inventory.php
/**
 * File: Inventory.php
 * Purpose: CRUD/queries for stock, stock_batches, stock_movements.
 *          Includes FEFO batch-selection logic (nearest expiry_date first).
 * Related: FR-STF-01, FR-STF-02, FR-STF-08, FR-STF-14, BR-03 (no negative stock)
 */
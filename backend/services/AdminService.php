// backend/services/AdminService.php
/**
 * File: AdminService.php
 * Purpose: Business logic for all Admin actions — account/permission management,
 *          category-based reorder rule configuration, PO approval, API config,
 *          audit log retrieval.
 * Related: FR-ADM-01 through FR-ADM-11, BR-16, BR-17, BR-20
 * Note: PO approval logic (including the BR-20 edit-lock check) lives here,
 *       not in the UI page.
 */
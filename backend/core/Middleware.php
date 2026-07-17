// backend/core/Middleware.php
/**
 * File: Middleware.php
 * Purpose: Server-side RBAC check — checkPermission($role, $permission_code)
 *          called at the top of every page in public/admin, public/manager, public/staff.
 * Related: NFR-03, FR-ADM-02, FR-ADM-03
 * Warning: This is the single point of access control — do not duplicate role
 *          checks elsewhere. UI hiding (sidebar.php) is NOT a substitute for this.
 */
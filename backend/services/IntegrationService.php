// backend/services/IntegrationService.php
/**
 * File: IntegrationService.php
 * Purpose: Wraps calls to external APIs (alert notification, demand forecast).
 *          Contains the fallback logic: if the forecast API fails or times out,
 *          returns a rule-based suggestion instead of throwing an error.
 * Related: FR-SYS-02, FR-SYS-03, NFR-06
 * Warning: Never let a failed external API call block the reorder workflow.
 */
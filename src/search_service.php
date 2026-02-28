<?php
/**
 * search_service.php - AJAX endpoint for hierarchy-filtered MedDRA search
 *
 * This is a no-auth page. REDCap External Module framework provides $module
 * automatically when this file is loaded — do NOT assign $this here.
 *
 * Query parameters:
 *   category    - MEDDRA_SOC, MEDDRA_HLGT, MEDDRA_HLT, MEDDRA_PT, MEDDRA_LLT
 *   search      - search term
 *   parent_code - (optional) parent MedDRA code to filter by
 *   limit       - (optional) max results (default 20)
 */

if (!isset($module)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Module not initialized']);
    exit;
}

$module->handleSearchRequest();

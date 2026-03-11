<?php
namespace AECRI\MeddraHierarchyProvider;

// Load the full implementation
require_once __DIR__ . '/MeddraHierarchyProviderModule.php';

/**
 * REDCap expects the main module class to be named after the last segment of the
 * namespace (i.e., "MeddraHierarchyProvider"). The actual implementation lives in
 * MeddraHierarchyProviderModule. This thin alias satisfies REDCap's naming convention
 * while keeping all logic in the original file.
 */
class MeddraHierarchyProvider extends MeddraHierarchyProviderModule
{
    // All functionality is inherited from MeddraHierarchyProviderModule.
}

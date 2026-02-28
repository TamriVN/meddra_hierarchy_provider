<?php
namespace AECRI\MeddraHierarchyProvider;

use \ExternalModules\AbstractExternalModule;

/**
 * MedDRA Hierarchy Provider — REDCap External Module
 * =====================================================================
 *
 * PURPOSE
 * -------
 * This module provides hierarchical MedDRA autocomplete fields inside REDCap
 * data-entry forms and surveys. Instead of one flat search box covering all
 * ~70 000 MedDRA terms, users get five cascading fields:
 *
 *   SOC  →  HLGT  →  HLT  →  PT  →  LLT
 *
 * Selecting a value at one level automatically restricts the options in the
 * next level to only the children of that selection.
 *
 * HOW REDCap FINDS THIS MODULE
 * ----------------------------
 * REDCap's External Module framework (≥ v12) supports the OntologyProvider
 * interface (introduced in REDCap 8.8.1). Any EM that implements
 * \OntologyProvider is automatically listed in the Online Designer's
 * "Ontology / BioPortal" dropdown. This module implements that interface.
 *
 * ONTOLOGY IDs (used in the Data Dictionary "Choices" column)
 * -----------------------------------------------------------
 *   MEDDRA_HIER:MEDDRA_SOC   — System Organ Class       (≈ 27 terms)
 *   MEDDRA_HIER:MEDDRA_HLGT  — High Level Group Term    (≈ 338 terms)
 *   MEDDRA_HIER:MEDDRA_HLT   — High Level Term          (≈ 1 737 terms)
 *   MEDDRA_HIER:MEDDRA_PT    — Preferred Term           (≈ 23 000 terms)
 *   MEDDRA_HIER:MEDDRA_LLT   — Lowest Level Term        (≈ 79 000 current)
 *
 * PARENT LINKING (action tag)
 * ---------------------------
 * Add  @MEDDRA_PARENT=<parent_field_name>  to the Field Annotation of each
 * child field. The JavaScript injected by this module reads the annotation,
 * looks up the parent field's current value, and passes it as ?parent_code=
 * to the search endpoint so only relevant children are returned.
 *
 * DATA FLOW
 * ---------
 * 1. Module is instantiated → registers itself with \OntologyManager.
 * 2. On data-entry / survey page load → injectHierarchyJS() reads the
 *    instrument's data dictionary, finds @MEDDRA_PARENT annotations, and
 *    outputs a small <script> block.
 * 3. That script intercepts jQuery UI Autocomplete on child fields and
 *    re-routes searches to search_service.php (no-auth AJAX endpoint).
 * 4. search_service.php calls handleSearchRequest(), which loads the JSON
 *    cache, filters by the supplied parent_code, and returns JSON results.
 * 5. First time the cache is needed it is built from the .asc files
 *    configured in System Settings and saved as a flat JSON file for speed.
 *
 * FILE STRUCTURE (deployed module folder)
 * ----------------------------------------
 *   meddra_hierarchy_provider_v1.0.0/
 *   ├── config.json                       REDCap EM metadata & settings
 *   ├── MeddraHierarchyProviderModule.php  ← this file
 *   ├── search_service.php                AJAX no-auth search endpoint
 *   └── README.md                         Usage instructions
 *
 * @author  MedDRA REDCap Integration — AECRI
 * @version 1.0.0
 */
class MeddraHierarchyProviderModule extends AbstractExternalModule implements \OntologyProvider
{
    // ── Constants ─────────────────────────────────────────────────────────────

    /**
     * Prefix stored in the REDCap data dictionary "Choices" column.
     * REDCap uses this to route ontology lookups to the correct provider.
     * Format in the data dictionary:  MEDDRA_HIER:MEDDRA_SOC
     */
    const SERVICE_PREFIX = 'MEDDRA_HIER';

    /**
     * The five MedDRA hierarchy levels supported by this module.
     * These are the "category" values that follow the colon in the DD entry.
     */
    const CATEGORIES = ['MEDDRA_SOC', 'MEDDRA_HLGT', 'MEDDRA_HLT', 'MEDDRA_PT', 'MEDDRA_LLT'];

    /**
     * Defines which level is the direct parent of each level.
     * Used for documentation / future validation; the actual hierarchy
     * is encoded in the soc_hlgt / hlgt_hlt / hlt_pt / pt_llt maps.
     */
    const PARENT_MAP = [
        'MEDDRA_SOC'  => null,          // top level — no parent
        'MEDDRA_HLGT' => 'MEDDRA_SOC',
        'MEDDRA_HLT'  => 'MEDDRA_HLGT',
        'MEDDRA_PT'   => 'MEDDRA_HLT',
        'MEDDRA_LLT'  => 'MEDDRA_PT',
    ];

    /**
     * Human-readable labels shown in the Online Designer level picker.
     */
    const LEVEL_LABELS = [
        'MEDDRA_SOC'  => 'SOC - System Organ Class',
        'MEDDRA_HLGT' => 'HLGT - High Level Group Term',
        'MEDDRA_HLT'  => 'HLT - High Level Term',
        'MEDDRA_PT'   => 'PT - Preferred Term',
        'MEDDRA_LLT'  => 'LLT - Lowest Level Term',
    ];

    // ── Internal state ────────────────────────────────────────────────────────

    /**
     * In-memory cache of the parsed MedDRA data.
     * Populated by loadCache() on first use and reused within the same request.
     * Structure: see buildCacheFromAsc() for the full array shape.
     *
     * @var array|null
     */
    private $cache = null;

    // ================================================================
    // CONSTRUCTOR & REDCap HOOKS
    // ================================================================

    /**
     * Module constructor.
     *
     * Registers this instance as an OntologyProvider with REDCap's
     * OntologyManager so the module appears in the Online Designer dropdown
     * and REDCap routes autocomplete requests here.
     *
     * NOTE: The constructor runs once per page load when the EM framework
     * instantiates the module. The hook methods below may or may not also
     * fire depending on which page REDCap is rendering.
     */
    public function __construct()
    {
        parent::__construct();

        // OntologyManager is only available in REDCap ≥ 8.8.1.
        // The class_exists guard prevents fatal errors on older installations.
        if (class_exists('\OntologyManager')) {
            $manager = \OntologyManager::getOntologyManager();
            $manager->addProvider($this);
        }
    }

    /**
     * Hook: fires on every REDCap page before rendering.
     *
     * The actual OntologyProvider registration is handled in the constructor
     * so this method body is empty. Declaring the hook in config.json and
     * having an empty method here ensures the module is instantiated (and
     * thus registered) on every page — including the Online Designer — not
     * only on data-entry pages.
     *
     * @param int|null $project_id Current project ID (null on system pages)
     */
    public function redcap_every_page_before_render($project_id)
    {
        // Registration happens in __construct(); nothing else needed here.
    }

    /**
     * Hook: fires after a data-entry form is rendered.
     *
     * Injects the JavaScript that links child autocomplete fields to their
     * parent fields using @MEDDRA_PARENT annotations.
     *
     * @param int    $project_id      REDCap project ID
     * @param string $record          Record name / ID
     * @param string $instrument      Instrument (form) name
     * @param int    $event_id        Event ID (longitudinal projects)
     * @param int    $group_id        DAG group ID (or null)
     * @param int    $repeat_instance Repeat instrument instance number
     */
    public function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
        $this->injectHierarchyJS($project_id, $instrument);
    }

    /**
     * Hook: fires after a survey page is rendered.
     *
     * Same injection as data-entry forms so hierarchy filtering works on
     * public-facing surveys too.
     *
     * @param int    $project_id   REDCap project ID
     * @param string $record       Record name / ID
     * @param string $instrument   Instrument (form) name
     * @param int    $event_id     Event ID
     * @param int    $group_id     DAG group ID (or null)
     * @param string $survey_hash  Survey hash token
     * @param int    $response_id  Survey response ID
     * @param int    $repeat_instance Repeat instrument instance number
     */
    public function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        $this->injectHierarchyJS($project_id, $instrument);
    }

    // ================================================================
    // OntologyProvider INTERFACE METHODS
    // ================================================================
    // These four methods are required by the \OntologyProvider interface.
    // REDCap calls them to display the module in the Online Designer and
    // to resolve autocomplete searches and stored code → label lookups.

    /**
     * Returns the display name shown in the REDCap Online Designer ontology
     * service dropdown (e.g. alongside "BioPortal", "LOINC", etc.).
     *
     * The optional "meddra-version-label" system setting appends the version
     * number so users can distinguish between multiple installed versions.
     *
     * @return string  e.g. "MedDRA Hierarchy (v27.1)"
     */
    public function getProviderName()
    {
        $version = $this->getSystemSetting('meddra-version-label') ?: '';
        $label = 'MedDRA Hierarchy';
        if ($version) $label .= " (v{$version})";
        return $label;
    }

    /**
     * Returns the service prefix string that REDCap stores in the data
     * dictionary to identify which provider owns a given ontology field.
     *
     * The stored value in the "Choices" column looks like:
     *   MEDDRA_HIER:MEDDRA_SOC
     *   ^^^^^^^^^^^  ←─ this prefix
     *
     * @return string  Always 'MEDDRA_HIER'
     */
    public function getServicePrefix()
    {
        return self::SERVICE_PREFIX;
    }

    /**
     * Returns the HTML fragment rendered inside the Online Designer's
     * ontology configuration panel when this provider is selected.
     *
     * The HTML contains:
     *  - A <select> for choosing the MedDRA level (SOC / HLGT / HLT / PT / LLT)
     *  - Usage instructions for the @MEDDRA_PARENT action tag
     *  - A small <script> with a callback REDCap calls when the selection changes
     *
     * REDCap convention: the JavaScript function must be named
     * {SERVICE_PREFIX}_ontology_changed(service, category)
     * and REDCap calls it whenever the user picks a different ontology/category
     * in the designer so the UI can sync its own dropdown.
     *
     * @return string  Raw HTML (will be echo'd by REDCap)
     */
    public function getOnlineDesignerSection()
    {
        $categories = self::CATEGORIES;
        $labels     = self::LEVEL_LABELS;

        $html  = '<div id="meddra_hier_section" style="padding:10px;">';
        $html .= '<p><b>MedDRA Hierarchy Level:</b></p>';
        $html .= '<select id="meddra_hier_category" '
               . 'onchange="update_ontology_selection(\'' . self::SERVICE_PREFIX . '\', this.value);" '
               . 'style="max-width:400px;">';
        $html .= '<option value="">-- Select MedDRA level --</option>';
        foreach ($categories as $cat) {
            $html .= '<option value="' . $cat . '">' . $labels[$cat] . '</option>';
        }
        $html .= '</select>';
        $html .= '<p style="margin-top:8px;color:#666;font-size:12px;">';
        $html .= 'Use <code>@MEDDRA_PARENT=field_name</code> in Field Annotation to link child fields to their parent.<br>';
        $html .= 'Example: For the HLGT field, add <code>@MEDDRA_PARENT=meddra_soc</code> to filter HLGTs by the selected SOC.';
        $html .= '</p>';
        $html .= '</div>';

        // REDCap calls this function whenever the active ontology/category changes
        // in the designer so that this provider's UI stays in sync.
        $html .= '<script>';
        $html .= 'function ' . self::SERVICE_PREFIX . '_ontology_changed(service, category) {';
        $html .= '  if (service == "' . self::SERVICE_PREFIX . '") {';
        $html .= '    document.getElementById("meddra_hier_category").value = category || "";';
        $html .= '  } else {';
        $html .= '    document.getElementById("meddra_hier_category").value = "";';
        $html .= '  }';
        $html .= '}';
        $html .= '</script>';

        return $html;
    }

    /**
     * Searches the MedDRA ontology — called by REDCap's built-in autocomplete.
     *
     * This is the standard OntologyProvider search path (used when REDCap
     * handles the AJAX directly). The hierarchy-filtered path for data-entry
     * uses search_service.php instead (see handleSearchRequest()), but REDCap
     * may still call this method for other contexts (e.g. reports, imports).
     *
     * The search matches ALL supplied words against both the term label and
     * the MedDRA code. Results are scored so that earlier/code matches rank
     * higher, and then truncated to $result_limit.
     *
     * @param string $category     One of the CATEGORIES constants
     * @param string $search_term  Text the user typed in the autocomplete box
     * @param int    $result_limit Maximum number of results to return
     * @return array               Associative array: [code => label, ...]
     */
    public function searchOntology($category, $search_term, $result_limit)
    {
        $data = $this->loadCache();
        if (!$data) return [];

        // When called via direct AJAX (e.g. from search_service.php fallback),
        // a parent_code may be passed as a GET parameter.
        $parent_code = isset($_GET['parent_code']) ? $_GET['parent_code'] : null;

        // Retrieve the flat list of terms for this level, optionally pre-filtered
        // to only children of the given parent code.
        $terms = $this->getTermsForLevel($data, $category, $parent_code);

        // ── Multi-word search with scoring ──────────────────────────────────
        $results      = [];
        $search_lower = mb_strtolower($search_term);
        // Split on whitespace so "cardiac arrhythmia" matches both words
        $search_words = preg_split('/\s+/', $search_lower, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($terms as $code => $label) {
            $label_lower = mb_strtolower($label);
            $match       = true;
            $match_score = 0;

            foreach ($search_words as $word) {
                $pos_label = mb_strpos($label_lower, $word);
                $pos_code  = mb_strpos((string)$code, $word);

                // ALL words must appear somewhere — if any word is missing, skip
                if ($pos_label === false && $pos_code === false) {
                    $match = false;
                    break;
                }
                // Earlier position in label = higher score (max label bonus: 1000)
                if ($pos_label !== false) $match_score += (1000 - $pos_label);
                // Matching the numeric code itself gets a flat bonus
                if ($pos_code !== false)  $match_score += 500;
            }

            if ($match) {
                $results[$code] = ['label' => $label, 'score' => $match_score];
            }
        }

        // Sort descending by score so the best matches appear first
        uasort($results, function($a, $b) { return $b['score'] - $a['score']; });

        // Return only code => label (strip internal score field) up to the limit
        $output = [];
        $count  = 0;
        foreach ($results as $code => $info) {
            if ($count >= $result_limit) break;
            $output[$code] = $info['label'];
            $count++;
        }

        return $output;
    }

    /**
     * Returns the human-readable label for a stored MedDRA code.
     *
     * REDCap calls this when it needs to display a saved code value as text,
     * for example in reports, email alerts, or the data-entry form itself
     * after a record is saved and reloaded.
     *
     * @param string $category  One of the CATEGORIES constants
     * @param string $value     The stored MedDRA code (e.g. "10005329")
     * @return string           The term label, or the raw code if not found
     */
    public function getLabelForValue($category, $value)
    {
        $data = $this->loadCache();
        if (!$data) return $value;

        $level_key = $this->getLevelKey($category);
        if ($level_key && isset($data[$level_key][$value])) {
            return $data[$level_key][$value];
        }

        // Graceful fallback: return the raw code rather than an empty string
        return $value;
    }

    // ================================================================
    // AJAX SEARCH ENDPOINT (no-auth page — called from search_service.php)
    // ================================================================

    /**
     * Handles an AJAX search request from the injected JavaScript.
     *
     * This method is called by search_service.php (a no-auth EM page) which
     * is the custom, hierarchy-aware search path used during data entry.
     * It differs from searchOntology() in that:
     *   - It always uses the parent_code filter if supplied.
     *   - An empty search term returns ALL terms for the level (up to limit),
     *     which allows browsing without typing.
     *   - Results are returned as JSON: {"results":[{"code":"...","label":"..."},...]}
     *
     * Expected GET parameters:
     *   category    — e.g. 'MEDDRA_HLGT'
     *   search      — user's search text (may be empty)
     *   parent_code — (optional) parent MedDRA code to restrict results
     *   limit       — (optional) max results, default 20
     *
     * Outputs JSON directly and should not return anything meaningful.
     */
    public function handleSearchRequest()
    {
        header('Content-Type: application/json');

        $category    = $_GET['category']    ?? '';
        $search      = $_GET['search']      ?? '';
        $parent_code = $_GET['parent_code'] ?? '';
        $limit       = intval($_GET['limit'] ?? 20);

        // Validate the category to prevent probing non-MedDRA data
        if (!in_array($category, self::CATEGORIES)) {
            echo json_encode(['error' => 'Invalid category']);
            return;
        }

        $data = $this->loadCache();
        if (!$data) {
            echo json_encode(['error' => 'MedDRA data not loaded. Check system settings.']);
            return;
        }

        // Get the flat term list for this level, filtered by parent if supplied
        $terms = $this->getTermsForLevel($data, $category, $parent_code ?: null);

        // ── Search & score ───────────────────────────────────────────────────
        $results      = [];
        $search_lower = mb_strtolower($search);
        $search_words = preg_split('/\s+/', $search_lower, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($terms as $code => $label) {
            $label_lower = mb_strtolower($label);
            $match       = true;
            $score       = 0;

            if (empty($search_words)) {
                // Empty search — include all terms (up to $limit) for browsing
                $match = true;
                $score = 0;
            } else {
                foreach ($search_words as $word) {
                    $pos_l = mb_strpos($label_lower, $word);
                    $pos_c = mb_strpos((string)$code, $word);
                    if ($pos_l === false && $pos_c === false) {
                        $match = false;
                        break;
                    }
                    if ($pos_l !== false) $score += (1000 - $pos_l);
                    if ($pos_c !== false) $score += 500;
                }
            }

            if ($match) {
                $results[] = ['code' => $code, 'label' => $label, 'score' => $score];
            }
        }

        // ── Sort: score DESC, then label ASC for ties ────────────────────────
        usort($results, function($a, $b) {
            if ($b['score'] !== $a['score']) return $b['score'] - $a['score'];
            return strcmp($a['label'], $b['label']);
        });

        // Trim to limit and strip the internal score field from the response
        $results = array_slice($results, 0, $limit);
        foreach ($results as &$r) unset($r['score']);

        echo json_encode(['results' => $results, 'total' => count($results)]);
    }

    // ================================================================
    // DATA LOADING & CACHING
    // ================================================================

    /**
     * Loads (or builds) the in-memory MedDRA data cache.
     *
     * Strategy:
     *   1. If already loaded this request, return the in-memory copy.
     *   2. Try reading from the JSON cache file (fast, no .asc parsing needed).
     *   3. If no valid cache exists, parse the .asc files directly, build the
     *      cache structure, and write it to the cache file for next time.
     *
     * The cache file is configured via the "meddra-cache-file" system setting.
     * The .asc source files are configured via "meddra-data-path".
     *
     * @return array|null  The full cache array, or null if data is unavailable
     */
    private function loadCache()
    {
        // Already loaded this request — skip disk I/O
        if ($this->cache !== null) return $this->cache;

        $cache_file = $this->getSystemSetting('meddra-cache-file');

        // ── Attempt to load from cache file ─────────────────────────────────
        if ($cache_file && file_exists($cache_file)) {
            $json        = file_get_contents($cache_file);
            $this->cache = json_decode($json, true);
            if ($this->cache) return $this->cache;
            // json_decode returned null → file is corrupt, fall through to rebuild
        }

        // ── Build cache from .asc source files ──────────────────────────────
        $data_path = $this->getSystemSetting('meddra-data-path');
        if (!$data_path || !is_dir($data_path)) return null;

        $this->cache = $this->buildCacheFromAsc($data_path);

        // ── Persist to disk for subsequent requests ──────────────────────────
        if ($this->cache && $cache_file) {
            $dir = dirname($cache_file);
            if (is_dir($dir) && is_writable($dir)) {
                file_put_contents($cache_file, json_encode($this->cache));
            }
            // If not writable, the module still works — just slower (parses .asc every request)
        }

        return $this->cache;
    }

    /**
     * Parses a single MedDRA .asc file into an array of row arrays.
     *
     * MedDRA .asc files use '$' as a field delimiter. Each line ends with
     * one or more trailing '$' characters (and a Windows \r\n line ending).
     * Blank lines are skipped.
     *
     * Example line from llt.asc:
     *   10000002$11-beta-hydroxylase deficiency$10000002$$$$$$$Y$$\r\n
     * After trim + rtrim('$') + explode('$'):
     *   ['10000002', '11-beta-hydroxylase deficiency', '10000002', '', '', '', '', '', 'Y']
     *
     * @param string $filepath  Absolute path to the .asc file
     * @return array            Array of string arrays (one per data row)
     */
    private function parseAsc($filepath)
    {
        $rows = [];
        if (!file_exists($filepath)) return $rows;

        $handle = fopen($filepath, 'r');
        if (!$handle) return $rows;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);            // removes \r, \n, and leading spaces
            $line = rtrim($line, '$');      // removes trailing '$' delimiters
            if (empty($line)) continue;
            $parts = array_map('trim', explode('$', $line));
            $rows[] = $parts;
        }
        fclose($handle);
        return $rows;
    }

    /**
     * Reads all required MedDRA .asc files and builds the in-memory cache.
     *
     * ── Cache structure ───────────────────────────────────────────────────────
     *
     *   $cache['soc']      = [ soc_code  => soc_name,  ... ]
     *   $cache['hlgt']     = [ hlgt_code => hlgt_name, ... ]
     *   $cache['hlt']      = [ hlt_code  => hlt_name,  ... ]
     *   $cache['pt']       = [ pt_code   => pt_name,   ... ]
     *   $cache['llt']      = [ llt_code  => llt_name,  ... ]   (current terms only)
     *
     *   $cache['soc_hlgt'] = [ soc_code  => [ hlgt_code, ... ], ... ]
     *   $cache['hlgt_hlt'] = [ hlgt_code => [ hlt_code,  ... ], ... ]
     *   $cache['hlt_pt']   = [ hlt_code  => [ pt_code,   ... ], ... ]
     *   $cache['pt_llt']   = [ pt_code   => [ llt_code,  ... ], ... ]
     *
     * ── Column layout of each .asc file (0-based index after parsing) ─────────
     *
     *   soc.asc      [0] soc_code  [1] soc_name   [2] soc_abbrev
     *   hlgt.asc     [0] hlgt_code [1] hlgt_name
     *   hlt.asc      [0] hlt_code  [1] hlt_name
     *   pt.asc       [0] pt_code   [1] pt_name     [2] null  [3] pt_soc_code
     *   llt.asc      [0] llt_code  [1] llt_name    [2] pt_code
     *                [3] whoart  [4] harts  [5] costart  [6] icd9  [7] icd9cm
     *                [8] llt_currency (Y = current, N = non-current)
     *   soc_hlgt.asc [0] soc_code  [1] hlgt_code
     *   hlgt_hlt.asc [0] hlgt_code [1] hlt_code
     *   hlt_pt.asc   [0] hlt_code  [1] pt_code
     *
     * @param string $folder  Absolute path to the directory containing .asc files
     * @return array          The fully populated cache structure described above
     */
    private function buildCacheFromAsc($folder)
    {
        $cache = [
            'soc'      => [],   // soc_code  => soc_name
            'hlgt'     => [],   // hlgt_code => hlgt_name
            'hlt'      => [],   // hlt_code  => hlt_name
            'pt'       => [],   // pt_code   => pt_name
            'llt'      => [],   // llt_code  => llt_name  (current terms only)
            'soc_hlgt' => [],   // soc_code  => [hlgt_code, ...]
            'hlgt_hlt' => [],   // hlgt_code => [hlt_code, ...]
            'hlt_pt'   => [],   // hlt_code  => [pt_code, ...]
            'pt_llt'   => [],   // pt_code   => [llt_code, ...]
        ];

        // ── SOC ──────────────────────────────────────────────────────────────
        // Columns: [0] soc_code  [1] soc_name  [2] soc_abbrev  [3+] ...
        foreach ($this->parseAsc($folder . '/soc.asc') as $row) {
            if (count($row) >= 2) $cache['soc'][$row[0]] = $row[1];
        }

        // ── HLGT ─────────────────────────────────────────────────────────────
        // Columns: [0] hlgt_code  [1] hlgt_name  [2+] ...
        foreach ($this->parseAsc($folder . '/hlgt.asc') as $row) {
            if (count($row) >= 2) $cache['hlgt'][$row[0]] = $row[1];
        }

        // ── HLT ──────────────────────────────────────────────────────────────
        // Columns: [0] hlt_code  [1] hlt_name  [2+] ...
        foreach ($this->parseAsc($folder . '/hlt.asc') as $row) {
            if (count($row) >= 2) $cache['hlt'][$row[0]] = $row[1];
        }

        // ── PT ───────────────────────────────────────────────────────────────
        // Columns: [0] pt_code  [1] pt_name  [2] null  [3] pt_soc_code  [4+] ...
        $pt_file = $folder . '/pt.asc';
        if (file_exists($pt_file)) {
            foreach ($this->parseAsc($pt_file) as $row) {
                if (count($row) >= 2) $cache['pt'][$row[0]] = $row[1];
            }
        }

        // ── LLT ──────────────────────────────────────────────────────────────
        // Columns: [0] llt_code  [1] llt_name  [2] pt_code
        //          [3] whoart  [4] harts  [5] costart  [6] icd9  [7] icd9cm
        //          [8] llt_currency  (Y = current/active, N = non-current/retired)
        //
        // IMPORTANT: We only include current LLTs (currency = 'Y').
        // Non-current LLTs are retired synonyms that should not appear in
        // new data collection. If currency is absent (malformed row), include
        // the term to be safe.
        //
        // BUG NOTE: The currency field is at index [8], NOT [3].
        // Index [3] is llt_whoart_code and is always empty — using it would
        // cause '' === '' to be true and include ALL LLTs (including retired).
        $llt_file = $folder . '/llt.asc';
        if (file_exists($llt_file)) {
            foreach ($this->parseAsc($llt_file) as $row) {
                if (count($row) >= 9) {
                    $currency = trim($row[8]);
                    if ($currency === 'Y' || $currency === '') {
                        $cache['llt'][$row[0]] = $row[1];
                        // Build the reverse PT → [LLT, ...] mapping
                        if (!empty($row[2])) {
                            $cache['pt_llt'][$row[2]][] = $row[0];
                        }
                    }
                } elseif (count($row) >= 2) {
                    // Fallback for malformed rows — include without currency filter
                    $cache['llt'][$row[0]] = $row[1];
                }
            }
        }

        // ── SOC → HLGT mapping ───────────────────────────────────────────────
        // Columns: [0] soc_code  [1] hlgt_code
        foreach ($this->parseAsc($folder . '/soc_hlgt.asc') as $row) {
            if (count($row) >= 2) {
                $cache['soc_hlgt'][$row[0]][] = $row[1];
            }
        }

        // ── HLGT → HLT mapping ───────────────────────────────────────────────
        // Columns: [0] hlgt_code  [1] hlt_code
        foreach ($this->parseAsc($folder . '/hlgt_hlt.asc') as $row) {
            if (count($row) >= 2) {
                $cache['hlgt_hlt'][$row[0]][] = $row[1];
            }
        }

        // ── HLT → PT mapping ─────────────────────────────────────────────────
        // Columns: [0] hlt_code  [1] pt_code
        $hlt_pt_file = $folder . '/hlt_pt.asc';
        if (file_exists($hlt_pt_file)) {
            foreach ($this->parseAsc($hlt_pt_file) as $row) {
                if (count($row) >= 2) {
                    $cache['hlt_pt'][$row[0]][] = $row[1];
                }
            }
        }

        return $cache;
    }

    /**
     * Returns the term list for a given MedDRA level, optionally filtered to
     * only children of a specified parent code.
     *
     * If $parent_code is null or empty, OR if the parent code has no children
     * in the mapping table, the full unfiltered term list for the level is
     * returned (so search still works even if no parent is selected yet).
     *
     * @param array       $data         The full cache array from loadCache()
     * @param string      $category     One of the CATEGORIES constants
     * @param string|null $parent_code  Parent MedDRA code to filter by (optional)
     * @return array                    Associative array: [code => label, ...]
     */
    private function getTermsForLevel($data, $category, $parent_code = null)
    {
        switch ($category) {
            case 'MEDDRA_SOC':
                // SOC is the top level — no parent filter applies
                return $data['soc'] ?? [];

            case 'MEDDRA_HLGT':
                if ($parent_code && isset($data['soc_hlgt'][$parent_code])) {
                    return $this->filterByKeys($data['hlgt'] ?? [], $data['soc_hlgt'][$parent_code]);
                }
                return $data['hlgt'] ?? [];

            case 'MEDDRA_HLT':
                if ($parent_code && isset($data['hlgt_hlt'][$parent_code])) {
                    return $this->filterByKeys($data['hlt'] ?? [], $data['hlgt_hlt'][$parent_code]);
                }
                return $data['hlt'] ?? [];

            case 'MEDDRA_PT':
                if ($parent_code && isset($data['hlt_pt'][$parent_code])) {
                    return $this->filterByKeys($data['pt'] ?? [], $data['hlt_pt'][$parent_code]);
                }
                return $data['pt'] ?? [];

            case 'MEDDRA_LLT':
                if ($parent_code && isset($data['pt_llt'][$parent_code])) {
                    return $this->filterByKeys($data['llt'] ?? [], $data['pt_llt'][$parent_code]);
                }
                return $data['llt'] ?? [];
        }

        return [];
    }

    /**
     * Filters an associative [code => label] array to only keep entries whose
     * keys are present in $allowed_codes.
     *
     * Used by getTermsForLevel() to extract child terms from the flat term
     * lookup using the code lists in the mapping arrays.
     *
     * @param array $all_terms      Full [code => label] map for a level
     * @param array $allowed_codes  List of codes that should be kept
     * @return array                Filtered [code => label] subset
     */
    private function filterByKeys($all_terms, $allowed_codes)
    {
        $result = [];
        foreach ($allowed_codes as $code) {
            if (isset($all_terms[$code])) {
                $result[$code] = $all_terms[$code];
            }
        }
        return $result;
    }

    /**
     * Maps a CATEGORY constant to the corresponding key in the cache array.
     *
     * Example: 'MEDDRA_PT' → 'pt'  (so $cache['pt'] can be accessed)
     *
     * @param string $category  One of the CATEGORIES constants
     * @return string|null      Cache key, or null for an unknown category
     */
    private function getLevelKey($category)
    {
        $map = [
            'MEDDRA_SOC'  => 'soc',
            'MEDDRA_HLGT' => 'hlgt',
            'MEDDRA_HLT'  => 'hlt',
            'MEDDRA_PT'   => 'pt',
            'MEDDRA_LLT'  => 'llt',
        ];
        return $map[$category] ?? null;
    }

    // ================================================================
    // CLIENT-SIDE JAVASCRIPT INJECTION
    // ================================================================

    /**
     * Reads the instrument's data dictionary to find @MEDDRA_PARENT annotations
     * and outputs a <script> block that intercepts REDCap's jQuery UI
     * Autocomplete on child fields.
     *
     * HOW THE JS WORKS
     * ----------------
     * 1. A PHP-side loop finds all fields with @MEDDRA_PARENT=<parent_field>
     *    and all fields whose "Choices" column starts with SERVICE_PREFIX.
     * 2. These two maps (parentMap and fieldCategories) are serialised to JSON
     *    and embedded directly in the <script> block.
     * 3. The JS uses setTimeout(1500ms) to wait for REDCap to finish wiring up
     *    jQuery UI Autocomplete on all ontology fields.
     * 4. For each child field in parentMap:
     *    a. The original jQuery UI autocomplete source function is saved.
     *    b. A new source function is set that:
     *       - Reads the parent field's current value.
     *       - If the parent has a value → calls search_service.php with
     *         ?category=...&search=...&parent_code=... for filtered results.
     *       - If the parent is empty → falls back to the original (unfiltered)
     *         REDCap autocomplete so the user can still search all terms.
     *    c. An 'change blur' listener on the parent field clears the child
     *       whenever the parent changes, preventing stale child selections.
     * 5. A second block sets up cascade clearing so changing SOC also clears
     *    HLGT, HLT, PT, and LLT in one step (hardcoded to default field names;
     *    see NOTE below).
     *
     * NOTE ON FIELD NAMES
     * -------------------
     * The cascade-clearing block uses hardcoded field names:
     *   meddra_soc / meddra_hlgt / meddra_hlt / meddra_pt / meddra_llt
     * If you name your fields differently, the cascade will still work level-
     * by-level (via the event-propagation chain in block 4c), but the full
     * "clear all descendants at once" optimisation in the second block will
     * not fire. Rename the variable in clearOrder[] if needed.
     *
     * @param int    $project_id  REDCap project ID
     * @param string $instrument  Instrument (form) name
     */
    private function injectHierarchyJS($project_id, $instrument)
    {
        // ── Read the data dictionary for this instrument ──────────────────────
        $fields = \REDCap::getDataDictionary($project_id, 'array', false, null, $instrument);

        $parent_map      = [];  // [ child_var => parent_var ]
        $field_categories = []; // [ var => 'MEDDRA_SOC' | 'MEDDRA_HLGT' | ... ]

        foreach ($fields as $var => $meta) {
            $annotation = $meta['field_annotation'] ?? '';

            // Extract @MEDDRA_PARENT=field_name  (case-insensitive, spaces around = allowed)
            if (preg_match('/@MEDDRA_PARENT\s*=\s*(\w+)/i', $annotation, $m)) {
                $parent_map[$var] = $m[1];
            }

            // Record the MedDRA category for fields that use this provider.
            // The "Choices" column for an ontology field stores the value as
            //   SERVICE_PREFIX:CATEGORY   e.g.   MEDDRA_HIER:MEDDRA_HLGT
            $enum = $meta['select_choices_or_calculations'] ?? '';
            if (strpos($enum, self::SERVICE_PREFIX . ':') === 0) {
                $cat = substr($enum, strlen(self::SERVICE_PREFIX) + 1);
                $field_categories[$var] = $cat;
            }
        }

        // No parent annotations on this instrument — nothing to do
        if (empty($parent_map)) return;

        $parent_map_json      = json_encode($parent_map);
        $field_categories_json = json_encode($field_categories);

        // getUrl() generates the absolute URL to search_service.php, with the
        // no-auth token appended so unauthenticated AJAX calls are accepted.
        $search_url = $this->getUrl('search_service.php', true, true);

        // ── Output the JavaScript block ───────────────────────────────────────
        // Notes on the embedded PHP variables:
        //   {$parent_map_json}       — PHP interpolation inside double-quoted string
        //   {$field_categories_json} — same
        //   json_encode($search_url) — concatenated to avoid interpolation issues
        echo "<script>
(function() {
    // parentMap:       { childFieldName: parentFieldName, ... }
    //   Built from @MEDDRA_PARENT annotations in the data dictionary.
    var parentMap = {$parent_map_json};

    // fieldCategories: { fieldName: 'MEDDRA_HLGT' | 'MEDDRA_HLT' | ... }
    //   Used to pass the correct ?category= parameter to search_service.php.
    var fieldCategories = {$field_categories_json};

    // AJAX endpoint — search_service.php via the EM no-auth URL
    var searchUrl = " . json_encode($search_url) . ";

    // ── Block 1: intercept autocomplete on each child field ───────────────────
    // We wait 1500 ms after DOM-ready so REDCap has had time to call
    // jQuery UI Autocomplete on all ontology text fields.
    \$(document).ready(function() {
        setTimeout(function() {
            Object.keys(parentMap).forEach(function(childField) {
                var parentField = parentMap[childField];
                var input = \$('input[name=\"' + childField + '\"]');

                if (input.length === 0) return;  // field not on this page/arm

                // Grab the jQuery UI autocomplete instance REDCap attached
                var acInstance = input.data('ui-autocomplete') || input.data('autocomplete');
                if (!acInstance) return;  // field was not wired up as autocomplete

                var originalSource = acInstance.options.source;

                // Replace the autocomplete source with our hierarchy-aware version
                acInstance.option('source', function(request, response) {
                    var parentVal = \$('input[name=\"' + parentField + '\"]').val();

                    if (parentVal && parentVal.trim() !== '') {
                        // Parent has a value — use our filtered AJAX endpoint
                        var category = fieldCategories[childField] || '';

                        \$.ajax({
                            url: searchUrl,
                            data: {
                                category:    category,
                                search:      request.term,
                                parent_code: parentVal,
                                limit:       20
                            },
                            dataType: 'json',
                            success: function(data) {
                                if (data.results) {
                                    // Format items for jQuery UI: {value, label}
                                    var items = data.results.map(function(r) {
                                        return {
                                            value: r.code,
                                            label: r.code + ' - ' + r.label
                                        };
                                    });
                                    response(items);
                                } else {
                                    response([]);
                                }
                            },
                            error: function() {
                                // Network or server error — fall back to unfiltered search
                                if (typeof originalSource === 'function') {
                                    originalSource(request, response);
                                }
                            }
                        });

                    } else {
                        // No parent selected yet — use original unfiltered search
                        if (typeof originalSource === 'function') {
                            originalSource(request, response);
                        }
                    }
                });

                // When the parent field changes, clear the child so a stale
                // selection from a different parent is not retained.
                // The child's own 'change' event will propagate down the chain,
                // clearing grandchild fields automatically.
                \$('input[name=\"' + parentField + '\"]').on('change blur', function() {
                    var currentChild = input.val();
                    if (currentChild) {
                        input.val('');
                        input.trigger('change');  // cascade: clears children of the child too
                    }
                });
            });
        }, 1500); // 1500 ms delay — adjust if fields still appear before REDCap wires them up
    });

    // ── Block 2: cascade-clear by default field names ─────────────────────────
    // When SOC changes, immediately clear HLGT, HLT, PT, and LLT in order.
    // This complements block 1's event-chain approach and ensures a fast, clean
    // reset even before the autocomplete instances have been intercepted.
    //
    // NOTE: These names are the RECOMMENDED field variable names from the README.
    //       If your project uses different names, update this array accordingly.
    \$(document).ready(function() {
        var clearOrder = ['meddra_soc', 'meddra_hlgt', 'meddra_hlt', 'meddra_pt', 'meddra_llt'];
        clearOrder.forEach(function(field, idx) {
            \$('input[name=\"' + field + '\"]').on('change', function() {
                // Clear every level below this one
                for (var i = idx + 1; i < clearOrder.length; i++) {
                    var childInput = \$('input[name=\"' + clearOrder[i] + '\"]');
                    if (childInput.length > 0 && childInput.val()) {
                        childInput.val('');
                        childInput.trigger('change');
                    }
                }
            });
        });
    });

})();
</script>";
    }
}

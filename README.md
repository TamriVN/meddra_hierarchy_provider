# MedDRA Hierarchy Provider — REDCap External Module

> **For IT / Deployment team:** This repository contains the full source code and tooling to build and deploy the MedDRA Hierarchy Provider module for REDCap. Read this file first, then follow the deployment steps below.
>
> **For end users / researchers:** See [`src/README.md`](src/README.md) (English) or [`src/README_vi.md`](src/README_vi.md) (Tiếng Việt) for usage instructions inside REDCap.

---

## What this module does

This is a [REDCap External Module](https://github.com/vanderbilt-redcap/external-module-framework) that implements the **OntologyProvider** interface to give researchers five cascading MedDRA autocomplete fields on data entry forms and surveys:

```
SOC  →  HLGT  →  HLT  →  PT  →  LLT
(27)   (~338)  (~1737)  (~23k) (~79k current)
```

Selecting a value at one level restricts the next level to only the children of that selection — instead of searching across all 70 000+ terms at once.

**No external internet connection is required.** The module reads MedDRA `.asc` files that are stored locally on the REDCap server.

---

## Repository structure

```
meddra_hierarchy_provider/
│
├── src/                                    Source files that get packaged
│   ├── config.json                         REDCap EM metadata & settings definitions
│   ├── MeddraHierarchyProviderModule.php   Main module class (OntologyProvider interface)
│   ├── search_service.php                  AJAX no-auth endpoint for hierarchy search
│   ├── README.md                           End-user guide (English)
│   └── README_vi.md                        End-user guide (Vietnamese)
│
├── meddra/                                 Reference MedDRA .asc files (DO NOT SHIP)
│   ├── soc.asc                             System Organ Class
│   ├── hlgt.asc                            High Level Group Term
│   ├── hlt.asc                             High Level Term
│   ├── pt.asc                              Preferred Term
│   ├── llt.asc                             Lowest Level Term
│   ├── soc_hlgt.asc                        SOC → HLGT mapping
│   ├── hlgt_hlt.asc                        HLGT → HLT mapping
│   └── hlt_pt.asc                          HLT → PT mapping
│
├── build.sh                                Build / packaging script
├── MedDRA_AE_DataDictionary_Module.csv     Sample REDCap data dictionary CSV
└── README.md                               ← You are here
```

> **Important:** The `meddra/` folder contains licensed MedDRA terminology files. They are included here for local development and reference **only**. They must **not** be committed to any public repository or included in the packaged ZIP. MedDRA is licensed by ICH/MSSO — only authorised users may possess these files.

---

## Prerequisites (for IT team)

| Requirement | Version | Notes |
|---|---|---|
| REDCap | ≥ 10.0.0 | Must have External Module Framework v12 enabled |
| PHP (on REDCap server) | ≥ 7.4 | Part of the standard LAMP/WAMP stack |
| MedDRA `.asc` files | Any current version | Licensed from MSSO/ICH; must be on the REDCap server |
| Disk write permission | — | Web server user (`www-data` or equivalent) must be able to write the cache file |

---

## Building the module package

The `build.sh` script copies the files from `src/` into a correctly named REDCap module folder and zips it up.

**Run from the repository root:**

```bash
# Default: builds version 1.0.0
bash build.sh

# Specify a version number
bash build.sh 1.2.0
```

**Output:**

```
dist/
├── meddra_hierarchy_provider_v1.0.0/      Deployable folder (contents only)
└── meddra_hierarchy_provider_v1.0.0.zip   Upload this to REDCap
```

The script works on Linux/macOS (uses `zip`) and Windows with Git Bash / MSYS (falls back to PowerShell `Compress-Archive`).

---

## Deployment steps

### Step 1 — Copy MedDRA files to the REDCap server

The `.asc` files must be placed in a directory that is:
- Readable by the PHP web server process (`www-data` or equivalent)
- **Not** publicly accessible via a browser URL (keep it outside the web root)

```bash
# Example (adjust to your server layout)
sudo mkdir -p /var/meddra/MedAscii
sudo cp path/to/meddra/*.asc /var/meddra/MedAscii/
sudo chown -R www-data:www-data /var/meddra
sudo chmod 750 /var/meddra/MedAscii
```

Create (and give write permission to) the cache file location:

```bash
sudo touch /var/meddra/meddra_cache.json
sudo chown www-data:www-data /var/meddra/meddra_cache.json
sudo chmod 660 /var/meddra/meddra_cache.json
```

### Step 2 — Install the module in REDCap

**Option A — Upload via the UI (recommended for most environments):**

1. Log in to REDCap as a System Administrator
2. Go to **Control Center → External Modules → Manage**
3. Click **Upload a package**
4. Select `dist/meddra_hierarchy_provider_v1.0.0.zip`
5. Confirm the upload

**Option B — Direct file copy (for servers without a web UI upload path):**

```bash
# Extract the ZIP to the REDCap modules directory
sudo unzip dist/meddra_hierarchy_provider_v1.0.0.zip \
     -d /var/www/redcap/modules/

# Verify the folder is in place
ls /var/www/redcap/modules/meddra_hierarchy_provider_v1.0.0/
# Should show: config.json  MeddraHierarchyProviderModule.php  search_service.php  README.md
```

### Step 3 — Enable the module system-wide

1. Go to **Control Center → External Modules → Manage**
2. Find **MedDRA Hierarchy Provider** in the list and click **Enable**

### Step 4 — Configure system settings

Click **Configure** next to the module and fill in:

| Setting | Value (example) | Required? |
|---|---|---|
| MedDRA Data Directory Path | `/var/meddra/MedAscii` | Yes |
| Cache File Path | `/var/meddra/meddra_cache.json` | Yes |
| MedDRA Version Label | `27.1` | No (display only) |

Click **Save**.

### Step 5 — Verify the cache builds

Open any REDCap project that has the module enabled and trigger a search in a MedDRA autocomplete field. Then confirm the cache file was written:

```bash
ls -lh /var/meddra/meddra_cache.json
# Should be several MB (typically 30–60 MB depending on MedDRA version)
```

If the file is still empty or very small, check the web server error log:

```bash
sudo tail -50 /var/log/apache2/error.log   # Apache
sudo tail -50 /var/log/nginx/error.log     # Nginx
```

---

## Updating to a new MedDRA version

1. Replace the `.asc` files on the server with the new version:

   ```bash
   sudo cp /path/to/new/MedAscii/*.asc /var/meddra/MedAscii/
   ```

2. Delete the cache file to force a rebuild:

   ```bash
   sudo rm /var/meddra/meddra_cache.json
   ```

3. Update the **MedDRA Version Label** in the module's system settings to the new version number (e.g. `28.0`)

4. The cache is rebuilt automatically on the next search request.

If the `.asc` file format changes significantly between versions, update the column indices in `buildCacheFromAsc()` in `src/MeddraHierarchyProviderModule.php` and rebuild the package.

---

## Updating the module itself

1. Pull the latest code from this repository
2. Make any necessary changes in `src/`
3. Bump the version number in `build.sh` (change `VERSION="1.0.0"` or pass it as an argument)
4. Run `bash build.sh <new_version>`
5. Deploy the new ZIP following the same steps as initial installation

REDCap will detect the new version folder and offer an upgrade automatically, or you can delete the old folder and install the new one manually.

---

## How the code is organised

### `src/config.json`

REDCap reads this file to discover the module. Key fields:

- `namespace` — PHP namespace of the main class (`AECRI\MeddraHierarchyProvider`)
- `framework-version` — EM framework version (12)
- `permissions` — which REDCap hooks this module uses
- `no-auth-pages` — pages accessible without a REDCap login (the AJAX search endpoint)
- `system-settings` — the three configurable settings shown in Control Center

### `src/MeddraHierarchyProviderModule.php`

The main class. Implements `\OntologyProvider` so REDCap registers it as an ontology service. Key responsibilities:

| Method | What it does |
|---|---|
| `__construct()` | Registers this instance with `\OntologyManager` |
| `getProviderName()` | Returns the name shown in Online Designer |
| `getServicePrefix()` | Returns `MEDDRA_HIER` (identifies this provider in the data dictionary) |
| `getOnlineDesignerSection()` | Returns the HTML level picker shown in the Online Designer |
| `searchOntology()` | Called by REDCap's built-in autocomplete path |
| `getLabelForValue()` | Resolves a stored code back to its label |
| `handleSearchRequest()` | Called from `search_service.php` for the hierarchy-filtered AJAX path |
| `loadCache()` | Loads JSON cache from disk (or builds it from `.asc` files on first use) |
| `buildCacheFromAsc()` | Parses all eight `.asc` files into the flat JSON structure |
| `getTermsForLevel()` | Returns terms for a level, optionally filtered by parent code |
| `injectHierarchyJS()` | Outputs the `<script>` block that links child fields to their parents |

### `src/search_service.php`

A two-line no-auth page. The REDCap EM framework provides the `$module` variable automatically when this file is loaded. It calls `$module->handleSearchRequest()` and returns JSON.

> **Note:** Do not use `$this` in this file — `$this` is undefined outside a class method in PHP. The EM framework injects `$module` into scope instead.

---

## MedDRA `.asc` file format

The module uses dollar-sign (`$`) delimited flat files. After stripping trailing `$` characters and splitting, the column indices (0-based) are:

| File | [0] | [1] | [2] | Critical column |
|---|---|---|---|---|
| `soc.asc` | soc_code | soc_name | soc_abbrev | — |
| `hlgt.asc` | hlgt_code | hlgt_name | — | — |
| `hlt.asc` | hlt_code | hlt_name | — | — |
| `pt.asc` | pt_code | pt_name | *(null)* | — |
| `llt.asc` | llt_code | llt_name | pt_code | **[8] llt_currency** — `Y`=current, `N`=non-current/retired |
| `soc_hlgt.asc` | soc_code | hlgt_code | — | mapping |
| `hlgt_hlt.asc` | hlgt_code | hlt_code | — | mapping |
| `hlt_pt.asc` | hlt_code | pt_code | — | mapping |

> The `pt_llt` (PT → LLT) reverse mapping is derived by the module from `llt.asc` column [2] during cache build — there is no separate `pt_llt.asc` file in the MedDRA distribution.

---

## Known limitations

| Limitation | Detail |
|---|---|
| Cascade-clear uses hardcoded field names | The JavaScript that clears all child fields at once uses the default names `meddra_soc`, `meddra_hlgt`, `meddra_hlt`, `meddra_pt`, `meddra_llt`. The `@MEDDRA_PARENT` filter still works with any field names, but the bulk-clear shortcut does not. Edit the `clearOrder` array in `injectHierarchyJS()` if your project uses different names. |
| 1 500 ms JS initialisation delay | The script waits 1.5 seconds after page load before intercepting autocomplete instances. On slow servers this may not be long enough. Increase the `1500` constant in `injectHierarchyJS()` if needed. |
| Cache is global | All projects share the same MedDRA data. There is no per-project MedDRA version support. |
| No cache invalidation on `.asc` update | Deleting the cache file manually is required after updating `.asc` files. |

---

## Troubleshooting (IT-level)

| Symptom | Check |
|---|---|
| Module does not appear in Control Center | Verify the folder name matches `<name>_v<N>.<N>` and `config.json` is valid JSON |
| Cache file is not created | Check web server user write permission on the cache directory; check PHP error log |
| `search_service.php` returns `{"error":"Module not initialized"}` | The no-auth page token may be wrong; re-save the system settings to regenerate the URL |
| All LLT results include retired terms | Cache was built by a pre-fix version of the module; delete cache file and let it rebuild |
| Very slow first search after cache delete | Normal — the cache build reads ~1 million rows from `.asc` files. Subsequent searches are fast. Pre-warm by running a search immediately after deployment. |
| PHP fatal error in log mentioning `\OntologyProvider` | REDCap version is below 8.8.1 (OntologyProvider interface not available) |

---

## License

This module code is provided as-is. **MedDRA terminology is a licensed product of ICH/MSSO. You must hold a valid MedDRA licence to possess and use the `.asc` files.** Do not redistribute MedDRA data files.

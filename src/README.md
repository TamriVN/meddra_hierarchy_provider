# MedDRA Hierarchy Provider — REDCap External Module

A REDCap External Module that provides **hierarchical MedDRA coding** with cascading filtered autocomplete. When you select a SOC, the HLGT field only shows terms under that SOC. When you select an HLGT, the HLT field only shows terms under that HLGT, and so on — giving clinicians five clean, fast-to-navigate fields instead of one overwhelming 70 000-term list.

---

## Features

| Feature | Detail |
|---|---|
| **5 cascading autocomplete fields** | SOC → HLGT → HLT → PT → LLT |
| **True hierarchy filtering** | Each level is filtered to only children of the parent selection |
| **Uses your own MedDRA `.asc` files** | No external API or internet connection required |
| **JSON caching** | `.asc` files are parsed once and cached as a JSON file for fast lookups on every subsequent request |
| **Cascading clear** | Changing a parent field automatically clears all child fields below it |
| **Works on data entry forms and surveys** | |
| **Standard Ontology Provider** | Appears in the Online Designer's ontology dropdown alongside BioPortal, LOINC, etc. |
| **Only current LLTs** | Non-current (retired) Lowest Level Terms are excluded from search results |

---

## Requirements

- REDCap **≥ 10.0.0** with External Module Framework **v12**
- PHP **≥ 7.4**
- MedDRA `.asc` distribution files *(licensed from MSSO/ICH — you must have a valid MedDRA subscription)*
- Web server write permission for the cache file location

---

## Installation

### Step 1 — Get the module package

Run the build script from the repository root to produce the deployable ZIP:

```bash
bash build.sh          # creates dist/meddra_hierarchy_provider_v1.0.0.zip
bash build.sh 2.0.0    # create a specific version number
```

### Step 2 — Deploy to REDCap

**Option A — Upload via Control Center (recommended):**

1. Go to **Control Center → External Modules → Manage**
2. Click **Upload a package** and select `meddra_hierarchy_provider_v1.0.0.zip`

**Option B — Manual copy:**

Extract the ZIP and copy the folder to the REDCap modules directory on the server:

```
/var/www/redcap/modules/meddra_hierarchy_provider_v1.0.0/
```

The folder must be named exactly `<module_name>_v<version>` (REDCap derives the version from the folder name).

### Step 3 — Enable in Control Center

1. Go to **Control Center → External Modules → Manage**
2. Find **MedDRA Hierarchy Provider** and click **Enable**

### Step 4 — Configure System Settings

Click the **Configure** button next to the module and set:

| Setting | Description | Example |
|---|---|---|
| **MedDRA Data Directory Path** | Absolute path to the folder containing your MedDRA `.asc` files on the server | `/var/meddra/MedAscii` |
| **Cache File Path** | Where the module writes its JSON cache. The web server user (`www-data`) must have **write** permission here. | `/var/meddra/meddra_cache.json` |
| **MedDRA Version Label** *(optional)* | Shown in the Online Designer so users know which version is loaded | `27.1` |

**Required `.asc` files** (must all be present in the data directory):

```
soc.asc        hlgt.asc       hlt.asc
soc_hlgt.asc   hlgt_hlt.asc   hlt_pt.asc
pt.asc         llt.asc
```

### Step 5 — Enable for your project

1. Open your project → **External Modules → Manage**
2. Click **Enable** next to MedDRA Hierarchy Provider

---

## Setting up fields in your instrument

### Option A — Using the Online Designer

1. Add a **Text Box** field
2. Under **Validation**, choose **MedDRA Hierarchy** from the ontology dropdown
3. Select the level: SOC, HLGT, HLT, PT, or LLT
4. For every child field (HLGT and below), add `@MEDDRA_PARENT=<parent_field_name>` in the **Field Annotation** box

### Option B — Using the Data Dictionary CSV

Add these rows to your CSV upload. The `Choices` column and `Field Annotation` column are the critical ones:

| Variable / Field Name | Field Type | Choices, Calculations, OR Slider Labels | Field Annotation |
|---|---|---|---|
| `meddra_soc` | text | `MEDDRA_HIER:MEDDRA_SOC` | *(leave blank)* |
| `meddra_hlgt` | text | `MEDDRA_HIER:MEDDRA_HLGT` | `@MEDDRA_PARENT=meddra_soc` |
| `meddra_hlt` | text | `MEDDRA_HIER:MEDDRA_HLT` | `@MEDDRA_PARENT=meddra_hlgt` |
| `meddra_pt` | text | `MEDDRA_HIER:MEDDRA_PT` | `@MEDDRA_PARENT=meddra_hlt` |
| `meddra_llt` | text | `MEDDRA_HIER:MEDDRA_LLT` | `@MEDDRA_PARENT=meddra_pt` |

> **Important:** The cascade-clearing JavaScript uses these exact field variable names. If you choose different names, filtering still works via `@MEDDRA_PARENT` annotations, but the "clear all descendants" shortcut will not fire for the non-default names. See the `clearOrder` array in `MeddraHierarchyProviderModule.php` if you need to customise this.

### What gets stored in REDCap

- The raw **MedDRA numeric code** is stored as the field value (e.g. `10005329`)
- The **human-readable label** is cached by REDCap in `redcap_web_service_cache` and displayed automatically when viewing records
- Piping `[meddra_soc]` in emails, alerts, or instruments will resolve to the label

---

## How it works (technical overview)

```
 User types in an autocomplete field
          │
          ▼
 Injected JS checks @MEDDRA_PARENT annotation
          │
          ├─ Parent has a value? ──► AJAX → search_service.php
          │                          ?category=MEDDRA_HLGT
          │                          &search=cardiac
          │                          &parent_code=10007541      ← SOC code
          │                                │
          │                                ▼
          │                         MeddraHierarchyProviderModule
          │                         ::handleSearchRequest()
          │                                │
          │                         Loads JSON cache
          │                         Filters: soc_hlgt[10007541] → hlgt_codes[]
          │                         Searches only those ~15 HLGTs
          │                                │
          │                         Returns: [{code, label}, ...]
          │
          └─ No parent yet? ──────► Original REDCap autocomplete (all terms)
```

1. On first use, the module parses your `.asc` files and writes `meddra_cache.json`
2. On every data-entry / survey page load, a small `<script>` block is injected that intercepts jQuery UI Autocomplete on child fields
3. When the user types, the script reads the parent field's current value and passes it as `?parent_code=` to the search endpoint
4. The endpoint returns only children of the selected parent
5. When a parent field changes, child fields are cleared automatically

---

## Rebuilding the cache

If you update your MedDRA `.asc` files (e.g. annual version upgrade), delete the cache file:

```bash
rm /var/meddra/meddra_cache.json
```

The module rebuilds it automatically on the next search request.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| "MedDRA Hierarchy" does not appear in Online Designer | Module not enabled at system level | Control Center → External Modules → Enable |
| No terms appear in the SOC field | Wrong data path, or `.asc` files missing | Check **MedDRA Data Directory Path** in system settings; verify files exist on the server |
| Search returns no results (all levels) | Cache file is corrupt or empty | Delete the cache file and let the module rebuild it |
| Child field shows all terms instead of filtered ones | `@MEDDRA_PARENT` annotation missing or wrong field name | Open the field in the Online Designer and check Field Annotation |
| Autocomplete not intercepted | JS error before `setTimeout` completes | Open browser Dev Tools → Console; look for errors; increase the `1500` ms delay in the module if needed |
| Cache file not created | Web server lacks write permission | `chown www-data /var/meddra && chmod 755 /var/meddra` |
| LLT returns retired/non-current terms | Stale cache built with older version of the module (pre-bugfix) | Delete the cache file to force a rebuild |

---

## File structure

```
meddra_hierarchy_provider_v1.0.0/    ← deploy this folder to REDCap
├── config.json                      REDCap EM metadata, settings definitions
├── MeddraHierarchyProviderModule.php  Main module class (OntologyProvider)
├── search_service.php               AJAX no-auth search endpoint
└── README.md                        This file
```

---

## MedDRA `.asc` file format reference

The module reads eight files. Their column layout (after `$`-delimiter splitting):

| File | [0] | [1] | [2] | Other relevant |
|---|---|---|---|---|
| `soc.asc` | soc_code | soc_name | soc_abbrev | |
| `hlgt.asc` | hlgt_code | hlgt_name | | |
| `hlt.asc` | hlt_code | hlt_name | | |
| `pt.asc` | pt_code | pt_name | *(null)* | [3] pt_soc_code |
| `llt.asc` | llt_code | llt_name | pt_code | **[8] llt_currency** (Y=current, N=non-current) |
| `soc_hlgt.asc` | soc_code | hlgt_code | | *(mapping file)* |
| `hlgt_hlt.asc` | hlgt_code | hlt_code | | *(mapping file)* |
| `hlt_pt.asc` | hlt_code | pt_code | | *(mapping file)* |

---

## License

This module is provided as-is under no specific license. **MedDRA terminology is licensed by ICH/MSSO — you must hold a valid MedDRA subscription to use the `.asc` files.**

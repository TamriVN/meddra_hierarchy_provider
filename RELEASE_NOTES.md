# MedDRA Hierarchy Provider v1.0.0

A REDCap External Module that provides **hierarchical MedDRA coding** with cascading filtered autocomplete on data entry forms and surveys.

## Highlights

- **5 cascading autocomplete fields** — SOC → HLGT → HLT → PT → LLT
- **True hierarchy filtering** — each level filters based on the parent selection (no more searching across 70,000+ terms at once)
- **No external API** — uses your own MedDRA `.asc` files stored locally
- **JSON caching** — builds a cache on first use for fast lookups
- **Standard Ontology Provider** — appears in the Online Designer like BioPortal

## Requirements

| Requirement | Version |
|-------------|---------|
| REDCap | ≥ 10.0.0 (External Module Framework v12) |
| PHP | ≥ 7.4 |
| MedDRA `.asc` files | Licensed from MSSO/ICH |

## Installation

1. **Download** the `meddra_hierarchy_provider_v1.0.0.zip` from the [Assets](#) section below
2. **Upload** via REDCap Control Center → External Modules → Upload
3. **Enable** the module in Control Center → External Modules → Manage
4. **Configure** system settings:
   - MedDRA Data Directory Path (folder containing `.asc` files)
   - Cache File Path (web server must have write permission)
   - MedDRA Version Label (e.g., `27.1`)
5. **Enable** for your project in Project Settings → External Modules

## MedDRA Files

You must have MedDRA `.asc` files on your REDCap server. These are **not** included in this release — obtain them through your organisation's MedDRA subscription (MSSO/ICH).

Required files: `soc.asc`, `hlgt.asc`, `hlt.asc`, `soc_hlgt.asc`, `hlgt_hlt.asc`  
Optional (for PT/LLT): `pt.asc`, `hlt_pt.asc`, `llt.asc`

## Documentation

- [README](README.md) — Full deployment guide for IT
- [src/README.md](src/README.md) — End-user guide (English)
- [src/README_vi.md](src/README_vi.md) — Hướng dẫn người dùng (Tiếng Việt)

## License

This module is provided as-is. MedDRA terminology is licensed by ICH/MSSO — you must have a valid MedDRA subscription to use the `.asc` files.

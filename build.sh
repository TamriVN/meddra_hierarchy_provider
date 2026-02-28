#!/usr/bin/env bash
# =============================================================================
# build.sh — Package the MedDRA Hierarchy Provider External Module for REDCap
#
# Usage:
#   ./build.sh              # builds dist/meddra_hierarchy_provider_v1.0.0.zip
#   ./build.sh 1.2.0        # builds a specific version
#
# Output:
#   dist/meddra_hierarchy_provider_v<VERSION>/    (deployable folder)
#   dist/meddra_hierarchy_provider_v<VERSION>.zip (upload to REDCap)
#
# REDCap install path (on server):
#   /var/www/redcap/modules/meddra_hierarchy_provider_v<VERSION>/
# =============================================================================

set -euo pipefail

VERSION="${1:-1.0.0}"
MODULE_NAME="meddra_hierarchy_provider"
FOLDER_NAME="${MODULE_NAME}_v${VERSION}"
SRC_DIR="$(cd "$(dirname "$0")/src" && pwd)"
DIST_DIR="$(cd "$(dirname "$0")" && pwd)/dist"
OUT_DIR="${DIST_DIR}/${FOLDER_NAME}"
ZIP_FILE="${DIST_DIR}/${FOLDER_NAME}.zip"

echo "Building MedDRA Hierarchy Provider v${VERSION}"
echo "  Source : ${SRC_DIR}"
echo "  Output : ${OUT_DIR}"

# ── Clean & create output directory ──────────────────────────────────────────
rm -rf "${OUT_DIR}"
mkdir -p "${OUT_DIR}"

# ── Copy module files ─────────────────────────────────────────────────────────
cp "${SRC_DIR}/config.json"                        "${OUT_DIR}/config.json"
cp "${SRC_DIR}/MeddraHierarchyProviderModule.php"  "${OUT_DIR}/MeddraHierarchyProviderModule.php"
cp "${SRC_DIR}/search_service.php"                 "${OUT_DIR}/search_service.php"
cp "${SRC_DIR}/README.md"                          "${OUT_DIR}/README.md"
cp "${SRC_DIR}/README_vi.md"                       "${OUT_DIR}/README_vi.md"

echo "Files copied to ${OUT_DIR}:"
ls -1 "${OUT_DIR}"

# ── Create ZIP ────────────────────────────────────────────────────────────────
rm -f "${ZIP_FILE}"

# Use zip if available, otherwise fall back to PowerShell (Windows/MSYS)
if command -v zip &>/dev/null; then
    (cd "${DIST_DIR}" && zip -r "${ZIP_FILE}" "${FOLDER_NAME}/")
elif command -v powershell &>/dev/null; then
    # Convert MSYS/Cygwin paths to Windows paths for PowerShell
    if command -v cygpath &>/dev/null; then
        WIN_OUT_DIR=$(cygpath -w "${OUT_DIR}")
        WIN_ZIP_FILE=$(cygpath -w "${ZIP_FILE}")
    else
        # Fallback: replace /d/ with D:\ style
        WIN_OUT_DIR=$(echo "${OUT_DIR}" | sed 's|^/\([a-zA-Z]\)/|\1:\\|;s|/|\\|g')
        WIN_ZIP_FILE=$(echo "${ZIP_FILE}" | sed 's|^/\([a-zA-Z]\)/|\1:\\|;s|/|\\|g')
    fi
    powershell -NoProfile -Command \
        "Compress-Archive -Path '${WIN_OUT_DIR}' -DestinationPath '${WIN_ZIP_FILE}' -Force"
else
    echo "WARNING: Neither 'zip' nor 'powershell' found — skipping ZIP creation."
    echo "         Manually zip the folder: ${OUT_DIR}"
    exit 0
fi

echo ""
echo "Done. Package ready:"
echo "  ${ZIP_FILE}"
echo ""
echo "REDCap deployment:"
echo "  1. Upload ${FOLDER_NAME}.zip via Control Center → External Modules → Upload"
echo "     OR manually extract to your REDCap modules directory:"
echo "     /var/www/redcap/modules/${FOLDER_NAME}/"
echo "  2. Enable in Control Center → External Modules → Manage"
echo "  3. Configure system settings (MedDRA path, cache file, version label)"

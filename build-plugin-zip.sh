#!/usr/bin/env bash
#
# Builds a WordPress plugin ZIP from wp-plugin/ and writes it to the Desktop.
#
# Usage:
#   ./build-plugin-zip.sh
#
# Result: ~/Desktop/training-translation-tracker.zip
# Structure inside the ZIP:
#   training-translation-tracker/         <- top-level folder (plugin slug)
#     training-translation-tracker.php
#     uninstall.php
#     includes/
#     assets/
#     readme.txt
#     LICENSE
#

set -euo pipefail

# ----------------------------------------------------------------------------
# Paths
# ----------------------------------------------------------------------------
WORKSPACE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_DIR="${WORKSPACE}/wp-plugin"
PLUGIN_SLUG="training-translation-tracker"
TARGET_ZIP="${HOME}/Desktop/${PLUGIN_SLUG}.zip"

# ----------------------------------------------------------------------------
# Sanity checks
# ----------------------------------------------------------------------------
if [[ ! -d "${SOURCE_DIR}" ]]; then
	echo "ERROR: wp-plugin/ folder is missing: ${SOURCE_DIR}" >&2
	exit 1
fi
if [[ ! -f "${SOURCE_DIR}/training-translation-tracker.php" ]]; then
	echo "ERROR: main plugin file is missing in ${SOURCE_DIR}" >&2
	exit 1
fi

# ----------------------------------------------------------------------------
# Build
# ----------------------------------------------------------------------------
echo "Building plugin ZIP from ${SOURCE_DIR}"

TMPDIR="$(mktemp -d)"
trap 'rm -rf "${TMPDIR}"' EXIT

# Copy into the temp folder. The top-level folder name IS the plugin slug.
rsync -a \
	--exclude='.git/' \
	--exclude='.gitignore' \
	--exclude='.DS_Store' \
	--exclude='.vscode/' \
	--exclude='.idea/' \
	--exclude='README.md' \
	--exclude='docs/' \
	--exclude='*.zip' \
	--exclude='build/' \
	--exclude='dist/' \
	"${SOURCE_DIR}/" "${TMPDIR}/${PLUGIN_SLUG}/"

# Remove the previous ZIP if present, otherwise zip(1) appends silently.
rm -f "${TARGET_ZIP}"

# Build the ZIP.
( cd "${TMPDIR}" && zip -r -q "${TARGET_ZIP}" "${PLUGIN_SLUG}" )

# Short table of contents as a sanity check.
echo
echo "ZIP built: ${TARGET_ZIP}"
echo "Contents (top level + nested):"
unzip -l "${TARGET_ZIP}" | awk 'NR>3 && NF>=4 {print "  " $4}' | head -30

SIZE_KB=$(du -k "${TARGET_ZIP}" | cut -f1)
echo
echo "Size: ${SIZE_KB} KB"
echo "Now in WP Admin: Plugins -> Upload Plugin -> choose this file -> Activate."

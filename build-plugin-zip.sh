#!/usr/bin/env bash
#
# Baut ein WordPress-Plugin-ZIP aus wp-plugin/ und legt es auf den Desktop.
#
# Verwendung:
#   ./build-plugin-zip.sh
#
# Ergebnis: ~/Desktop/training-translation-tracker.zip
# Struktur im ZIP:
#   training-translation-tracker/         ← Top-Level-Ordner (Plugin-Slug)
#     training-translation-tracker.php
#     uninstall.php
#     includes/
#     assets/
#     readme.txt
#     LICENSE
#

set -euo pipefail

# ----------------------------------------------------------------------------
# Pfade
# ----------------------------------------------------------------------------
WORKSPACE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_DIR="${WORKSPACE}/wp-plugin"
PLUGIN_SLUG="training-translation-tracker"
TARGET_ZIP="${HOME}/Desktop/${PLUGIN_SLUG}.zip"

# ----------------------------------------------------------------------------
# Sanity-Checks
# ----------------------------------------------------------------------------
if [[ ! -d "${SOURCE_DIR}" ]]; then
	echo "ERROR: wp-plugin/-Ordner fehlt: ${SOURCE_DIR}" >&2
	exit 1
fi
if [[ ! -f "${SOURCE_DIR}/training-translation-tracker.php" ]]; then
	echo "ERROR: Plugin-Hauptdatei fehlt in ${SOURCE_DIR}" >&2
	exit 1
fi

# ----------------------------------------------------------------------------
# Bauen
# ----------------------------------------------------------------------------
echo "Building plugin ZIP from ${SOURCE_DIR}"

TMPDIR="$(mktemp -d)"
trap 'rm -rf "${TMPDIR}"' EXIT

# In den temp-Ordner kopieren — der Top-Level-Ordnername IST der Plugin-Slug.
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

# Altes ZIP, falls vorhanden, entfernen — sonst zip(1) appendet stillschweigend.
rm -f "${TARGET_ZIP}"

# ZIP bauen
( cd "${TMPDIR}" && zip -r -q "${TARGET_ZIP}" "${PLUGIN_SLUG}" )

# Kurzes Inhaltsverzeichnis als Sanity-Check
echo
echo "ZIP gebaut: ${TARGET_ZIP}"
echo "Inhalt (top-level + nested):"
unzip -l "${TARGET_ZIP}" | awk 'NR>3 && NF>=4 {print "  " $4}' | head -30

SIZE_KB=$(du -k "${TARGET_ZIP}" | cut -f1)
echo
echo "Größe: ${SIZE_KB} KB"
echo "Jetzt im WP-Admin: Plugins → Plugin hochladen → diese Datei wählen → Aktivieren."

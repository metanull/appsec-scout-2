#!/bin/sh
# Exports the Linux host's locally trusted CA certificates into .docker/certs/,
# in the exact layout scripts/lib/Certificates.psm1's Export-HostCertificates
# produces for Windows hosts: one NNNN-<label>-<THUMBPRINT>.crt file per
# certificate (4-digit index, sanitised subject-CN label, uppercase SHA-1
# thumbprint) plus a combined host-ca-bundle.crt. Containers install every
# *.crt under .docker/certs/ at start (docker/lib/install-ca-certs.sh);
# dependencytrack-cacerts-init additionally requires the NNNN-*.crt naming to
# pick a file up for Dependency-Track's own truststore.
#
# Source directories are the two places a Linux administrator adds locally
# trusted CAs (not the distribution's own Mozilla bundle):
#   - /usr/local/share/ca-certificates  (Debian/Ubuntu, update-ca-certificates)
#   - /etc/pki/ca-trust/source/anchors  (RHEL/Fedora/Rocky/Alma, update-ca-trust)
# Regular .crt/.pem/.cer files directly inside those directories (not
# recursively) are considered; each is parsed with `openssl x509` (PEM, then
# DER as a fallback) and files openssl cannot parse are skipped with a
# warning. Only the first certificate of a multi-certificate PEM file is
# exported, since `openssl x509` itself only ever reads the first one.
#
# Usage: export-host-certificates.sh [OUTPUT_DIR]
#   OUTPUT_DIR defaults to <script dir>/../.docker/certs
#
# Safe to re-run: existing *.crt files in the output directory are replaced.
# Requires openssl. POSIX sh only (no bashisms) — runs under dash/ash/bash.
set -eu

SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
OUTPUT_DIR="${1:-"$SCRIPT_DIR/../.docker/certs"}"

if ! command -v openssl >/dev/null 2>&1; then
    echo "export-host-certificates.sh: openssl is required but was not found on PATH." >&2
    exit 1
fi

SOURCE_DIRS="/usr/local/share/ca-certificates /etc/pki/ca-trust/source/anchors"

WORK_DIR=$(mktemp -d)
LIST_FILE=$(mktemp)
trap 'rm -rf "$WORK_DIR"; rm -f "$LIST_FILE"' EXIT

# Collect distinct thumbprints across every candidate file, writing each
# accepted certificate's PEM body and label to "$WORK_DIR/<thumbprint>" so a
# certificate found in both source directories is only exported once.
found_any=0
for dir in $SOURCE_DIRS; do
    [ -d "$dir" ] || continue
    for f in "$dir"/*.crt "$dir"/*.pem "$dir"/*.cer; do
        [ -f "$f" ] || continue

        pem=$(openssl x509 -in "$f" -inform PEM -outform PEM 2>/dev/null) || \
            pem=$(openssl x509 -in "$f" -inform DER -outform PEM 2>/dev/null) || pem=""
        if [ -z "$pem" ]; then
            echo "export-host-certificates.sh: skipping unparsable certificate file: $f" >&2
            continue
        fi

        thumbprint=$(printf '%s\n' "$pem" | openssl x509 -noout -fingerprint -sha1 2>/dev/null | \
            sed 's/^.*=//' | tr -d ':' | tr '[:lower:]' '[:upper:]')
        [ -n "$thumbprint" ] || continue

        thumb_file="$WORK_DIR/$thumbprint"
        [ -f "$thumb_file" ] && continue

        label=$(printf '%s\n' "$pem" | openssl x509 -noout -subject -nameopt sep_multiline,utf8 2>/dev/null | \
            grep '^ *CN=' | head -n 1 | cut -d= -f2-)
        [ -n "$label" ] || label="certificate"
        safe_label=$(printf '%s' "$label" | tr -cs 'A-Za-z0-9._-' '-' | sed 's/^-*//; s/-*$//')
        [ -n "$safe_label" ] || safe_label="certificate"

        printf '%s\n' "$pem" > "$thumb_file"
        echo "$safe_label" > "$thumb_file.label"
        found_any=1
    done
done

if [ "$found_any" -eq 0 ]; then
    echo "No trusted host CA certificates found"
    exit 0
fi

mkdir -p "$OUTPUT_DIR"
find "$OUTPUT_DIR" -maxdepth 1 -type f -name '*.crt' -delete

bundle_path="$OUTPUT_DIR/host-ca-bundle.crt"
: > "$bundle_path"

find "$WORK_DIR" -maxdepth 1 -type f -name '*.label' -prune -o -type f -print | sort > "$LIST_FILE"

index=0
count=0
while IFS= read -r thumb_path; do
    thumbprint=$(basename "$thumb_path")
    index=$((index + 1))
    safe_label=$(cat "$WORK_DIR/$thumbprint.label")
    file_name=$(printf '%04d-%s-%s.crt' "$index" "$safe_label" "$thumbprint")
    cp "$WORK_DIR/$thumbprint" "$OUTPUT_DIR/$file_name"
    cat "$WORK_DIR/$thumbprint" >> "$bundle_path"
    count=$((count + 1))
done < "$LIST_FILE"

echo "Exported $count trusted certificates to $OUTPUT_DIR"

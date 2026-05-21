#!/usr/bin/env sh

set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
OUTPUT_DIR=${1:-"$REPO_ROOT/.docker/certs"}
WORK_DIR=$(mktemp -d)
CERT_INDEX=0

cleanup() {
    rm -rf "$WORK_DIR"
}

trap cleanup EXIT INT TERM

mkdir -p "$OUTPUT_DIR"
find "$OUTPUT_DIR" -maxdepth 1 -type f -name '*.crt' -delete

split_pem_bundle() {
    bundle_path=$1

    awk -v output_dir="$OUTPUT_DIR" '
        /-----BEGIN CERTIFICATE-----/ {
            cert_index += 1
            file_path = sprintf("%s/host-ca-%04d.crt", output_dir, cert_index)
        }
        file_path != "" {
            print > file_path
        }
        /-----END CERTIFICATE-----/ {
            close(file_path)
            file_path = ""
        }
    ' "$bundle_path"
}

copy_directory_certs() {
    source_dir=$1
    if [ ! -d "$source_dir" ]; then
        return
    fi

    find "$source_dir" -maxdepth 1 -type f \( -name '*.crt' -o -name '*.pem' \) > "$WORK_DIR/copy-list.txt"

    while IFS= read -r cert_path; do
        CERT_INDEX=$((CERT_INDEX + 1))
        cp "$cert_path" "$OUTPUT_DIR/local-anchor-$CERT_INDEX.crt"
    done < "$WORK_DIR/copy-list.txt"
}

case "$(uname -s)" in
    Darwin)
        security find-certificate -a -p \
            /Library/Keychains/System.keychain \
            /System/Library/Keychains/SystemRootCertificates.keychain \
            > "$WORK_DIR/host-ca-bundle.pem"
        split_pem_bundle "$WORK_DIR/host-ca-bundle.pem"
        ;;
    Linux)
        if [ -f /etc/ssl/certs/ca-certificates.crt ]; then
            cp /etc/ssl/certs/ca-certificates.crt "$WORK_DIR/host-ca-bundle.pem"
        elif [ -f /etc/pki/tls/certs/ca-bundle.crt ]; then
            cp /etc/pki/tls/certs/ca-bundle.crt "$WORK_DIR/host-ca-bundle.pem"
        elif [ -f /etc/ssl/cert.pem ]; then
            cp /etc/ssl/cert.pem "$WORK_DIR/host-ca-bundle.pem"
        else
            echo 'Could not find a system CA bundle on this host.' >&2
            exit 1
        fi

        split_pem_bundle "$WORK_DIR/host-ca-bundle.pem"
        copy_directory_certs /usr/local/share/ca-certificates
        copy_directory_certs /etc/pki/ca-trust/source/anchors
        ;;
    *)
        echo "Unsupported host OS: $(uname -s)" >&2
        exit 1
        ;;
esac

cat "$OUTPUT_DIR"/*.crt > "$OUTPUT_DIR/host-ca-bundle.crt"
count=$(find "$OUTPUT_DIR" -maxdepth 1 -type f -name '*.crt' ! -name 'host-ca-bundle.crt' | wc -l | tr -d ' ')

echo "Exported $count trusted certificates to $OUTPUT_DIR"
echo "Bundle written to $OUTPUT_DIR/host-ca-bundle.crt"
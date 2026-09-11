#!/usr/bin/env sh
set -eu

dist_directory="${1:-dist}"
release_configuration="${2:-.goreleaser.yaml}"
allow_unsigned_snapshot="${ALLOW_UNSIGNED_SNAPSHOT:-0}"

[ -d "$dist_directory" ] || {
    echo "release output directory does not exist: $dist_directory" >&2
    exit 1
}

find "$dist_directory" -type f -name 'checksums.txt' | grep -q . || {
    echo "checksums.txt is missing" >&2
    exit 1
}
checksums_file="$(find "$dist_directory" -type f -name 'checksums.txt' | head -n 1)"
(
    cd "$(dirname "$checksums_file")"
    sha256sum --check "$(basename "$checksums_file")"
)
if [ "$allow_unsigned_snapshot" = "1" ]; then
    echo "allowing unsigned local snapshot verification"
else
    signature_bundle="$(find "$dist_directory" -type f -name '*.sigstore.json' | head -n 1)"
    [ -n "$signature_bundle" ] || {
        echo "keyless checksum signature bundle is missing" >&2
        exit 1
    }
    [ -n "${COSIGN_CERTIFICATE_IDENTITY:-}" ] || {
        echo "COSIGN_CERTIFICATE_IDENTITY is required for signed release verification" >&2
        exit 1
    }
    cosign verify-blob \
        --certificate-identity "$COSIGN_CERTIFICATE_IDENTITY" \
        --certificate-oidc-issuer 'https://token.actions.githubusercontent.com' \
        --bundle "$signature_bundle" \
        "$checksums_file"
fi

for operating_system in darwin linux windows; do
    for architecture in amd64 arm64; do
        archive="$(find "$dist_directory" -type f \( -name "*_${operating_system}_${architecture}.tar.gz" -o -name "*_${operating_system}_${architecture}.zip" \) | head -n 1)"
        [ -n "$archive" ] || {
            echo "missing ${operating_system} ${architecture} archive" >&2
            exit 1
        }

        case "$archive" in
            *.tar.gz) contents="$(tar -tzf "$archive")" ;;
            *.zip) contents="$(unzip -Z1 "$archive")" ;;
            *) echo "unsupported archive format: $archive" >&2; exit 1 ;;
        esac
        unexpected="$(printf '%s\n' "$contents" | awk -F/ '{name=$NF; if (name != "" && name != "crucible" && name != "crucible.exe" && name != "LICENSE" && name != "README.md") print}')"
        [ -z "$unexpected" ] || {
            echo "unexpected file in $archive: $unexpected" >&2
            exit 1
        }
    done
done

find "$dist_directory" -type f -name '*.deb' | grep -q . || {
    echo "Debian package is missing" >&2
    exit 1
}
find "$dist_directory" -type f -name '*.rpm' | grep -q . || {
    echo "RPM package is missing" >&2
    exit 1
}
find "$dist_directory" -type f \( -name '*.sbom.*' -o -name '*sbom*' \) | grep -q . || {
    echo "SBOM artifact is missing" >&2
    exit 1
}

grep -Eq '^homebrew_casks:' "$release_configuration" || {
    echo "Homebrew cask configuration is missing" >&2
    exit 1
}
for required_metadata in 'name: crucible' 'owner: adiwidia-dev' 'name: homebrew-tap' 'license: MIT' 'binaries: \[crucible\]'; do
    grep -Eq "$required_metadata" "$release_configuration" || {
        echo "Homebrew cask metadata is incomplete: $required_metadata" >&2
        exit 1
    }
done

echo "native release artifact verification passed"

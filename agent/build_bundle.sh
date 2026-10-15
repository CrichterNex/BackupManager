#!/bin/sh
# Builds the agent bundle: bms_agent.py plus a portable Python, in one .tar.gz, for a Linux machine that has no Python and cannot install one.
# This is made ON REQUEST. The Python agent (bms_agent.py) stays the normal way to run the agent.
#
# Run it on Linux (or in WSL), not on a Windows file system, so that symbolic links and file modes in the tarball survive:
#   sh agent/build_bundle.sh PYTHON_TARBALL [OUTPUT_DIR]
#
# PYTHON_TARBALL is an "install_only" build from https://github.com/astral-sh/python-build-standalone/releases (the file named
# cpython-3.11.N+DATE-x86_64-unknown-linux-gnu-install_only.tar.gz, or aarch64 for ARM). Those builds need glibc 2.17 or newer, which covers
# Debian 8 and later. Check the download against that release's SHA256SUMS first; set BMS_PYTHON_SHA256 and this script does it for you.
set -eu

src=${1:?usage: build_bundle.sh PYTHON_TARBALL [OUTPUT_DIR]}
out=${2:-dist}
here=$(cd "$(dirname "$0")" && pwd)

[ -f "$src" ] || { echo "not found: $src" >&2; exit 1; }
if [ -n "${BMS_PYTHON_SHA256:-}" ]; then
    have=$(sha256sum "$src" | cut -d' ' -f1)
    [ "$have" = "$BMS_PYTHON_SHA256" ] || { echo "SHA-256 of $src is $have, not the $BMS_PYTHON_SHA256 you gave. Not using it." >&2; exit 1; }
    echo "Python download matches the checksum you gave."
fi
arch=${BMS_BUNDLE_ARCH:-}
if [ -z "$arch" ]; then
    case "$src" in
        *x86_64*) arch=x86_64 ;;
        *aarch64*) arch=aarch64 ;;
        *) echo "cannot tell the CPU type from the file name. Put x86_64 or aarch64 in it, or set BMS_BUNDLE_ARCH." >&2; exit 1 ;;
    esac
fi
case "$arch" in x86_64|aarch64) ;; *) echo "BMS_BUNDLE_ARCH must be x86_64 or aarch64" >&2; exit 1 ;; esac
version=$(sed -n 's/^VERSION = "\(.*\)"/\1/p' "$here/bms_agent.py" | tr -d '\r')
[ -n "$version" ] || { echo "cannot read the agent VERSION" >&2; exit 1; }

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
dir="$tmp/bms-agent-bundle"
mkdir "$dir"
tar xzf "$src" -C "$dir"
[ -x "$dir/python/bin/python3" ] || { echo "$src does not unpack to python/bin/python3, so it is not an install_only build" >&2; exit 1; }
# Unix line endings, whatever this file was checked out with, so nothing in it trips on a stray carriage return.
tr -d '\r' < "$here/bms_agent.py" > "$dir/bms_agent.py"
tr -d '\r' < "$here/bundle/bms-agent.sh" > "$dir/bms-agent.sh"
chmod 755 "$dir/bms-agent.sh"
chmod 644 "$dir/bms_agent.py"

mkdir -p "$out"
file="$out/bms-agent-$version-linux-$arch.tar.gz"
tar czf "$file" --owner=0 --group=0 -C "$tmp" bms-agent-bundle
(cd "$out" && sha256sum "$(basename "$file")" > "$(basename "$file").sha256")
echo "Built $file"
cat "$file.sha256"

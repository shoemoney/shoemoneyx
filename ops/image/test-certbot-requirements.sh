#!/usr/bin/env bash
# Networked check: resolve the hashed wheel-only closure for both Ubuntu 24.04 arches,
# then exercise pip's missing-hash and tampered-artifact rejection using local wheels.
# Run with CPython 3.12 so dependency markers match the image, not the developer's Python.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PYTHON="${PYTHON:-python3.12}"
"$PYTHON" -c 'import sys; assert sys.version_info[:2] == (3, 12), "Use CPython 3.12 to match Ubuntu 24.04"'
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
"$PYTHON" -m venv "$TMP/venv"
PIP=("$TMP/venv/bin/python" -m pip --isolated --disable-pip-version-check)
REQ="$ROOT/ops/image/files/certbot-requirements.txt"

for arch in x86_64 aarch64; do
  # Ubuntu 24.04 has glibc 2.39 and accepts older manylinux wheels as well.
  platforms=()
  for minor in {39..5}; do
    platforms+=(--platform "manylinux_2_${minor}_${arch}")
  done
  platforms+=(--platform "manylinux2014_${arch}" --platform "manylinux2010_${arch}" --platform "manylinux1_${arch}")
  "${PIP[@]}" download --no-cache-dir --require-hashes --only-binary=:all: \
    --index-url https://pypi.org/simple --python-version 3.12 --implementation cp \
    --abi cp312 --abi abi3 --abi none "${platforms[@]}" \
    --dest "$TMP/$arch" -r "$REQ" >"$TMP/$arch.log" 2>&1 || { cat "$TMP/$arch.log"; exit 1; }
  echo "PASS: Python 3.12 Linux $arch hashed dependency closure"
done

"$TMP/venv/bin/python" - "$REQ" "$TMP" <<'PY'
import pathlib
import re
import shutil
import sys

requirements, temporary = map(pathlib.Path, sys.argv[1:])
certifi = re.search(r"^certifi==[^\s]+", requirements.read_text(), re.M).group()
(temporary / "missing-hash.txt").write_text(certifi + "\n")
shutil.copytree(temporary / "x86_64", temporary / "tampered")
wheel = next((temporary / "tampered").glob("certifi-*.whl"))
with wheel.open("ab") as artifact:
    artifact.write(b"\ntampered\n")
PY

if "${PIP[@]}" download --no-cache-dir --no-deps --require-hashes --no-index \
  --find-links "$TMP/x86_64" --dest "$TMP/missing-download" \
  -r "$TMP/missing-hash.txt" >"$TMP/missing.log" 2>&1; then
  echo 'FAIL: pip accepted an unhashed requirement' >&2
  exit 1
fi
grep -q 'Hashes are required' "$TMP/missing.log" || { cat "$TMP/missing.log"; exit 1; }
echo 'PASS: pip rejects a missing hash'

# --no-deps limits this negative check to certifi, a platform-independent wheel.
# Its expected hashes still come directly from the checked-in requirements file.
"$TMP/venv/bin/python" - "$REQ" "$TMP/tampered-requirement.txt" <<'PY'
import pathlib
import re
import sys

source = pathlib.Path(sys.argv[1]).read_text().replace("\\\n", " ")
certifi = next(line for line in source.splitlines() if line.startswith("certifi=="))
pathlib.Path(sys.argv[2]).write_text(certifi + "\n")
PY
if "${PIP[@]}" download --no-cache-dir --no-deps --require-hashes --no-index \
  --find-links "$TMP/tampered" --dest "$TMP/tampered-download" \
  -r "$TMP/tampered-requirement.txt" >"$TMP/tampered.log" 2>&1; then
  echo 'FAIL: pip accepted a tampered artifact' >&2
  exit 1
fi
grep -q 'DO NOT MATCH THE HASHES' "$TMP/tampered.log" || { cat "$TMP/tampered.log"; exit 1; }
echo 'PASS: pip rejects a tampered artifact'

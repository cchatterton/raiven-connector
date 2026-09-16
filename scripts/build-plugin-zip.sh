#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
slug='raiven-connector'
mkdir -p dist
rm -rf "dist/$slug"
rm -f "dist/$slug.zip" "$slug.zip"
cp -R "$slug" "dist/$slug"
find "dist/$slug" -name .DS_Store -delete
(cd dist && zip -qr "$slug.zip" "$slug")
cp "dist/$slug.zip" "$slug.zip"
python3 - <<'PY'
from zipfile import ZipFile
from pathlib import Path
with ZipFile('raiven-connector.zip') as z:
    names=z.namelist()
    assert all(n.startswith('raiven-connector/') for n in names)
    assert not any('/.git/' in n or n.endswith('.zip') for n in names)
    for name in ['raiven-connector.php','LICENSE','readme.txt']:
        assert 'raiven-connector/'+name in names
assert Path('raiven-connector.zip').read_bytes()==Path('dist/raiven-connector.zip').read_bytes()
print('Verified installable raiven-connector.zip')
PY

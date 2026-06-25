#!/usr/bin/env bash
# Собрать архив модуля Bitrix: onecatalog-bitrix-<ver>.zip (распаковать в /bitrix/modules/).
set -euo pipefail
cd "$(dirname "$0")"
ver=$(grep -oE "'VERSION' *=> *'[0-9.]+'" onecatalog.import/install/version.php | head -1 | grep -oE '[0-9.]+')
out="onecatalog-bitrix-${ver:-dev}.zip"
rm -f "$out"
zip -rq "$out" onecatalog.import -x '*.DS_Store'
echo "✔ $out"

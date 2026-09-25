#!/usr/bin/env bash
# 固定验收入口。用法：bash scripts/check.sh [-list] [--only <组名>[,<组名>...]]
set -uo pipefail

cd "$(dirname "$0")/.."

if ! command -v php >/dev/null 2>&1; then
    echo "找不到 php（需要 PHP 8 命令行）" >&2
    exit 2
fi

exec php check/check.php "$@"

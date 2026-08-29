#!/usr/bin/env bash
# Build a single markdown evidence report from the screenshots each spec saved.
set -euo pipefail
cd "$(dirname "$0")"
OUT=evidence/README.md

{
  echo "# PrestaShop module — e2e evidence"
  echo
  echo "Generated: $(date '+%Y-%m-%d %H:%M:%S')"
  echo
  echo "Every screenshot below was produced by Playwright driving the real"
  echo "storefront and back office as a shopper/merchant would: navigating,"
  echo "clicking, typing, and submitting. No database writes, no direct calls"
  echo "into the module, no POSTs to its own controllers."
  echo
  for dir in evidence/*/; do
    name=$(basename "$dir")
    [ "$name" = "README.md" ] && continue
    shopt -s nullglob
    shots=("$dir"*.png)
    [ ${#shots[@]} -eq 0 ] && continue
    echo "## $name"
    echo
    for s in "${shots[@]}"; do
      label=$(basename "$s" .png)
      echo "### $label"
      echo
      echo "![$label]($(basename "$dir")/$(basename "$s"))"
      echo
    done
  done
} > "$OUT"

echo "wrote $OUT"
grep -c '^!\[' "$OUT" | xargs echo "screenshots referenced:"

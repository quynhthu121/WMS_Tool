#!/usr/bin/env sh
# Kiểm tra: không chỗ nào ngoài InventoryService ghi inventory_balances / stock_movements,
# và không còn mã nào dùng products.qty. Thoát mã ≠ 0 nếu vi phạm.
#   sh bin/check_writes.sh
set -u
cd "$(dirname "$0")/.."

fail=0
targets="index.php landing.php about.php register.php google_callback.php google_login.php classes modules views src bin"

hits=$(grep -rniE "(update|insert[[:space:]]+into|delete[[:space:]]+from|replace[[:space:]]+into)[[:space:]]+\`?(inventory_balances|stock_movements)" $targets \
    | grep -v "^src/Service/InventoryService.php:" | grep -v "^bin/check_writes.sh:")
if [ -n "$hits" ]; then
    echo "Ghi trực tiếp inventory_balances/stock_movements ngoài InventoryService:"
    echo "$hits"
    fail=1
fi

hits=$(grep -rniE "products\.qty|\bqty[[:space:]]*=[[:space:]]*qty|SUM\(qty\)[[:space:]]+FROM[[:space:]]+products|SELECT[^;]*\bqty\b[^;]*FROM[[:space:]]+products|INSERT[[:space:]]+INTO[[:space:]]+products[[:space:]]*\([^)]*\bqty\b" $targets \
    | grep -v "^bin/check_writes.sh:")
if [ -n "$hits" ]; then
    echo "Còn mã dùng products.qty:"
    echo "$hits"
    fail=1
fi

[ "$fail" -eq 0 ] && echo "OK: chỉ InventoryService ghi tồn; không còn products.qty."
exit "$fail"

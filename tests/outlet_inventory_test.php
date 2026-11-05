<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/outlet_inventory.php';

$base = default_table_inventory();
$inventory = submitted_outlet_inventory(['inventory_tables' => ['2', '3', '0', '1']], $base);
if (table_capacity($inventory) !== 24 || total_tables($inventory) !== 6) throw new RuntimeException('Incorrect capacity');
if ($inventory[2]['tables'] !== 0 || $inventory[3]['type'] !== 'Party table') throw new RuntimeException('Type indexes shifted');
if (find_exact_table_type_allocation($inventory, '2', 6) !== null) throw new RuntimeException('Disabled table accepted');
if (find_exact_table_type_allocation($inventory, '3', 8) === null) throw new RuntimeException('Party type lost');
foreach ([[], ['0','0','0','0'], ['-1','2','3','4'], ['1.5','2','3','4'], ['abc','2','3','4'], ['101','2','3','4'], [[],2,3,4]] as $counts) {
    try {
        submitted_outlet_inventory(['inventory_tables' => $counts], $base);
    } catch (InvalidArgumentException $e) {
        continue;
    }
    throw new RuntimeException('Invalid inventory accepted');
}
echo "Outlet inventory validation and stable table type tests passed.\n";

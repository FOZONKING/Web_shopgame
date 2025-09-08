<?php
require __DIR__ . '/db.php';
echo "DB OK\n";
$r = $pdo->query("SHOW COLUMNS FROM payments")->fetchAll();
foreach ($r as $c) echo $c['Field'] . "\n";

<?php
// temporary dev helper: php app/Support/Testing/_grep.php <file> <needle> [context-lines]
$file = $argv[1] ?? '';
$needle = $argv[2] ?? '';
$ctx = (int) ($argv[3] ?? 20);
$lines = file($file);
foreach ($lines as $i => $line) {
    if (stripos($line, $needle) !== false) {
        echo "== line ".($i + 1)." ==\n";
        for ($j = $i; $j < min(count($lines), $i + $ctx); $j++) {
            echo $lines[$j];
        }
        echo "\n";
    }
}

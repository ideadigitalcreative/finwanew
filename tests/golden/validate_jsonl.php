<?php
// Utilitas sekali pakai untuk memvalidasi tests/golden/messages.jsonl
$f = __DIR__.'/messages.jsonl';
$lines = file($f, FILE_IGNORE_NEW_LINES);
$n = 0;
foreach ($lines as $i => $l) {
    if (trim($l) === '') {
        continue;
    }
    $j = json_decode($l, true);
    if ($j === null) {
        echo 'INVALID line '.($i + 1).': '.json_last_error_msg().PHP_EOL;
        exit(1);
    }
    $n++;
}
echo 'OK '.$n.' cases'.PHP_EOL;

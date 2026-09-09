<?php
/*
 * Общие хелперы для тестов.
 * Подключается один раз в раннере.
 */

$tests = 0;
$failures = 0;

function assertEq($expected, $actual, $desc) {
    global $tests, $failures;
    $tests++;
    if (is_array($expected)) sort($expected);
    if (is_array($actual)) sort($actual);

    if ($expected === $actual) {
        echo "  PASS: $desc\n";
    } else {
        $failures++;
        echo "  FAIL: $desc\n";
        echo "    Ожидалось: " . json_encode($expected) . "\n";
        echo "    Получено:  " . json_encode($actual) . "\n";
    }
}

function makeStorage() {
    $st = new COrgStructureStorage();
    $st->data = [
        'u1' => ['Pernr' => '00000001', 'Ename' => 'Иванов Иван Иванович', 'Orgeh' => '10000031', 'Email' => 'ivanov@test.ru'],
        'u2' => ['Pernr' => '00000002', 'Ename' => 'Петров Петр Петрович', 'Orgeh' => '10000031', 'Email' => 'petrov@test.ru'],
        'u3' => ['Pernr' => '00000003', 'Ename' => 'сидоров Сидор Сидорович', 'Orgeh' => '10000032', 'Email' => 'sidorov@test.ru'],
        'u4' => ['Pernr' => '00000004', 'Ename' => 'Ёлкин Ёлкий Ёлкич', 'Orgeh' => '10000031', 'Email' => 'yelkin@test.ru'],
        'u5' => ['Pernr' => '00000001', 'Ename' => 'Дубликат Дубликос', 'Orgeh' => '10000033', 'Email' => ''],
        'u6' => ['Pernr' => '00000006', 'Ename' => '', 'Orgeh' => '', 'Email' => ''],
    ];
    return $st;
}

function makeIndexedStorage() {
    $st = makeStorage();
    $st->buildIndexes([
        'Pernr'  => ['hard' => true, 'case' => true,  'soft' => true,  'softcase' => true],
        'Ename'  => ['hard' => true, 'case' => true,  'soft' => true,  'softcase' => true],
        'Orgeh'  => ['hard' => true, 'case' => true,  'soft' => true,  'softcase' => true],
        'Email'  => ['hard' => true, 'case' => true,  'soft' => true,  'softcase' => true],
    ]);
    return $st;
}

#!/usr/bin/php
<?php
/*
 * Раннер тестов COrgStructureStorage.
 * Запуск: php tests.php
 * Совместимо с PHP 5.6 (т.к. используется на хосте Bitrix).
 */

require_once __DIR__ . '/libs/Abstract/COrgStructureStorage.php';
require_once __DIR__ . '/libs/Arms/CArmsUserList.php';
require_once __DIR__ . '/tests/helper.php';

$blocks = [
    'normalization',
    'field_compare',
    'indexed_search',
    'index_consistency',
    'arms_user_indexes',
    'unindexed_search',
    'filter_item',
    'edge_cases',
];

foreach ($blocks as $block) {
    require_once __DIR__ . '/tests/' . $block . '.php';
}

foreach ($blocks as $block) {
    $test = 'test_' . $block;
    $test();
}

echo "\n";
echo "Всего тестов: $tests\n";
echo "Провалов:     $failures\n";
echo $failures ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failures > 0 ? 1 : 0);

<?php
/*
 * Тесты fieldCompare и fieldCompareInt.
 */

function test_field_compare() {
    echo "\n--- Тесты fieldCompare ---\n";
    $item = ['Name' => 'Иванов', 'Age' => 30, 'Roles' => ['admin', 'user']];
    assertEq(true,  COrgStructureStorage::fieldCompare($item, 'Name', 'Иванов', false, false), 'fieldCompare: точное совпадение');
    assertEq(true,  COrgStructureStorage::fieldCompare($item, 'Name', 'ИВАНОВ', false, false), 'fieldCompare: case=false игнорирует регистр');
    assertEq(false, COrgStructureStorage::fieldCompare($item, 'Name', 'ИВАНОВ', true, false),  'fieldCompare: case=true учитывает регистр');
    $itemY = ['Name' => 'Ёлкин'];
    assertEq(true,  COrgStructureStorage::fieldCompare($itemY, 'Name', 'Елкин', false, true), 'fieldCompare: soft (Ё==Е)');
    assertEq(true,  COrgStructureStorage::fieldCompare($item, 'Missing', '', false, false),  'fieldCompare: missing field + empty value');
    assertEq(false, COrgStructureStorage::fieldCompare($item, 'Missing', 'x', false, false), 'fieldCompare: missing field + non-empty value');
    assertEq(true,  COrgStructureStorage::fieldCompare($item, 'Roles', 'admin,user', false, false), 'fieldCompare: массив → строка');

    echo "\n--- Тесты fieldCompareInt ---\n";
    assertEq(true,  COrgStructureStorage::fieldCompareInt($item, 'Age', 30),   'fieldCompareInt: точное совпадение');
    assertEq(true,  COrgStructureStorage::fieldCompareInt($item, 'Age', '30'), 'fieldCompareInt: строка vs int');
    assertEq(false, COrgStructureStorage::fieldCompareInt($item, 'Age', 31),   'fieldCompareInt: mismatch');
}

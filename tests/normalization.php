<?php
/*
 * Тесты нормализации и утилит COrgStructureStorage.
 */

function test_normalization() {
    echo "\n--- Тесты нормализации ---\n";
    assertEq('a,b,c', COrgStructureStorage::normalizeValue(['a', 'b', 'c']), 'normalizeValue массив → строка');
    assertEq('Тест & Co', COrgStructureStorage::normalizeValue('Тест &amp; Co'), 'normalizeValue HTML entities');
    assertEq('123', COrgStructureStorage::normalizeValue(123), 'normalizeValue число → строка');
    assertEq('иванов', COrgStructureStorage::uncaseString('ИВАНОВ'), 'uncaseString верхний регистр');
    assertEq('иванов', COrgStructureStorage::uncaseString('ИваНов'), 'uncaseString смешанный регистр');
    assertEq('Елкин', COrgStructureStorage::softString('Ёлкин'), 'softString Ё → Е');
    assertEq('елкин', COrgStructureStorage::softString('ёлкин'), 'softString ё → е');
    assertEq('hard',     COrgStructureStorage::hardenesMode(false, false), 'hardenesMode(false,false)=hard');
    assertEq('case',     COrgStructureStorage::hardenesMode(true,  false), 'hardenesMode(true,false)=case');
    assertEq('soft',     COrgStructureStorage::hardenesMode(false, true),  'hardenesMode(false,true)=soft');
    assertEq('softcase', COrgStructureStorage::hardenesMode(true,  true),  'hardenesMode(true,true)=softcase');
}

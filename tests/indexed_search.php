<?php
/*
 * Тесты поиска с индексами.
 */

function test_indexed_search() {
    echo "\n--- Тесты поиска с индексами ---\n";
    $st = makeIndexedStorage();

    assertEq(['u1','u5'], $st->findIdsBy(['Pernr' => '00000001']), 'findIdsBy Pernr точное совпадение (hard), 2 записи');
    assertEq(['u1'], $st->findIdsBy(['Ename' => 'ИВАНОВ ИВАН ИВАНОВИЧ'], true, true, false), 'findIdsBy Ename case-insensitive');
    assertEq(['u4'], $st->findIdsBy(['Ename' => 'Елкин Елкий Елкич'], true, false, true), 'findIdsBy Ename soft (Ё==Е)');
    assertEq(['u4'], $st->findIdsBy(['Ename' => 'ёлкин ёлкий ёлкич'], true, true, true), 'findIdsBy Ename softcase (Ё==Е + регистр)');
    assertEq(['u1'], $st->findIdsBy(['Pernr' => '00000001', 'Orgeh' => '10000031']), 'findIdsBy AND Pernr+Orgeh');
    assertEq(['u1','u2','u4','u5'], $st->findIdsBy(['Pernr' => '00000001', 'Orgeh' => '10000031'], false), 'findIdsBy OR Pernr+Orgeh');
    assertEq(['u5','u6'], $st->findIdsBy(['Email' => '']), 'findIdsBy Email пустое значение');
    assertEq([], $st->findIdsBy(['Pernr' => '00009999', 'Orgeh' => '10000031']), 'findIdsBy AND с пустым индексом → быстрый []');
}

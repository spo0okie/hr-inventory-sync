<?php
/*
 * Тесты поиска с индексами.
 */

function test_indexed_search() {
    echo "\n--- Тесты поиска с индексами ---\n";
    $st = makeIndexedStorage();

    assertEq(['u1','u5'], $st->findIdsBy(['Pernr' => '00000001']), 'findIdsBy Pernr точное совпадение (hard), 2 записи');
    assertEq('u1', $st->findIdBy('Pernr', '00000001'), 'findIdBy берёт первый ID из индексного результата');
    assertEq(['u1'], $st->findIdsBy(['Ename' => 'ИВАНОВ ИВАН ИВАНОВИЧ']), 'findIdsBy: case=false по умолчанию игнорирует регистр');
    assertEq('u1', $st->findIdBy('Ename', 'ИВАНОВ ИВАН ИВАНОВИЧ'), 'findIdBy использует case-индекс');
    assertEq([], $st->findIdsBy(['Ename' => 'ИВАНОВ ИВАН ИВАНОВИЧ'], true, true, false), 'findIdsBy: case=true учитывает регистр');
    assertEq(null, $st->findIdBy('Ename', 'ИВАНОВ ИВАН ИВАНОВИЧ', true, false), 'findIdBy: case=true учитывает регистр');
    assertEq(['u4'], $st->findIdsBy(['Ename' => 'Елкин Елкий Елкич'], true, true, true), 'findIdsBy: case=true, soft=true (Ё==Е)');
    assertEq('u4', $st->findIdBy('Ename', 'Елкин Елкий Елкич', true, true), 'findIdBy использует soft-индекс');
    assertEq(['u4'], $st->findIdsBy(['Ename' => 'ёлкин ёлкий ёлкич'], true, false, true), 'findIdsBy: case=false, soft=true (Ё==Е + регистр)');

    $withoutIndexes = makeStorage();
    foreach ([[true, false], [false, false], [true, true], [false, true]] as $flags) {
        $case = $flags[0];
        $soft = $flags[1];
        assertEq(
            $withoutIndexes->findIdsBy(['Ename' => 'ёлкин ёлкий ёлкич'], true, $case, $soft),
            $st->findIdsBy(['Ename' => 'ёлкин ёлкий ёлкич'], true, $case, $soft),
            'findIdsBy: индексы и fallback одинаковы (case=' . (int)$case . ', soft=' . (int)$soft . ')'
        );
    }

    assertEq(['u1'], $st->findIdsBy(['Pernr' => '00000001', 'Orgeh' => '10000031']), 'findIdsBy AND Pernr+Orgeh');
    assertEq(['u1','u2','u4','u5'], $st->findIdsBy(['Pernr' => '00000001', 'Orgeh' => '10000031'], false), 'findIdsBy OR Pernr+Orgeh');
    assertEq(['u5','u6'], $st->findIdsBy(['Email' => '']), 'findIdsBy Email пустое значение');
    assertEq([], $st->findIdsBy(['Pernr' => '00009999', 'Orgeh' => '10000031']), 'findIdsBy AND с пустым индексом → быстрый []');
}

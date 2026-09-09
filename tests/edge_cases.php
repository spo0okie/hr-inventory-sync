<?php
/*
 * Тесты граничных случаев.
 */

function test_edge_cases() {
    echo "\n--- Тесты граничных случаев ---\n";
    $st = makeStorage();
    $st->buildIndexes([
        'Pernr' => ['hard' => true],
        'Ename' => ['hard' => true, 'case' => true, 'soft' => true, 'softcase' => true],
        'Orgeh' => ['hard' => true, 'case' => true, 'soft' => true, 'softcase' => true],
        'Email' => ['hard' => true, 'case' => true, 'soft' => true, 'softcase' => true],
    ]);
    assertEq(['u1','u2','u3','u4','u5','u6'], $st->findIdsBy([]), 'findIdsBy пустой фильтр → все ID');

    $empty = new COrgStructureStorage();
    $empty->data = [];
    $empty->buildIndexes(['Pernr' => ['hard' => true]]);
    assertEq([], $empty->findIdsBy(['Pernr' => 'x']), 'findIdsBy пустое хранилище');
    assertEq(null, $st->findIdBy('Pernr', '00009999'), 'findIdBy несуществующий элемент');
    assertEq(null, $st->findIdBy(null, 'x'), 'findIdBy null field → null');
    assertEq(null, $st->findIdBy('Pernr', null), 'findIdBy null value → null');
}

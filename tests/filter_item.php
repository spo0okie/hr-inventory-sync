<?php
/*
 * Тесты filterItem.
 */

function test_filter_item() {
    echo "\n--- Тесты filterItem ---\n";
    $st = makeStorage();
    assertEq(true,  $st->filterItem('u1', ['Pernr' => '00000001'], true), 'filterItem AND совпадение');
    assertEq(false, $st->filterItem('u1', ['Pernr' => '00009999'], true), 'filterItem AND несовпадение');
    assertEq(true,  $st->filterItem('u1', ['Pernr' => '00000001', 'Orgeh' => '10000031'], true), 'filterItem AND 2 поля (все совпали)');
    assertEq(false, $st->filterItem('u1', ['Pernr' => '00000001', 'Orgeh' => '10000099'], true), 'filterItem AND 2 поля (одно не совпало)');
    assertEq(true,  $st->filterItem('u1', ['Orgeh' => '10000031', 'Pernr' => '00009999'], false), 'filterItem OR (хотя бы одно совпало)');
    assertEq(false, $st->filterItem('u1', ['Pernr' => '00009998', 'Orgeh' => '10000098'], false), 'filterItem OR (ни одного не совпало)');
}

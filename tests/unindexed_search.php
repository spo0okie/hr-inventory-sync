<?php
/*
 * Тесты поиска без индекса (fallback).
 */

function test_unindexed_search() {
    echo "\n--- Тесты поиска без индекса (fallback) ---\n";
    $st = makeStorage();
    assertEq([], $st->findIdsBy(['Doljnost' => 'Менеджер']), 'findIdsBy неиндексированное поле → fallback (ничего не найдено)');
    $st->data['u3']['Doljnost'] = 'Менеджер';
    assertEq(['u3'], $st->findIdsBy(['Doljnost' => 'Менеджер']), 'findIdsBy неиндексированное поле → fallback (найдено)');
}

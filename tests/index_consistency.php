<?php
/*
 * Проверяет, что все типы индекса и fallback дают одинаковый результат.
 */

function test_index_consistency() {
    echo "\n--- Тесты согласованности индексов ---\n";

    $modes = [
        ['hard' => true],
        ['case' => true],
        ['soft' => true],
        ['softcase' => true],
    ];
    $queries = [
        [['Ename' => 'Ёлкин Ёлкий Ёлкич'], true, true, false],
        [['Ename' => 'ёлкин ёлкий ёлкич'], true, false, false],
        [['Ename' => 'Елкин Елкий Елкич'], true, true, true],
        [['Ename' => 'елкин елкий елкич'], true, false, true],
    ];

    foreach ($modes as $index => $mode) {
        $indexed = makeStorage();
        $indexed->buildIndexes(['Ename' => $mode]);
        $query = $queries[$index];
        $fallback = makeStorage();
        assertEq(
            $fallback->findIdsBy($query[0], $query[1], $query[2], $query[3]),
            $indexed->findIdsBy($query[0], $query[1], $query[2], $query[3]),
            'Режим индекса ' . key($mode) . ' совпадает с fallback'
        );
    }

    $defaultModes = makeStorage();
    $defaultModes->buildIndexes(['Ename' => []]);
    assertEq(['u1'], $defaultModes->findIdsBy(['Ename' => 'ИВАНОВ ИВАН ИВАНОВИЧ']), 'По умолчанию создаётся case-индекс');
    assertEq([], $defaultModes->findIdsBy(['Ename' => 'ИВАНОВ ИВАН ИВАНОВИЧ'], true, true), 'По умолчанию hard-поиск учитывает регистр');

    $normalized = new COrgStructureStorage();
    $normalized->data = [
        'html' => ['Value' => 'Тест &amp; Co'],
        'array' => ['Value' => ['admin', 'user']],
    ];
    $normalized->buildIndexes(['Value' => ['case' => true]]);
    assertEq(['html'], $normalized->findIdsBy(['Value' => 'Тест & Co']), 'Индекс нормализует HTML entities');
    assertEq(['array'], $normalized->findIdsBy(['Value' => ['admin', 'user']]), 'Индекс нормализует массив в строку');

    $mixed = makeIndexedStorage();
    $mixed->data['u3']['Doljnost'] = 'Менеджер';
    assertEq(
        ['u2', 'u3'],
        $mixed->findIdsBy(['Pernr' => '00000002', 'Doljnost' => 'Менеджер'], false),
        'OR объединяет совпадения индексированного и неиндексированного полей'
    );
    assertEq(
        ['u3'],
        $mixed->findIdsBy(['Pernr' => '00009999', 'Doljnost' => 'Менеджер'], false),
        'OR с пустым индексом не скрывает fallback-совпадение'
    );
}

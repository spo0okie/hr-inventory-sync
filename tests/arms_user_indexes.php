<?php
/*
 * Проверяет, что CArmsUserList использует индексы родительского хранилища.
 */

function test_arms_user_indexes() {
    echo "\n--- Тесты индексов CArmsUserList ---\n";
    $users = new CArmsUserList();
    $users->data = [
        'employment-1' => ['Pernr' => '00000001', 'uid' => 'uid-1', 'Ename' => 'Ёлкин Иван', 'Orgeh' => 'org-1', 'org_id' => 'no-structure', 'Persg' => 1, 'Uvolen' => 0],
        'employment-2' => ['Pernr' => '00000001', 'uid' => 'uid-1', 'Ename' => 'Ёлкин Иван', 'Orgeh' => 'org-2', 'org_id' => 'no-structure', 'Persg' => 2, 'Uvolen' => 0],
    ];
    $users->buildExFieldsAll();
    $users->buildIndexes();

    assertEq(['employment-1', 'employment-2'], $users->findIdsBy(['Pernr' => '00000001']), 'ARMS: табельный номер ищется родительским индексом');
    assertEq(['employment-1', 'employment-2'], $users->findIdsBy(['uid' => 'UID-1']), 'ARMS: uid использует регистронезависимый индекс');
    assertEq(['employment-1', 'employment-2'], $users->findIdsBy(['Ename' => 'елкин иван'], true, false, true), 'ARMS: ФИО использует softcase-индекс');
    assertEq(['employment-1'], $users->findIdsBy(['Pernr' => '00000001', 'Orgeh' => 'org-1']), 'ARMS: составной фильтр пересекает родительские индексы');
    assertEq('employment-1', $users->getMainEmployment(['Ename' => 'Елкин Иван']), 'ARMS: getMainEmployment сохраняет поиск без учёта Ё');
    assertEq(2, $users->applyOrgRoots(['no-structure' => 'org-root']), 'ARMS: изменены коды подразделений без оргструктуры');
    assertEq(['employment-1', 'employment-2'], $users->findIdsBy(['Orgeh' => 'org-root']), 'ARMS: Orgeh-индекс перестраивается после applyOrgRoots');
}

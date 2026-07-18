<?php
/**
 * Пример конфига импорта в инвентори
 *
 * 2019-10-07, обновленная редация
 * Теперь необходимо в одну бд заталкивать несколько юрлиц.
 */

//источник данных: 1С - Рога и копыта
$dataSrc_1c_rik=[
    'org_id'=>     1,
    'org'=>[    //источник оргструктуры
        'src'=>'c1',    
        'ftype'=>'csv',
        'path'=>'/tmp/sync_inventory/out1_str2.csv',
    ],
    'usr'=>[ //источник списка сотрудников
        'src'=>'c1',    
        'ftype'=>'csv',
        'path'=>'/tmp/sync_inventory/out1.csv',
    ],
];

//источник данных: 1С - Табуретка incorporated
$dataSrc_1c_tab=[
    'org_id'=>     2,
    'org'=>[    //источник оргструктуры
        'src'=>'c1',
        'ftype'=>'csv',
        'path'=>'/tmp/sync_inventory/out2_str2.csv',
    ],
    'usr'=>[ //источник списка сотрудников
        'src'=>'c1',    
        'ftype'=>'csv',
        'path'=>'/tmp/sync_inventory/out2.csv',
    ],
];

//источник данных: 1С - Нуф Нуф и Ко
$dataSrc_1c_nuf=[
    'org_id'=>     3,
    'org'=>[    //источник оргструктуры
        'src'=>'c1',
        'ftype'=>'csv',
        'path'=>'/tmp/sync_inventory/out3_str2.csv',
    ],
    'usr'=>[ //источник списка сотрудников
        'src'=>'c1',
        'ftype'=>'csv',
        'path'=>'/tmp/sync_inventory/out3.csv',
    ],
];


//источник данных: Инвентаризация - MyDB (для передачи из инв в Битрикс)
$dataSrc_inv_my=[
    'org_id'=>     1,
    'org'=>[    //откуда берем оргструктуру
        'src'=>'sql',   
        'ftype'=>'sql',
        'path'=>['127.0.0.1', 'arms_user', 'password', 'arms_db'],
    ],
    'usr'=>[    //откуда берем юзеров
        'src'=>'sql',   'ftype'=>'sql',
        'path'=>['127.0.0.1', 'arms_user', 'password', 'arms_db'],
    ],
];

//источник данных: ARMS (инвентаризация) -> Bitrix, через REST API.
//На этот источник настроен portal.php в новом (ARMS-хаб) режиме.
$arms_api = [
    'url'  => 'http://localhost:8081/web/api', //база REST API ARMS (на проде — свой хост)
    'user' => '',   //сервисный пользователь ARMS (пусто = без авторизации, только dev)
    'pass' => '',
    'orgs' => [1],  //какие организации (org_id) выгружать в Bitrix
];

$dataSrc_arms = [
    'org_id' => 0,
    'org' => ['src'=>'arms', 'ftype'=>'rest', 'path'=>$arms_api], //оргструктура
    'usr' => ['src'=>'arms', 'ftype'=>'rest', 'path'=>$arms_api], //сотрудники
];

//режим dry-run для portal.php: НЕ писать в Bitrix, только логировать намеренные
//изменения. Для аккуратной выкатки на прод: сначала прогнать с true, изучить лог.
$dryRun = true;

// список групп Битрикс для включения в них всех пользователей
$grpListAll = ['Сотрудники'];

// список ключевых слов в должностях руководителей
$managersTitles = ['начальник','руководитель','директор'];

// список групп Битрикс для включения в них руководителей
$grpListManagers = ['Могут создавать рабочие группы'];

// адрес службы персонала для напоминаний о днях рождения (bDayReminder.php)
$bdayRemindMail = 'hr@example.com';

// словарь соответствия завода сотрудника (поле Werks в SAP) названию организации
// нужен, чтобы переопределить название, которое SAP отдает в поле NameF
// используется только источниками типа 'sap'
$factories=[
    '1101'=>'ООО "Рога и копыта"',
    '1102'=>'Филиал ООО "Рога и копыта" в г.Урюпинск',
];

$inventory=[
    'ip'=>      '127.0.0.1',
    'user'=>    'arms_user',
    'passwd'=>  'password',
    'db'=>      'arms_db',
];


$inventory_import=[
    $dataSrc_1c_rik,
    $dataSrc_1c_tab,
    $dataSrc_1c_nuf
];

?>

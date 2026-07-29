<?php
/**
 * Шаблон конфигурации SAPsync (схема-хаб: SAP -> ARMS -> Bitrix).
 * Скопируйте в config.php и заполните. config.php в gitignore.
 *
 * Какие переменные нужны какому скрипту:
 *   inventory-arms.php  — $arms_api, $inventoryOrgs, $inventory_import, $dryRun,
 *                         $sapCache*, $sapAbsence* (отсутствия), $sapPhotoCache (режим photos),
 *                         $sapPushUserData (обратная синхронизация контактов в SAP)
 *   portal.php          — $arms_api, $dataSrc_arms, $dryRun, $bitrix_root, $grpList*, $managersTitles
 *   absenceSubscr.php   — $bitrix_root
 *   bDayReminder.php    — $bitrix_root, $bdayRemindMail
 */

// ===== ARMS (инвентаризация) =====

//REST API ARMS. Сервисному пользователю нужны права: index/view/update/delete
//на users, org-struct, absences, scans (RBAC ARMS)
$arms_api = [
	'url'  => 'https://inventory.example.com/web/api', //база REST API (обязательно /web/api)
	'user' => 'sapsync',    //сервисный пользователь ARMS (пусто = без авторизации, только dev)
	'pass' => 'secret',
	'orgs' => [1],          //какие организации (org_id ARMS) выгружать в Bitrix
];

//источник данных для portal.php (выгрузка ARMS -> Bitrix)
$dataSrc_arms = [
	'org_id' => 0,
	'org' => ['src'=>'arms', 'ftype'=>'rest', 'path'=>$arms_api], //оргструктура
	'usr' => ['src'=>'arms', 'ftype'=>'rest', 'path'=>$arms_api], //сотрудники
];

//РАСКЛАДКА ПОДРАЗДЕЛЕНИЙ SAP ПО ЮРЛИЦАМ (приём SAP -> ARMS):
//  Objid корневого узла юрлица в дереве SAP => org_id организации в ARMS.
//Организации (org_id) заводят администраторы ARMS вручную, здесь они связываются
//с корнями оргструктуры SAP. Каждое подразделение и сотрудник получает org_id
//того юрлица, под чьим корнем лежит в дереве (поиск предка). Узел вне корней
//в инвентаризацию не попадает. При вложенных юрлицах более конкретное — НИЖЕ
//по списку (выигрывает последнее совпадение).
$inventoryOrgs = [
	'10000001' => 1,   // Objid «Рога и копыта» в SAP      -> org_id 1 в ARMS
	'10001128' => 2,   // Objid дочерней «Табуретки» в SAP -> org_id 2 в ARMS
];

//источники приёма в инвентаризацию (inventory-arms.php); src: 'sap' | 'c1'
$inventory_import = [
	'sources' => [
		[   //SAP: оргструктура и сотрудники из одного JSON-кэша
			'org' => ['src'=>'sap', 'ftype'=>'json', 'path'=>'/opt/SAPcache/cache/orgStruct.cache.json'],
			'usr' => ['src'=>'sap', 'ftype'=>'json', 'path'=>'/opt/SAPcache/cache/orgStruct.cache.json'],
		],
	],
];

// ===== режим dry-run =====

//true — inventory-arms.php и portal.php НИЧЕГО не пишут (в ARMS и Bitrix
//соответственно), только логируют намеренные изменения. Для выкатки на прод:
//сначала прогнать с true и изучить лог.
$dryRun = true;

// ===== внешний кэш SAP (скрипты вне репозитория) =====

$sapCache        = '/opt/SAPcache/portal.php';                    //обновить кэш оргструктуры и сотрудников
$sapCacheFile    = '/opt/SAPcache/cache/orgStruct.cache.json';    //куда он пишет (path источника sap выше)
$sapCacheDir     = '/opt/SAPcache/cache/';                        //каталог кэшей (фотографии)
//выгрузок отсутствий может быть несколько: основная (все типы, только утверждённые записи)
//и плановая (только отпуска, включая ещё не утверждённые — они приходят с Sprps=X и
//принимаются в ARMS с типом VACATION_PLAN). Файлы объединяются в одно хранилище по
//составному ключу Pernr-Awart-Begda-Endda, дубли схлопываются; порядок важен — записи
//первой выгрузки не перезаписываются последующими.
//Скрипты обновления — список, файлы — путь => корневой элемент JSON выгрузки.
//Закомментируйте $sapAbsenceFile, чтобы отключить приём отсутствий.
$sapAbsenceCache = ['/opt/SAPcache/absence.php', '/opt/SAPcache/absence2.php'];
$sapAbsenceFile  = [
	'/opt/SAPcache/cache/absence.cache.json'  => 'OAbsents',      //YfmPersAbsents, все типы отсутствий
	'/opt/SAPcache/cache/absence2.cache.json' => 'OVacations',    //YfmVacationNotifications, плановые отпуска
];
$sapPhotoCache   = '/opt/SAPcache/photo.php';                     //выгрузка фото одного сотрудника: <pernr> <файл>
//обратная синхронизация контактов ARMS -> SAP (email из AD, телефоны из инвентаризации):
//скрипт отправки одного контакта: <pernr> <тип 0010|0020|CELL> <значение>.
//Закомментируйте, чтобы отключить обратную синхронизацию.
$sapPushUserData = '/opt/SAPcache/pushUserData.php';

// ===== Bitrix (portal.php и сервисы портала) =====

$bitrix_root = '/home/bitrix/www';

//группы Битрикс для включения всех сотрудников
$grpListAll = ['Сотрудники'];

//ключевые слова в должностях руководителей
$managersTitles = ['начальник','руководитель','директор'];

//группы Битрикс для руководителей
$grpListManagers = ['Могут создавать рабочие группы'];

//адрес службы персонала для напоминаний о днях рождения (bDayReminder.php)
$bdayRemindMail = 'hr@example.com';

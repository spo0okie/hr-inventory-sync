#!/usr/bin/php
<?php
/*
 * Приём оргструктуры, сотрудников, отсутствий и фотографий из источника (SAP,
 * в перспективе 1C) в инвентаризацию ARMS через REST API.
 * Запускать из корня репозитория.
 *
 * Источник(и) и разложение по юрлицам берутся из конфига:
 *   $inventory_import['sources'] — дескрипторы источников (src=sap/c1, ftype=json/csv);
 *   $inventoryOrgs               — Objid(SAP) => org_id(ARMS);
 *   $arms_api                    — url/user/pass REST API ARMS;
 *   $dryRun                      — не писать в ARMS, только логировать.
 */

//ошибки — в консоль независимо от php.ini: скрипт запускают руками и по cron,
//молчаливая смерть от фатала (display_errors=Off) недопустима
ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
echo date('c') . " Script started\n";

require_once "config.php";
require_once "libs/OrgStruct.php";
require_once "libs/Arms/CArmsIngest.php";
require_once "libs/Abstract/CUserPhotoStruct.php";

//флажок ошибок для мониторинга (zabbix), см. reportSyncErrors в OrgStruct.php
$errFlagFile = isset($syncErrorsFile) ? $syncErrorsFile : '';

//клиент и писатель ARMS
$client = new CArmsRestClient($arms_api['url'], $arms_api['user'] ?? '', $arms_api['pass'] ?? '');
$writer = new CArmsWriter($client);
$writer->dryRun = !empty($dryRun);
if ($writer->dryRun) echo "*** DRY-RUN: запись в ARMS отключена, изменения только логируются ***\n";

$ingest = new CArmsIngest($writer, $inventoryOrgs);

//режим photos: дополнительно синхронизировать фотографии сотрудников
$withPhotos = in_array('photos', $argv);

//обновляем JSON-кэши выгрузок SAP (скрипты вне репозитория, пути в конфиге)
if (isset($sapCache) && strlen($sapCache)) {
	echo "Refreshing SAP cache...\n";
	system($sapCache);
}
//выгрузок отсутствий у SAP несколько (основная и плановая), поэтому в конфиге и файлы,
//и скрипты обновления кэша могут быть списками; строка — прежний вариант с одной выгрузкой
$absenceFiles = [];
if (isset($sapAbsenceFile))
	$absenceFiles = is_array($sapAbsenceFile)
		? $sapAbsenceFile						//путь => корневой элемент JSON
		: (strlen($sapAbsenceFile) ? [$sapAbsenceFile => 'OAbsents'] : []);
$absenceCaches = [];
if (isset($sapAbsenceCache))
	$absenceCaches = is_array($sapAbsenceCache)
		? $sapAbsenceCache
		: (strlen($sapAbsenceCache) ? [$sapAbsenceCache] : []);

if ($absenceCaches && $absenceFiles) {
	echo "Refreshing SAP absence cache...\n";
	//все выгрузки обновляются в одном прогоне: иначе файлы разъезжаются по времени
	foreach ($absenceCaches as $cacheScript) system($cacheScript);
}

//проверка доступности API и авторизации до начала работы: лучше упасть с внятным
//сообщением здесь, чем на первой записи посреди синхронизации
try {
	$client->whoami();
} catch (RuntimeException $e) {
	fwrite(STDERR, "ОШИБКА: ARMS API недоступен: " . $e->getMessage() . "\n");
	reportSyncErrors($errFlagFile, 1);
	exit(1);
}

$syncErrors = 0;

try {

foreach ($inventory_import['sources'] as $dataSrc) {
	$sapOrg = initOrgStructure($dataSrc);
	$sapUsers = initUserList($dataSrc);

	//предохранитель: не заливать пустой/обрезанный источник (как в portal.php/absence.php)
	if (count($sapOrg->getIds()) < 50)
		die("Fail loading orgStruct suspected (" . count($sapOrg->getIds()) . ")\n");
	if (count($sapUsers->getIds()) < 250)
		die("Fail loading users suspected (" . count($sapUsers->getIds()) . ")\n");

	echo "Ingesting org structure...\n";
	$ro = $ingest->ingestOrgStruct($sapOrg);
	echo "  org: создано {$ro['created']}, обновлено {$ro['updated']}, без изменений {$ro['ok']}, вне юрлиц {$ro['skipped']}\n";

	echo "Ingesting users...\n";
	$ru = $ingest->ingestUsers($sapUsers, $sapOrg);
	echo "  users: создано {$ru['created']}, обновлено {$ru['updated']} (из них усыновлено ручных {$ru['adopted']}), " .
		"без изменений {$ru['ok']}, вне юрлиц {$ru['skipped']}\n";

	//обратная синхронизация контактов ARMS -> SAP: email и телефоны ведутся в AD и
	//инвентаризации, SAP получает их отсюда. Только для источника SAP (у 1С своих
	//полей контактов в выгрузке нет, сверять не с чем) и только если настроен скрипт
	if (isset($sapPushUserData) && strlen($sapPushUserData) && $dataSrc['usr']['src'] === 'sap') {
		echo "Pushing contacts to SAP...\n";
		$pusher = function ($pernr, $type, $value) use ($sapPushUserData, $writer) {
			if ($writer->dryRun) {
				echo "  [DRY] push $pernr $type '$value'\n";
				return;
			}
			system($sapPushUserData . ' ' . escapeshellarg($pernr) . ' '
				. escapeshellarg($type) . ' ' . escapeshellarg($value));
		};
		$rc = $ingest->pushContacts($sapUsers, $sapOrg, $pusher);
		echo "  contacts: сверено {$rc['checked']}, отправлено: email {$rc['email']}, " .
			"раб. тел {$rc['phone']}, моб. тел {$rc['mobile']}; " .
			"вне юрлиц {$rc['skippedOrg']}, нет в ARMS {$rc['skippedUser']}\n";
	}

	if (!$withPhotos) {
		echo "Photos: пропущено (синхронизация фото — запуск с ключом photos)\n";
	} else {
		echo "Ingesting photos...\n";
		//бинарник фото запрашивается у SAP только для тех, у кого дата обновилась
		$photoFetcher = function ($pernr) use ($sapPhotoCache, $sapCacheDir) {
			$cache = $sapCacheDir . 'YfmGetPersPhoto.' . $pernr . '.json';
			system("$sapPhotoCache $pernr $cache");
			$photo = new CUserPhotoStruct();
			if (!$photo->loadFromJson($cache)) return null;
			if (!$photo->getTimeStamp()) return null; //в SAP фото так и не загружали
			return $photo->getImage();
		};
		$rp = $ingest->ingestPhotos($sapUsers, $sapOrg, $photoFetcher);
		echo "  photos: актуально {$rp['ok']}, обновлено {$rp['updated']}, ошибок {$rp['failed']}; " .
			"без фото {$rp['noPhoto']}, вне юрлиц {$rp['skippedOrg']}, нет в ARMS {$rp['skippedUser']}\n";
		$syncErrors += $rp['failed'];
	}
}

//приём отсутствий (если настроен кэш выгрузки отсутствий SAP)
if ($absenceFiles) {
	echo "Loading absences...\n";
	$sapAbsents = new CSapAbsentsStructure();
	//выгрузки складываются в одно хранилище, дубли схлопываются по составному ключу;
	//порядок важен: первой грузится основная, её записи не перезаписываются плановыми
	foreach ($absenceFiles as $absenceFile => $absenceRoot) {
		$loaded = $sapAbsents->appendFromJson($absenceFile, $absenceRoot);

		//предохранитель на каждую выгрузку, а не на итог (как в absence.php): обратный
		//проход приёма удаляет из ARMS всё, чего нет в источнике, поэтому не загрузившийся
		//файл при живых остальных молча снёс бы свои отсутствия
		if ($loaded === false || $loaded < 100)
			die("Fail loading absences suspected in $absenceFile (" . var_export($loaded, true) . ")\n");
	}

	echo "Ingesting absences...\n";
	$ra = $ingest->ingestAbsences($sapAbsents);
	echo "  absences: создано {$ra['created']}, обновлено {$ra['updated']}, без изменений {$ra['ok']}, " .
		"удалено исчезнувших {$ra['removed']}, ошибок {$ra['failed']}, пропущено: без сотрудника в ARMS " .
		"{$ra['skippedUser']}, с неизвестным типом {$ra['skippedType']}; " .
		"запланированных отпусков {$ra['planned']}\n";
	$syncErrors += $ra['failed'];
}

} catch (RuntimeException $e) {
	//ошибки ARMS API (RBAC/валидация/сеть) — с телом ответа, см. CArmsRestClient::apiError
	fwrite(STDERR, "ОШИБКА: " . $e->getMessage() . "\n");
	reportSyncErrors($errFlagFile, $syncErrors + 1);
	exit(1);
}

reportSyncErrors($errFlagFile, $syncErrors);
echo date('c') . " Script complete\n";

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

error_reporting(E_WARNING);
echo date('c') . " Script started\n";

require_once "config.php";
require_once "libs/OrgStruct.php";
require_once "libs/Arms/CArmsIngest.php";
require_once "libs/Abstract/CUserPhotoStruct.php";

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
if (isset($sapAbsenceCache) && strlen($sapAbsenceCache) && isset($sapAbsenceFile) && strlen($sapAbsenceFile)) {
	echo "Refreshing SAP absence cache...\n";
	system($sapAbsenceCache);
}

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
	echo "  users: создано {$ru['created']}, обновлено {$ru['updated']}, без изменений {$ru['ok']}, вне юрлиц {$ru['skipped']}\n";

	if ($withPhotos) {
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
	}
}

//приём отсутствий (если настроен кэш выгрузки отсутствий SAP)
if (isset($sapAbsenceFile) && strlen($sapAbsenceFile)) {
	echo "Loading absences...\n";
	$sapAbsents = new CSapAbsentsStructure();
	$sapAbsents->loadFromJson($sapAbsenceFile);

	//предохранитель: как в absence.php
	if (!is_array($sapAbsents->getIds()) || count($sapAbsents->getIds()) < 100)
		die("Fail loading absences suspected (" . count((array)$sapAbsents->getIds()) . ")\n");

	echo "Ingesting absences...\n";
	$ra = $ingest->ingestAbsences($sapAbsents);
	echo "  absences: создано {$ra['created']}, обновлено {$ra['updated']}, без изменений {$ra['ok']}, " .
		"удалено исчезнувших {$ra['removed']}, пропущено: без сотрудника в ARMS {$ra['skippedUser']}, " .
		"с неизвестным типом {$ra['skippedType']}\n";
}

echo date('c') . " Script complete\n";

#!/usr/bin/php
<?php
/*
 * Приём оргструктуры и сотрудников из источника (SAP/1C) в инвентаризацию ARMS
 * через REST API. REST-замена inventory-SAP.php (который писал напрямую в SQL).
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

//клиент и писатель ARMS
$client = new CArmsRestClient($arms_api['url'], $arms_api['user'] ?? '', $arms_api['pass'] ?? '');
$writer = new CArmsWriter($client);
$writer->dryRun = !empty($dryRun);
if ($writer->dryRun) echo "*** DRY-RUN: запись в ARMS отключена, изменения только логируются ***\n";

$ingest = new CArmsIngest($writer, $inventoryOrgs);

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
	echo "  org: обработано {$ro['processed']}, пропущено {$ro['skipped']}\n";

	echo "Ingesting users...\n";
	$ru = $ingest->ingestUsers($sapUsers, $sapOrg);
	echo "  users: обработано {$ru['processed']}, пропущено {$ru['skipped']}\n";
}

echo date('c') . " Script complete\n";

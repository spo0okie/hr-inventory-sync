#!/usr/bin/php
<?php
/*
 * Выгрузка отсутствий из инвентаризации ARMS -> график отсутствий Bitrix.
 * Выполняется из оболочки на хосте Bitrix, запускать из корня репозитория.
 *
 * Отдельная точка входа, а не часть portal.php: отсутствий десятки тысяч, у них
 * своя периодичность обновления, и держать их в одном прогоне с оргструктурой
 * незачем. Сотрудники и оргструктура при этом должны быть уже выгружены
 * (portal.php) — отсутствие привязывается к существующему пользователю портала,
 * пользователей эта выгрузка не заводит.
 *
 * Ключ сверки — свойство SAP_ID элемента: для записей SAP это натуральный ключ
 * источника (Pernr-Awart-Begda-Endda), для ручных записей ARMS — "arms-{id}".
 *
 * $dryRun=true в конфиге — не писать в Bitrix, только логировать намерения.
 */

/**
 * @var $bitrix_root string корень установки Bitrix
 * @var $dataSrc_arms array дескриптор источника ARMS
 * @var $dryRun bool не писать в Bitrix
 */

// Подключаем битрикс фреймворк
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
//загружаем конфигурацию
require_once "config.php";

$_SERVER ["DOCUMENT_ROOT"] = $bitrix_root;
require ($_SERVER ["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

// Будем работать с информационными блоками
CModule::IncludeModule('iblock');

//подключаем библиотеку с объектами
require_once "libs/Abstract/COrgStructureStorage.php";
require_once "libs/OrgStruct.php";
require_once "libs/Bitrix/CBxUserList.php";
require_once "libs/Bitrix/CBxAbsenceList.php";

/*
 * Предохранитель от массового сноса графика: если выгрузка ARMS дошла неполной
 * (упал API на середине, съехало сопоставление пользователей), обратный проход
 * вычистил бы портал. Доля элементов, которую разрешено удалить за один прогон.
 */
define('ABSENCE_MAX_REMOVE_SHARE', 0.2);

echo "\n";
ob_flush();
echo date('c')." Script started\n";

$dataSrc=$dataSrc_arms;

//сотрудники нужны для сопоставления отсутствия с пользователем портала
$armsUsers=initUserList($dataSrc);
ob_flush();
$armsAbsents=initAbsenceList($dataSrc);
ob_flush();

//предохранитель: обрезанный источник — это массовое удаление на обратном проходе
if (!is_array($armsUsers->getIds()))		die ("Fail loading ARMS users");
if (count($armsUsers->getIds())<250)		die ("Fail loading ARMS users suspected (".count($armsUsers->getIds()).")");
if (!is_array($armsAbsents->getIds()))		die ("Fail loading ARMS absences");
if (count($armsAbsents->getIds())<100)		die ("Fail loading ARMS absences suspected (".count($armsAbsents->getIds()).")");

echo "Initializing BX users\n";
$bxUsers = new CBxUserList();
$bxUsers->attachSap($armsUsers);

echo "Initializing BX absences\n";
$bxAbsents = new CBxAbsenceList();

//режим dry-run: не пишем в Bitrix, только логируем намеренные изменения
if (!empty($dryRun)) {
	echo "*** DRY-RUN: запись в Bitrix отключена, изменения только логируются ***\n";
	$bxAbsents->readOnly = true;
}
ob_flush();

//карта "сотрудник ARMS -> пользователь портала" (по хешу СНИЛС и табельному)
echo "Matching users\n";
$matched=$armsAbsents->buildUserMap($bxUsers,$armsUsers);
echo "  сопоставлено пользователей портала: $matched из ".count($bxUsers->getIds())."\n";

//отсутствия сотрудников, которых в портале нет, не выгружаем
$dropped=$armsAbsents->filterByUserMap();
echo "  отсутствий без пользователя портала: $dropped, к выгрузке: ".count($armsAbsents->getIds())."\n";
if (!count($armsAbsents->getIds())) die ("Нечего выгружать: ни одно отсутствие не привязалось к пользователю портала\n");
ob_flush();

// ****** инициализация завершена ******
// далее вносим изменения в портал

echo "ARMS>Bitrix check\n";
$created=0; $updated=0; $ok=0; $skipped=0; $failed=0;
foreach ($armsAbsents->getIds() as $sapID) {
	$fields=$armsAbsents->bxGenFields($sapID,CBxAbsenceList::$AbsenceIBlockId);

	//причина, по которой запись выгрузить нельзя (нет пользователя, тип, даты)
	if (!is_array($fields)) {
		echo "$sapID: skip ($fields)\n";
		ob_flush();
		$skipped++;
		continue;
	}

	$bxID=$bxAbsents->findBySapId($sapID);

	//нет в портале — заводим
	if (is_null($bxID)) {
		if ($bxAbsents->addItem($fields)) {
			$created++;
		} else {
			$failed++;
		}
		ob_flush();
		continue;
	}

	//есть — сверяем поля и правим только при отличиях
	$diff=$bxAbsents->diffItem($bxID,$fields);
	if (!count($diff)) {
		$ok++;
		continue;
	}

	echo "$sapID (#$bxID): ".implode('; ',$diff)."\n";
	if ($bxAbsents->updItem($bxID,$fields)) $updated++; else $failed++;
	ob_flush();
}

echo "  создано $created, обновлено $updated, без изменений $ok, пропущено $skipped, ошибок $failed\n";

echo "Bitrix>ARMS check\n";

/*
 * Обратный проход: элементы, заведённые синхронизацией (у них заполнен SAP_ID),
 * которых больше нет в выгрузке ARMS. Элементы без SAP_ID — ручные записи
 * портала — не трогаем.
 */
$toRemove=array();
foreach ($bxAbsents->getSapIds() as $sapID)
	if (!$armsAbsents->exist($sapID)) $toRemove[]=$sapID;

$known=count($bxAbsents->getSapIds());
$share=$known?count($toRemove)/$known:0;
echo "  к удалению ".count($toRemove)." из $known (".round($share*100,1)."%)\n";

if ($share>ABSENCE_MAX_REMOVE_SHARE) {
	echo "  СЛИШКОМ МНОГО: порог ".(ABSENCE_MAX_REMOVE_SHARE*100)."%, удаление пропущено.\n";
	echo "  Похоже на неполную выгрузку ARMS или съехавшее сопоставление пользователей — разберитесь до следующего прогона.\n";
} else {
	$removed=0; $delFailed=0;
	foreach ($toRemove as $sapID) {
		$bxID=$bxAbsents->findBySapId($sapID);
		if (is_null($bxID)) continue;
		echo "$sapID (#$bxID): в ARMS больше нет, удаляем\n";
		if ($bxAbsents->delItem($bxID)) $removed++; else $delFailed++;
		ob_flush();
	}
	echo "  удалено $removed, ошибок удаления $delFailed\n";
}

echo date('c')." Script complete\n";

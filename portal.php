#!/usr/bin/php
<?php
/*
 * Выгрузка оргструктуры и пользователей из инвентаризации ARMS -> Bitrix.
 * Выполняется из оболочки на хосте Bitrix, запускать из корня репозитория.
 *
 * Источник — REST API ARMS ($dataSrc_arms в конфиге), какие организации
 * выгружать — список org_id в $arms_api['orgs']. Подразделения сопоставляются
 * по CODE Bitrix == "{org_id}-{hr_id}" ARMS, пользователи — по табельному
 * (XML_ID) / хешу СНИЛС (UF_INN) / ФИО.
 *
 * Режимы:
 *   php portal.php                 # вся оргструктура и все пользователи
 *   php portal.php photos         # то же + фотографии (портреты из сканов ARMS)
 *   php portal.php ivanov.i       # один пользователь по LOGIN (без прохода по структуре)
 *   php portal.php ivanov.i photos # один пользователь вместе с фотографией
 *
 * Фотографии: портрет сотрудника — последний по дате скан ARMS с именем
 * photo-{pernr}-{дата} (их заливает приём inventory-arms.php photos). Дата из
 * имени сверяется с UF_SAP_PHOTO_SYNC пользователя портала, байты качаются
 * только при расхождении и пишутся в PERSONAL_PHOTO.
 *
 * $dryRun=true в конфиге — не писать в Bitrix, только логировать намерения.
 */

/**
 * @var string $bitrix_root  корень установки Bitrix
 * @var array $dataSrc_arms дескриптор источника ARMS
 * @var bool $dryRun не писать в Bitrix
 * @var array $grpListAll группы, где должны быть все сотрудники
 * @var array $managersTitles ключевые слова должностей руководителей
 * @var array $grpListManagers группы руководителей
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
require_once "libs/Bitrix/CBxGroupList.php";
require_once "libs/Bitrix/CBxUserList.php";
require_once "libs/Bitrix/CBxOrgStructure.php";

echo "\n";
ob_flush();
echo date('c')." Script started\n";

$dataSrc=$dataSrc_arms;

$armsOrg=initOrgStructure($dataSrc);
ob_flush();
$armsUsers=initUserList($dataSrc);
ob_flush();
//организации без оргструктуры: их сотрудников — на синтетический корень организации
if ($moved=$armsUsers->applyOrgRoots($armsOrg->getOrgRoots()))
	echo "Сотрудников поставлено на корни организаций без оргструктуры: $moved\n";
$armsOrg->attachUsers($armsUsers);

//предохранитель: пустой или обрезанный источник массово деактивирует пользователей портала
if (!is_array($armsOrg->getIds()))	die ("Fail loading ARMS orgStruct");
if (count($armsOrg->getIds())<50)	die ("Fail loading ARMS orgStruct suspected (".count($armsOrg->getIds()).")");
if (!is_array($armsUsers->getIds()))	die ("Fail loading ARMS users");
if (count($armsUsers->getIds())<250)	die ("Fail loading ARMS users suspected (".count($armsUsers->getIds()).")");

echo "Initializing BX structure\n";
$bxOrg= new CBxOrgStructure();

ob_flush();
echo "Initializing BX groups\n";
$bxGrp = new CBxGroupList ();
$bxUsers = new CBxUserList();

//режим dry-run: не пишем в Bitrix, только логируем намеренные изменения
if (!empty($dryRun)) {
	echo "*** DRY-RUN: запись в Bitrix отключена, изменения только логируются ***\n";
	$bxOrg->readOnly = true;
	$bxUsers->readOnly = true;
}

//режим photos: дополнительно синхронизировать фотографии из сканов ARMS
$withPhotos = in_array('photos', $argv);

//счётчик ошибок прогона для флажка мониторинга (zabbix, $portalErrorsFile)
$syncErrors = 0;

ob_flush();

//подключаем базы друг к другу
$armsOrg->attachBx($bxOrg);
$armsUsers->attachBxOrg($bxOrg);
$armsUsers->attachSapOrg($armsOrg);
$bxUsers->attachSap($armsUsers);


// ****** инициализация завершена ******
// далее вносим изменения в портал
echo "Checking Groups\n";

//ищем идентификаторы групп для всеобщего включения
foreach ( $grpListAll as $grp )
	if (is_null ( $grpListAll_id [] = $bxGrp->findIdBy ( 'NAME', $grp ) ))
		die ( "Can't find group $grp" );

//ищем идентификаторы групп в которые включить менеджеров
foreach ( $grpListManagers as $grp )
	if (is_null ( $grpListManagers_id [] = $bxGrp->findIdBy ( 'NAME', $grp ) ))
		die ( "Can't find group $grp" );

//если мы делаем полный прогон (либо без аргументов, либо аргумент - добавить синхронизацию фото)
if ($argc==1 || $argv[1]=='photos') {
	echo "Checking structure\n";

	/*
	 * пробегает по всей оргструктуре ARMS и выстраивает подразделения в портале.
	 * недостающие подразделения добавляются (цепочкой от корня). Идентификация
	 * по полю CODE со стороны Битрикс == неймспейс-коду {org_id}-{hr_id} ARMS
	 */
	foreach ($armsOrg->getIds() as $armsID) {
		$unitName = $armsOrg->getItemField($armsID, 'Orgtx', 0);
		$users = $armsOrg->getActiveUsersRecursive($armsID);
		$wcount = count($users);

		echo "$armsID ($unitName): " .
			str_pad('', 40 - mb_strlen($unitName)) .
			str_pad("$wcount", 3, ' ', STR_PAD_LEFT) .
			" users : ";

		/*
		 * узлы без активных сотрудников, которых ещё нет в портале, не создаём:
		 * в ARMS есть ручная оргструктура для нужд инвентаризации (техника,
		 * помещения) — ей в портале делать нечего. Существующие секции при этом
		 * обслуживаются как раньше (включая деактивацию опустевших)
		 */
		if (!$wcount && is_null($armsOrg->findBxSection($armsID))) {
			echo "skip (пустое, в портале нет)\n";
			ob_flush();
			continue;
		}

		if ($armsOrg->ckBxItemFields($armsID)) {
			echo 'OK';
		} else {
			echo 'Err';
			$syncErrors++;
		}
		echo "\n";
		ob_flush();
	}
}

echo "Checking users\n";

/*
 * Пробегает по всем пользователям Битрикс (только тем, что уже есть в битриксе).
 * Новые пользователи в этот пул попадают из АД, не из этой синхронизации.
 * Пользователи, не найденные в ARMS, деактивируются (если были активны).
 */

if ($argc>1 && $argv[1]!='photos') {							//если нам передан аргумент и это не photos
	$userId=$bxUsers->findIdBy('LOGIN',$argv[1]);				//значит обрабатываем одного пользователя
	if (!$userId) die('User '.$argv[1].' not found in Bitrix');
	$userIds=[$userId];
} else {														//иначе грузим всех
	$userIds=$bxUsers->getIds();
}

foreach ($userIds as $bxID) {
	$prefx=str_pad($bxID,5).' <bx: '.str_pad($bxUsers->getItemField($bxID,'LOGIN'),20).":arms> ";

	//ищем сотрудника в ARMS (табельник / хеш СНИЛС / ФИО)
	if (!($armsID=$bxUsers->getSapID($bxID))) {
		/*
		 * Нет такого сотрудника:
		 * если он активирован в портале - отключаем
		 * если не активен - просто пропускаем молча
		 */
		if ($bxUsers->getItemField($bxID,'ACTIVE')=='Y') {
			echo $prefx."No ARMS ID - Deactivating...\n";
			$bxUsers->updItem($bxID,['ACTIVE'=>'N','XML_ID'=>'']);
		}

		continue; //пропускаем всех кого в ARMS нет
	}

	//выводим биртикс ИД, логин, ARMS ИД
	$prefx.=str_pad($armsID,12);

	//проверка полей (ACTIVE, UF_DEPARTMENT; прочее приходит из АД)
	$fieldsOk=$bxUsers->correctItem($bxID);
	if (!$fieldsOk) $syncErrors++;
	$prefx.='Fields - '.str_pad(($fieldsOk?'OK ':'Err'),3).'  ';

	//проверяем наличие пользователя в группе в которой должны быть все
	$grpOk=CBxUserList::fixUserGroups($bxID, $grpListAll_id);
	if (!$grpOk) $syncErrors++;
	$prefx.='All grp - '.str_pad(($grpOk?'OK ':'Err'),3)."  ";

	/*
	 * Фотографии (режим photos): портрет из сканов ARMS.
	 * Актуальность определяется датой из имени скана против UF_SAP_PHOTO_SYNC,
	 * байты качаются только при расхождении
	 */
	if ($withPhotos) {
		$bxStamp = $bxUsers->getItemField($bxID, 'UF_SAP_PHOTO_SYNC');
		$portrait = $armsUsers->getPortrait($armsID);
		//отметки недостаточно — фото должно ещё и существовать: отметку мог
		//оставить легаси-механизм, а фото потом удалили из портала
		$hasPhoto = strlen($bxUsers->getItemField($bxID, 'PERSONAL_PHOTO')) > 0;
		if (is_null($portrait)) {
			$photoStatus = 'ARMS missing';
		} elseif ($portrait['date'] == $bxStamp && $hasPhoto) {
			$photoStatus = 'OK';
		} elseif (!empty($dryRun)) {
			echo "[DRY-RUN] пользователь #$bxID: обновил бы фото (скан #".$portrait['id'].", ".$portrait['date'].
				", в портале '".$bxStamp."'".($hasPhoto ? "" : ", фото в портале нет").")\n";
			$photoStatus = 'would update';
		} else {
			$blob = $armsUsers->downloadScan($portrait['id']);
			if (is_null($blob) || !strlen($blob)) {
				$photoStatus = 'ERROR';
				$syncErrors++;
			} else {
				//пишем во временный файл с осмысленным именем: Bitrix проверяет
				//картинку и по расширению имени тоже
				$pernr = $armsUsers->getItemField($armsID, 'Pernr');
				$tmp = sys_get_temp_dir().'/photo-'.$pernr.'-'.$portrait['date'].'.'.$portrait['format'];
				file_put_contents($tmp, $blob);
				$arFile = CFile::MakeFileArray($tmp);
				$arFile['name'] = 'photo-'.$pernr.'-'.$portrait['date'].'.'.$portrait['format'];
				$arFile['MODULE_ID'] = 'main';
				//старое фото удаляем, как делал легаси-механизм
				$oldPhoto = $bxUsers->getItemField($bxID, 'PERSONAL_PHOTO');
				if (strlen($oldPhoto)) {
					$arFile['del'] = 'Y';
					$arFile['old_file'] = $oldPhoto;
				}
				$bxUsers->updItem($bxID, array(
					'PERSONAL_PHOTO'    => $arFile,
					'UF_SAP_PHOTO_SYNC' => $portrait['date'],
				));
				unlink($tmp);
				//критерий успеха — перечитанная отметка совпала (updItem перезагружает запись);
				//сверять PERSONAL_PHOTO бессмысленно: Bitrix кладёт туда id нового файла
				if ($bxUsers->getItemField($bxID, 'UF_SAP_PHOTO_SYNC') == $portrait['date']) {
					$photoStatus = 'updated';
				} else {
					$photoStatus = 'ERROR';
					$syncErrors++;
				}
			}
		}
		$prefx .= 'Photo - '.str_pad($photoStatus, 14).' ';
	}

	//проверяем наличие пользователя в группах для руководителей
	//пробегаемся по ключевым словам должностей руководителей и ищем их в должности обрабатываемого пользователя
	$manager=false;
	foreach ($managersTitles as $title)	if (false!==strpos(mb_strtolower($bxUsers->getItemField($bxID, 'WORK_POSITION')),$title))
		$manager=true;
	if ($manager) {
		$mgrOk=CBxUserList::fixUserGroups($bxID, $grpListManagers_id);
		if (!$mgrOk) $syncErrors++;
		$mgr_prfx="Manger grp - ".($mgrOk?'OK ':'Err');
	} else
		$mgr_prfx='';
	$prefx.=str_pad($mgr_prfx,18);

	//выводим подразделение
	$prefx.='Dep: '.str_pad($bxUsers->getItemField($bxID,'UF_DEPARTMENT')[0],6);

	echo $prefx;

	//если обнаружены дубликаты пользователя - сообщаем об этом (возникают при импорте с разных доменов)
	if (count($dupes=$bxUsers->findDupes($bxID))&&($dupes[0]>$bxID)) echo ' Dupes found ['.	implode(',',$dupes).'] ';

	echo "\n";
	ob_flush();
}

//флажок ошибок для zabbix — только полные прогоны: запуск по одному пользователю
//(отладка) не должен переписывать результат последнего полного
if ($argc==1 || $argv[1]=='photos')
	reportSyncErrors(isset($portalErrorsFile)?$portalErrorsFile:'', $syncErrors);

echo date('c')."Script complete\n";

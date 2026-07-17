#!/usr/bin/php
<?php
/*
 * Скрипт синхронизации оргструктуры и пользователей из БД инвентаризации -> Bitrix
 * выполняется из оболочки, а не через веб
 *
 * v1.5 - Добавлена работа с файлами (Загрузка фото)
 * v1.4 - Корректировка вывода информации
 * v1.3 - Рефакторинг и небольшие багфиксы (после того как портал пришлось откатить изза потертых групп)
 * v1.2 - Вносит пользователей в группы
 * v1.1 - Создает полностью дерево оргструктуры если каких-то узлов нет
 * 			раскладывает пользователей по узлам
 * v1.0 - Перемещает узлы оргструктуры, если идентифицирует ищ по ID из САП
 */

/**
 * @var $bitrix_root string
 * @var $dataSrc_SAP array
 * @var $grpListAll array
 * @var $managersTitles array
 * @var $grpListManagers array
 * @var $factories array
 * @var $sapCacheDir string
 * @var $sapPhotoCache string
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
require_once "libs/Abstract/CUserPhotoStruct.php";

echo "\n";
ob_flush();
echo date('c')." Script started\n";


$dataSrc=$dataSrc_SAP;
$org_id=$dataSrc['org_id'];
$sapOrgDict=$factories;


echo "Refreshing SAP cache ...\n";
ob_flush();
system("$sapCache");


$sapOrg=initOrgStructure($dataSrc);
ob_flush();
$sapUsers=initUserList($dataSrc);
ob_flush();
$sapOrg->attachUsers($sapUsers);


//проверяем что данне из SAP загрузились (иначе начинает выключать всех пользователей как уволенных)
if (!is_array($sapOrg->getIds()))	die ("Fail loading SAP orgStruct");
if (count($sapOrg->getIds())<50)	die ("Fail loading SAP orgStruct suspected (".count($sapOrg->getIds()).")");
if (!is_array($sapUsers->getIds()))	die ("Fail loading SAP users");
if (count($sapUsers->getIds())<250)	die ("Fail loading SAP users suspected (".count($sapUsers->getIds()).")");

//var_dump($sapUsers);
//включаем вывод всех ошибок
//error_reporting(E_ALL);

echo "Initializing BX structure\n";
$bxOrg= new CBxOrgStructure();
//$bxOrg->readOnly=true;
//print_r($bxOrg->data);

ob_flush();
echo "Initializing BX groups\n";
$bxGrp = new CBxGroupList ();
$bxUsers = new CBxUserList();
//$bxUsers->readOnly=true;

ob_flush();

//подключаем базы друг к другу
$sapOrg->attachBx($bxOrg);
$sapUsers->attachBxOrg($bxOrg);
$sapUsers->attachSapOrg($sapOrg);
$bxUsers->attachSap($sapUsers);


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


//die ( "dont't touch anything!\n" );

if ($argc==1 || $argv[1]=='photos') {
	echo "Checking structure\n";

	/*
	 * пробегает по всей оргструктуре SAP и выстраивает подразделения в портале
	 * в соответствии с SAP. недостающие подразделения добавляются. Идентификация подразделения
	 * производится по полю CODE со стороны Битрикс и Objid со стороны SAP
	 */
	foreach ($sapOrg->getIds() as $sapID) {
		$unitName = $sapOrg->getItemField($sapID, 'Orgtx', 0);
		$users = $sapOrg->getActiveUsersRecursive($sapID);
		$wcount = count($users);

		echo "$sapID ($unitName): " .
			str_pad('', 40 - mb_strlen($unitName)) .
			str_pad("$wcount", 3, ' ', STR_PAD_LEFT) .
			" users : ";
		echo $sapOrg->ckBxItemFields($sapID) ? 'OK' : 'Err';
		echo "\n";
		ob_flush();
	}
}

echo "Checking users\n";

/*
 * Пробегает по всем активным пользователям Битрикс (только тем, что уже есть в битриксе).
 * Новые пользователи в этот пул попадут из АД, не из этой синхронизации
 * Если какой-то пользователь из нужных окажется неактивен - он активируется при следующем его входе в портал и попадет под синхронизацию
 */

if ($argc>1 && $argv[1]!='photos') {
    $userId=$bxUsers->findIdBy('LOGIN',$argv[1]);
    if (!$userId) die('User '.$argv[1].' not found in Bitrix');
    $userIds=[$userId];
} else {
    $userIds=$bxUsers->getIds();
}

foreach ($userIds as $bxID) {
	$prefx=str_pad($bxID,5).' <bx: '.str_pad($bxUsers->getItemField($bxID,'LOGIN'),20).":sap> ";

	//ищем пользователя в сап по ID (табельник)
	if (!($sapID=$bxUsers->getSapID($bxID))) {
		/* 
		 * Нет такого пользователя
		 * если он активирован с портале - отключаем
		 * если его нет в сапе и он не активен в портале - просто пропускаем молча
		 */

		//echo '?';ob_flush();
		if ($bxUsers->getItemField($bxID,'ACTIVE')=='Y') {
			echo $prefx."No SAP ID ($sapID) - Deactivating...\n";
			$bxUsers->updItem($bxID,['ACTIVE'=>'N','XML_ID'=>'']);
		}
		
		continue; //пропускаем всех кого в сапе нет
	}


	
	/*
	 * Если пользователь в сап есть, то проверяется соответствие полей
	 * - Должность
	 * - Отдел
	 * - XML_ID (соответствует табельному номеру из SAP)
	 * - Подразделение (правка этого поля меняет положение пользователя в оргструктуре)
	 */

	//выводим биртикс ИД, логин, Сап ИД
	$prefx.=str_pad($sapID,12);

	//echo '2';ob_flush();

	
	
	//проверка полей
	$prefx.='Fields - '.str_pad(($bxUsers->correctItem($bxID)?'OK ':'Err'),3).'  ';


	//echo '3';ob_flush();

	
	//проверяем наличие пользователя в группе в которой должны быть все
	$prefx.='All grp - '.str_pad(((CBxUserList::fixUserGroups($bxID, $grpListAll_id))?'OK ':'Err'),3)."  ";


	//echo '4';ob_flush();

	/*
	 * Работа с фотографиями -
	 */
    if (array_search('photos',$argv)!==false){
        $bxPhotoTimestamp=$bxUsers->getItemField($bxID,'UF_SAP_PHOTO_SYNC');
        $sapPhotoTimestamp=$sapUsers->getItemField($sapID,'PhotoDate');

        if ($sapPhotoTimestamp=='0000-00-00') {
            $photoStatus='SAP missing';
        } elseif ($sapPhotoTimestamp==$bxPhotoTimestamp) {
            $photoStatus='OK';
        } else {
            //в сапе есть и штамп не совпадает
			$cache=$sapCacheDir.'YfmGetPersPhoto.'.$sapID.'.json';
			//echo " $sapPhotoCache $sapID $cache ";
			system("$sapPhotoCache $sapID $cache");
            $userPhoto = new CUserPhotoStruct();
            $userPhoto->loadFromJson($cache);
            if ($userPhoto->getTimeStamp()>0) {
                //тут мы выяснили что в сапе есть данные по фотографии сотрудника
                $userPhoto->loadBxData($bxID);
                $userPhoto->updateBxData($sapID,$bxID,$bxUsers);
                $photoStatus='updated ';
            } else {
				$photoStatus='ERROR';
            }
            unset($userPhoto);
        }
		$prefx.= 'Photo - '.str_pad($photoStatus,12).'  ';
	}


	//проверяем наличие пользователя в группах для руководителей
	//пробегаемся по ключевым словам должностей руководителей и ищем их в должности обрабатываемого пользователя
	$manager=false;
	foreach ($managersTitles as $title)	if (false!==strpos(mb_strtolower($bxUsers->getItemField($bxID, 'WORK_POSITION')),$title)) 
		$manager=true;
	if ($manager) 
		$mgr_prfx="Manger grp - ".(CBxUserList::fixUserGroups($bxID, $grpListManagers_id)?'OK ':'Err');
	else 
		$mgr_prfx='';
	$prefx.=str_pad($mgr_prfx,18);

	//echo '6';ob_flush();

	//выводим подразделение	
	$prefx.='Dep: '.str_pad($bxUsers->getItemField($bxID,'UF_DEPARTMENT')[0],6);

	//echo '7';	ob_flush();
	//выводим список идентификаторов групп пользователя
	//$prefx.='Grps: ['.implode(',',CBxUserList::getUserGroups($bxID))."]";

	//echo '8';echo "\n";	ob_flush();
	//echo "\n";
	
	echo $prefx;
	//ob_flush();

	//если обнаружены дубликаты пользователя - сообщаем об этом (возникают при импорте с разных доменов)
	if (count($dupes=$bxUsers->findDupes($bxID))&&($dupes[0]>$bxID)) echo ' Dupes found ['.	implode(',',$dupes).'] ';

	
	echo "\n";
	ob_flush();
}



//теперь проходим по оргуструктуре в портале и ищем подразделения, где есть неуволенные пользователи
//не нужно, т.к. обходим выше на основании SAP с учетом вложенных подразделений
/*
foreach ($bxOrg->getIds() as $depID) {
	$depUsers=$bxUsers->findIdsBy([
		'UF_DEPARTMENT'=>$depID,
		'ACTIVE'=>'Y'
	]);
	$depActive=count($depUsers)?'Y':'N';
	$bxOrg->updItem($depID,['ACTIVE'=>$depActive]);
	echo $depID.':'.count($depUsers).":$depActive\n";
}

*/

echo date('c')."Script complete\n";
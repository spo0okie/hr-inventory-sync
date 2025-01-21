#!/usr/bin/php
<?php
// Подключаем битрикс фреймворк
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

$_SERVER ["DOCUMENT_ROOT"] = '/home/bitrix/www';
@require ($_SERVER ["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
// Будем работать с информационными блоками
CModule::IncludeModule('iblock');

require __DIR__."/libs/Abstract/COrgStructureStorage.php";
require __DIR__."/libs/SAP/CSapAbsentsStructure.php";
require __DIR__."/config.php";


error_reporting(E_WARNING);

echo date('c')." Script started\n";

echo ("Loading SAP data...");
system($sapAbsenceCache);


echo "Initializing SAP structure\n";
$sapAbsents = new CSapAbsentsStructure();
$sapAbsents->loadFromJson($sapAbsenceFile);
if (!is_array($sapAbsents->getIds()) || count ($sapAbsents->getIds())<100) 
	die("Error loading SAP data suspected");


// ****** инициализация завершена ******
// далее вносим изменения в портал


//var_dump($sapAbsents);


echo "Checking absence objects\n";
 /*
  * пробегает по всей оргструктуре SAP и выстраивает подразделения в портале
  * в соответствии с SAP. недостающие подразделения добавляются. Идентификация подразделения
  * производится по полю CODE со стороны Битрикс и Objid со стороны SAP
  */

$Item=new CIBlockElement;
echo "SAP>Bitrix check\n";
foreach ($sapAbsents->getIds() as $sapID) {
	$data=$sapAbsents->bxGenFields($sapID);
	if (is_array($data)) {
		//пропускаем те отсутствия у которых не заполнилось наименование - которые отсутствуют в списке согласованных типов
		if (strlen($data['NAME'])===0) {
			echo "$sapID: ERR: Name missing!\n";
			ob_flush();
		    continue;
		}
 			
		//ищем иже внесенные данные
		$search=CIBlockElement::GetList([],['IBLOCK_ID'=>3,'PROPERTY_SAP_ID'=>$sapID]);
		if ($found=$search->GetNext()) 	{
			echo "$sapID: OK>\n";
			ob_flush();
		    continue;
		}
		
		//Тут вносим если не нашли
		if ($newID=$Item->Add($data)) {
			echo "$sapID: added $newID\n";
			ob_flush();
			continue;
		} else {
			echo "$sapID: addition error: ".$Item->LAST_ERROR."\n";
			ob_flush();
			continue;
		}
			
		echo "$sapID: skipped\n";
		ob_flush();
	} else {
		echo "$sapID: ERR: $data\n";
		ob_flush();
	}
}

echo "Bitrix>SAP check\n";
$search=CIBlockElement::GetList([],['IBLOCK_ID'=>3,'!PROPERTY_ABSENCE_TYPE'=>9],false,['nTopCount'=>10000000],['*','PROPERTY_ABSENCE_TYPE','PROPERTY_SAP_ID']);
while ($item=$search->GetNextElement()) {
	//echo "exist ".$Item['ID']."\n";
	$id=$item->GetFields()['ID'];
	$sapID=$item->GetProperty('SAP_ID')['VALUE'];
	echo "$sapID: ";
	//var_dump($item);
	if (!$sapAbsents->exist($sapID)) {
		echo "$id [$sapID]: SAP missing, removing in BX ... ";
		//var_dump($item);
		$DB->StartTransaction();
		if(!CIBlockElement::Delete($id))
		{
			echo "ERR\n";
			ob_flush();
			$DB->Rollback();
		}
		else {
			echo "<OK\n";
			ob_flush();
			$DB->Commit();
		} //*/
	} else echo "<OK\n";
}

echo date('c')."Script complete\n";

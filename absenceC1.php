#!/usr/bin/php
<?php
// Подключаем битрикс фреймворк
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

require "config.php";

$_SERVER ["DOCUMENT_ROOT"] = $bitrix_root;
require ($_SERVER ["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

// Будем работать с информационными блоками
CModule::IncludeModule('iblock');

require "libs/COrgStructureStorage.php";
require "libs/CC1AbsentsStructure.php";

//включаем вывод всех ошибок
error_reporting(E_WARNING);


echo date('c')." Script started\n";

foreach ($inventory_import as $dataSrc) {

	echo "Initializing SAP structure\n";
	$sapAbsents = new CC1AbsentsStructure();
	$sapAbsents->loadFromCSV($dataSrc['abs']['path'], ['']);
	$sapAbsents->org_id=$dataSrc['org_id'];


	// ****** инициализация завершена ******
	// далее вносим изменения в портал


	echo "Checking absence objects\n";
	/*
	 * пробегает по всей оргструктуре SAP и выстраивает подразделения в портале
	 * в соответствии с SAP. недостающие подразделения добавляются. Идентификация подразделения
	 * производится по полю CODE со стороны Битрикс и Objid со стороны SAP
	 */

	$Item = new CIBlockElement;
	echo "SAP2Bitrix check\n";

	//Перебираем отсутствия
	foreach ($sapAbsents->getIds() as $sapID) {
		$data = $sapAbsents->bxGenFields($sapID);
		if (is_array($data)) {
			//пропускаем те отсутствия у которых не заполнилось наименование - которые отсутствуют в списке согласованных типов
			if (strlen($data['NAME']) === 0) continue;

			//ищем иже внесенные данные
			$search = CIBlockElement::GetList([], ['IBLOCK_ID' => 3, 'CODE' => $sapID]);
			if ($found = $search->GetNext()) {
				echo "$sapID: exist\n";
				//var_dump($found);
				$Item->Update(
					$found['ID'],
					$data
				);
				continue;
			}

			echo "$sapID: addin ... ";
			//var_dump($data);

			//Тут вносим если не нашли
			if ($newID = $Item->Add($data)) {
				echo "added $newID\n";
				ob_flush();
				//exit;
				continue;
			} else {
				echo " addition error: " . $Item->LAST_ERROR . "\n";
				ob_flush();
				//exit;
				continue;
			}

			echo "$sapID: skipped\n";
		} else {
			echo "$sapID: Unknown user " . $sapAbsents->getItemField($sapID, 'Pernr') . "\n";
		}
		ob_flush();
	}
	/*exit();
	echo "Bitrix2SAP check\n";
	$search = CIBlockElement::GetList([], ['IBLOCK_ID' => 3, '!PROPERTY_SAP_ID' => false]);
	while ($item = $search->GetNextElement()) {
		//echo "exist ".$Item['ID']."\n";
		$id = $item->GetFields()['ID'];
		$sapID = $item->GetProperty('SAP_ID')['VALUE'];
		echo "";
		if (!$sapAbsents->exist($sapID)) {
			echo "$id [$sapID]: SAP missing, removing in BX ... ";
			$DB->StartTransaction();
			if (!CIBlockElement::Delete($id)) {
				echo "ERR\n";
				$DB->Rollback();
			} else
				echo "OK\n";
			$DB->Commit();
		}
	}*/
	unset ($sapAbsents);
	unset ($Item);
}

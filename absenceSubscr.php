#!/usr/bin/php
<?php

/* 
 * файл обработчик рассылки подписки на отсутствия сотудников
 */


// Подключаем битрикс фреймворк
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

$_SERVER ["DOCUMENT_ROOT"] = '/home/bitrix/www';
@require ($_SERVER ["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
// Будем работать с информационными блоками
CModule::IncludeModule('iblock');

require __DIR__."/config.php";


//включаем вывод всех ошибок
error_reporting(E_WARNING);



echo date('c')." Script started\n";


// ****** инициализация завершена ******
// далее вносим изменения в портал




echo "Checking absence objects\n";
/*
 * пробегает по всей оргструктуре SAP и выстраивает подразделения в портале
 * в соответствии с SAP. недостающие подразделения добавляются. Идентификация подразделения
 * производится по полю CODE со стороны Битрикс и Objid со стороны SAP
 */



$search_by=[
    'IBLOCK_SECTION_ID'=>false,			//кладем в корень инфоблока, без каких-л секций
    'IBLOCK_ID'=>3,						//вообще то он третий, но может стоит его выдергивать по явным признакам блока оргструктуры
    'ACTIVE'=>'Y',						//ищем только среди активных
	['LOGINC'=>'AND',					//границы понятное дело
    	['>=DATE_ACTIVE_FROM'=>date('d.m.Y 00:00:00',time()+86400*2)],
    	['<DATE_ACTIVE_FROM'=>date('d.m.Y 00:00:00',time()+86400*3)],
	],
    'PROPERTY_USER'=>0,		//ссылка на пользователя чей отпуск
];

$read_fields=['ID','DATE_ACTIVE_FROM','DATE_ACTIVE_TO','NAME','PROPERTY_USER'];
//print_r($fields);

echo "Searching users\n";
$usrList=CUser::GetList($by,$sort,[],['SELECT'=>['UF_ABSENCE_SUBSCR']]);
while($ob = $usrList->GetNext(false,false)) if (is_array($ob['UF_ABSENCE_SUBSCR'])&&strlen($ob['EMAIL'])){
	//echo $ob['ID'].">>\n";
    $notice='';
    foreach ($ob['UF_ABSENCE_SUBSCR'] as $item) {
		//echo "$item\n";
        $search_by['PROPERTY_USER']=$item;
        $search=CIBlockElement::GetList([],$search_by,false,false,$read_fields);
        if ($found=$search->GetNext()) {
            $usr=CUser::GetList($by,$sort,['ID'=>$item])->Fetch();
            //print_r($found);
            //print_r($usr);
            $notice.="Послезавтра у сотрудника ${usr['LAST_NAME']} ${usr['NAME']} начинается ${found['NAME']} (${found['DATE_ACTIVE_FROM']} - ${found['DATE_ACTIVE_TO']})\n";
        }
    }
    if (strlen($notice)) {
		echo "Sending notice to ${ob['EMAIL']}, Напоминание о скором отсутствии ваших коллег : $notice";
		if (mail($ob['EMAIL'],'Напоминание о скором отсутствии ваших коллег',$notice,"Content-type: text/plain; charset=utf-8 \r\n")) {
			echo "sucess\n;";
		} else {
			echo "fail\n;";
		}
    }
}


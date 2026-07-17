#!/usr/bin/php
<?php

/* 
 * файл обработчик напоминания о днях рождения
 * 
 * задача - найти сотрудников у кого дни рождения на текущей неделе 
 * (сегодня и на 6 дней вперед)
 * и отправить информацию о них в службу персонала
 */


// Подключаем битрикс фреймворк
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

require __DIR__."/config.php";

$_SERVER ["DOCUMENT_ROOT"] = $bitrix_root;
@require ($_SERVER ["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
// Будем работать с информационными блоками
CModule::IncludeModule('iblock');

require __DIR__."/libs/Abstract/COrgStructureStorage.php";
require __DIR__."/libs/Bitrix/CBxUserList.php";

//включаем вывод всех ошибок
error_reporting(E_WARNING);

echo date('c')." Script started\n";

echo "Initializing BX structure\n";
$bxUsers = new CBxUserList();


echo "Getting week dates\n";
$week[]= date('d.m', strtotime('now'));
for ($day=1; $day<7; $day++) {
    $week[]= date('d.m', strtotime("+$day day"));
}

//$week=['01.01','02.01','03.01','04.01','05.01','06.01','07.01','08.01',];
echo "${week[0]} - ${week[6]}\n";


$msg='';


// ****** инициализация завершена ******


foreach ($bxUsers->getIds() as $bxID) {
    if ($bxUsers->getItemField($bxID,'ACTIVE')==='Y') {
        $bday=$bxUsers->getItemField($bxID,'PERSONAL_BIRTHDAY');
        if (strlen($bday)) {
            $bdate=explode('.',$bday);  //разбиваем на токены
            unset($bdate[2]);           //выкидываем год рождения
            $bday=implode('.',$bdate);  //собираем обратно
            //echo $bday."\n";
        }
        //проверяем что денюха в нашем массиве дат
        if (array_search($bday,$week)!==false) {    
            $user=$bxUsers->getItemField($bxID,'LAST_NAME').' '.$bxUsers->getItemField($bxID,'NAME').': '.$bxUsers->getItemField($bxID,'PERSONAL_BIRTHDAY')."\n";
            $msg .= $user;
        }
    }
}

if (!strlen($msg)) $msg='Не обнаружено дней рождения в интервале дат '.$week[0].' - '.$week[6];

echo $msg."\n";

//отправляем письмо
if (mail($bdayRemindMail,'Дни рождения на этой неделе',$msg,"Content-type: text/plain; charset=utf-8 \r\n")) {
    echo "mail sent\n";
} else {
    echo "err while sending mail\n";
}


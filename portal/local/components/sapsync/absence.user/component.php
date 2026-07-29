<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();
/*
 * Текущие и будущие отсутствия сотрудника.
 *
 * Читает график отсутствий портала (инфоблок абсанс-календаря, тот же, что
 * наполняет portal-absence.php) и отдаёт шаблону список незакончившихся записей.
 * Ничего не пишет и от синхронизации не зависит — просто витрина инфоблока.
 *
 * Синтаксис PHP 5.6: портал живёт на нём.
 *
 * Параметры:
 *   USER_ID    — чей профиль (0 = текущий пользователь)
 *   IBLOCK_ID  — инфоблок отсутствий (по умолчанию 3)
 *   COUNT      — сколько записей показывать
 *   SHOW_PAST_DAYS — сколько дней назад ещё показывать (0 = только незакончившиеся)
 *   CACHE_TIME — время кэширования
 */

if (!CModule::IncludeModule('iblock')) {
	ShowError('Не установлен модуль инфоблоков');
	return;
}

$arParams['USER_ID'] = intval($arParams['USER_ID']);
if ($arParams['USER_ID'] <= 0) $arParams['USER_ID'] = intval($GLOBALS['USER']->GetID());
if ($arParams['USER_ID'] <= 0) return;	//гостю показывать нечего

$arParams['IBLOCK_ID'] = intval($arParams['IBLOCK_ID']);
if ($arParams['IBLOCK_ID'] <= 0) $arParams['IBLOCK_ID'] = 3;
$arParams['COUNT'] = intval($arParams['COUNT']);
if ($arParams['COUNT'] <= 0) $arParams['COUNT'] = 10;
$arParams['SHOW_PAST_DAYS'] = intval($arParams['SHOW_PAST_DAYS']);
$arParams['CACHE_TIME'] = isset($arParams['CACHE_TIME']) ? intval($arParams['CACHE_TIME']) : 3600;

//граница выборки: отсутствия, которые ещё не закончились
$border = date('d.m.Y', time() - $arParams['SHOW_PAST_DAYS'] * 86400);

/*
 * В ключ кэша входит дата: выборка относительная ("ещё не закончились"), и без
 * этого вчерашний кэш показывал бы уже прошедшее отсутствие как текущее.
 */
$cacheKeys = array($arParams['USER_ID'], $arParams['IBLOCK_ID'], $arParams['COUNT'], $border);

if ($this->StartResultCache($arParams['CACHE_TIME'], $cacheKeys)) {

	//сброс кэша при правке инфоблока (в т.ч. прогоном portal-absence.php)
	global $CACHE_MANAGER;
	if (defined('BX_COMP_MANAGED_CACHE')) {
		$CACHE_MANAGER->StartTagCache($this->GetCachePath());
		$CACHE_MANAGER->RegisterTag('iblock_id_' . $arParams['IBLOCK_ID']);
	}

	/*
	 * Код типа (XML_ID варианта списка ABSENCE_TYPE) — стабильный идентификатор:
	 * ID вариантов у каждого портала свои, а XML_ID совпадают с кодами типов ARMS
	 * (VACATION, LEAVESICK, VACATION_PLAN...) и годятся для оформления.
	 */
	$typeCodes = array();
	$rsEnum = CIBlockPropertyEnum::GetList(
		array('SORT' => 'ASC'),
		array('IBLOCK_ID' => $arParams['IBLOCK_ID'], 'CODE' => 'ABSENCE_TYPE')
	);
	while ($enum = $rsEnum->GetNext(false, false))
		$typeCodes[$enum['ID']] = $enum['XML_ID'];

	$arResult['ITEMS'] = array();
	$now = time();

	//фильтр по датам активности задаётся полями DATE_ACTIVE_*, а выбирается ACTIVE_*
	$rs = CIBlockElement::GetList(
		array('ACTIVE_FROM' => 'ASC'),
		array(
			'IBLOCK_ID'          => $arParams['IBLOCK_ID'],
			'ACTIVE'             => 'Y',
			'PROPERTY_USER'      => $arParams['USER_ID'],
			'>=DATE_ACTIVE_TO'   => $border,
		),
		false,
		array('nTopCount' => $arParams['COUNT']),
		array('ID', 'NAME', 'ACTIVE_FROM', 'ACTIVE_TO', 'IBLOCK_ID', 'PROPERTY_ABSENCE_TYPE')
	);
	while ($item = $rs->GetNext(false, false)) {
		$from = MakeTimeStamp($item['ACTIVE_FROM']);
		$to = MakeTimeStamp($item['ACTIVE_TO']);
		$enumId = isset($item['PROPERTY_ABSENCE_TYPE_ENUM_ID']) ? $item['PROPERTY_ABSENCE_TYPE_ENUM_ID'] : '';

		$arResult['ITEMS'][] = array(
			'ID'         => $item['ID'],
			'NAME'       => $item['NAME'],
			'TYPE_CODE'  => isset($typeCodes[$enumId]) ? $typeCodes[$enumId] : '',
			'TYPE_NAME'  => isset($item['PROPERTY_ABSENCE_TYPE_VALUE']) ? $item['PROPERTY_ABSENCE_TYPE_VALUE'] : '',
			'FROM'       => $from,
			'TO'         => $to,
			//идёт прямо сейчас — шаблон выделяет такие
			'CURRENT'    => ($from && $from <= $now && $to && $to >= $now),
			'DAYS'       => ($from && $to) ? (floor(($to - $from) / 86400) + 1) : 0,
		);
	}

	$arResult['USER_ID'] = $arParams['USER_ID'];
	$arResult['IS_CURRENT_USER'] = ($arParams['USER_ID'] === intval($GLOBALS['USER']->GetID()));

	if (defined('BX_COMP_MANAGED_CACHE')) $CACHE_MANAGER->EndTagCache();

	$this->IncludeComponentTemplate();
}

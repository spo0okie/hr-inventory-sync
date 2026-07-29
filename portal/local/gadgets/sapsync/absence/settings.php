<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();
/*
 * Гаджет «Отсутствия» для рабочего стола профиля сотрудника.
 *
 * Страница профиля (socialnetwork.user_profile) собирается компонентом
 * bitrix:desktop из гаджетов, поэтому свой блок добавляется мышкой через
 * «Настроить», без правки шаблонов и ванильного кода.
 *
 * GROUP.ID = sonet_user — набор гаджетов страницы профиля.
 */

$arGadgetDescription = array(
	"NAME"        => "Отсутствия",
	"DESCRIPTION" => "Текущие и запланированные отсутствия сотрудника (график отсутствий портала)",
	"GROUP"       => array("ID" => "sonet_user"),
	"CACHE_TIME"  => 3600,
);

$arParameters = array(
	"PARAMETERS" => array(
		"IBLOCK_ID" => array(
			"NAME"    => "ID инфоблока отсутствий",
			"TYPE"    => "STRING",
			"DEFAULT" => "3",
		),
		"COUNT" => array(
			"NAME"    => "Сколько записей показывать",
			"TYPE"    => "STRING",
			"DEFAULT" => "5",
		),
		"SHOW_PAST_DAYS" => array(
			"NAME"    => "Показывать и закончившиеся, дней назад",
			"TYPE"    => "STRING",
			"DEFAULT" => "0",
		),
	),
);

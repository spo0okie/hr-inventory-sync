<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();
/*
 * Гаджет «Отсутствия»: тонкая обёртка над компонентом sapsync:absence.user.
 *
 * @var array $arGadgetParams настройки гаджета
 * @var CBitrixComponent|null $this
 */

/*
 * Чей профиль показан.
 *
 * Штатным гаджетам профиля desktop передаёт USER_ID параметром вида
 * G_<КОД_ГАДЖЕТА>_USER_ID, но эти параметры перечислены в шаблоне
 * socialnetwork.user_profile поимённо — стороннего гаджета там нет и быть не может
 * без правки шаблона. Поэтому определяем сами: сначала настройка гаджета, затем
 * адрес страницы профиля (/company/personal/user/<ID>/), в последнюю очередь —
 * текущий пользователь (профиль «мой» и виджеты на личных страницах).
 */
$userId = isset($arGadgetParams["USER_ID"]) ? intval($arGadgetParams["USER_ID"]) : 0;

if ($userId <= 0 && isset($_REQUEST["user_id"])) $userId = intval($_REQUEST["user_id"]);

if ($userId <= 0) {
	$page = $GLOBALS["APPLICATION"]->GetCurPage();
	if (preg_match('#/user/(\d+)/#', $page, $m)) $userId = intval($m[1]);
}

if ($userId <= 0) $userId = intval($GLOBALS["USER"]->GetID());

$GLOBALS["APPLICATION"]->IncludeComponent(
	"sapsync:absence.user",
	"",
	array(
		"USER_ID"        => $userId,
		"IBLOCK_ID"      => isset($arGadgetParams["IBLOCK_ID"]) ? $arGadgetParams["IBLOCK_ID"] : 3,
		"COUNT"          => isset($arGadgetParams["COUNT"]) ? $arGadgetParams["COUNT"] : 5,
		"SHOW_PAST_DAYS" => isset($arGadgetParams["SHOW_PAST_DAYS"]) ? $arGadgetParams["SHOW_PAST_DAYS"] : 0,
		"CACHE_TIME"     => 3600,
	),
	false,
	array("HIDE_ICONS" => "Y")
);

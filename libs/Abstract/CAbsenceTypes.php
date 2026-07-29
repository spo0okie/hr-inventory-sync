<?php
/*
 * Словарь типов отсутствия — единая точка соответствия:
 *   нормализованный код ARMS <-> ID элемента списка ABSENCE_TYPE в Bitrix <-> описание.
 *
 * Лежит в libs/Abstract и написан на синтаксисе PHP 5.6, потому что нужен обеим
 * сторонам: приёму SAP -> ARMS (хост инвентаризации, PHP 8.2) и выгрузке
 * ARMS -> Bitrix (хост портала, PHP 5.6). Держать две копии таблицы нельзя —
 * разъедутся молча, а сверять их некому.
 *
 * Группировка кодов конкретного источника (для SAP — Awart) в нормализованный
 * тип остаётся в адаптере источника (CSapAbsentsStructure::$absentsTypes),
 * здесь только сам справочник типов.
 *
 * Описание (descr) намеренно обобщённое: график отсутствий видят все сотрудники
 * портала, и текст отсутствия из источника (SAP Abstext) в него не попадает.
 * Исключение — командировки: там в comment лежит направление, сформированное
 * приёмом (CArmsIngest::tripComment), и оно и идёт в название.
 */
class CAbsenceTypes {

	/*
	 * bx    — ID элемента списка ABSENCE_TYPE (инфоблок отсутствий портала)
	 * descr — название элемента графика отсутствий в Bitrix
	 *
	 * Таблица согласована с кадровой службой — не расширять без решения пользователя.
	 */
	public static $types = array(
		'VACATION'       => array('bx' => 8,   'descr' => 'Отпуск'),
		'ASSIGNMENT'     => array('bx' => 9,   'descr' => 'Командировка'),
		'LEAVESICK'      => array('bx' => 10,  'descr' => 'Болезнь'),
		'LEAVEMATERNITY' => array('bx' => 11,  'descr' => 'Отпуск по уходу за ребенком'),
		'LEAVEUNPAYED'   => array('bx' => 12,  'descr' => 'Отгул'),
		'UNKNOWN'        => array('bx' => 13,  'descr' => 'Прогул'),
		'OTHER'          => array('bx' => 14,  'descr' => 'Другое'),
		'PERSONAL'       => array('bx' => 293, 'descr' => 'Персональный календарь'),
		//запланированный, но ещё не утверждённый отпуск (в SAP — Sprps=X)
		'VACATION_PLAN'  => array('bx' => 367, 'descr' => 'Запланированный отпуск'),
	);

	/*
	 * ID элемента списка Bitrix по коду ARMS.
	 * @param string $code код типа
	 * @return int|null null, если тип неизвестен (угадывать нельзя)
	 */
	public static function bxId($code) {
		$code = (string)$code;
		return isset(static::$types[$code]) ? static::$types[$code]['bx'] : null;
	}

	/*
	 * Обобщённое описание типа (название элемента в графике отсутствий).
	 * @param string $code код типа
	 * @return string|null
	 */
	public static function descr($code) {
		$code = (string)$code;
		return isset(static::$types[$code]) ? static::$types[$code]['descr'] : null;
	}

	/*
	 * Код ARMS по ID элемента списка Bitrix (обратное направление).
	 * @param int $bxId
	 * @return string|null
	 */
	public static function codeByBxId($bxId) {
		foreach (static::$types as $code => $t)
			if ((int)$t['bx'] === (int)$bxId) return $code;
		return null;
	}
}

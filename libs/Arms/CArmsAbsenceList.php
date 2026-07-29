<?php
require_once dirname(__FILE__) . "/CArmsRestClient.php";
require_once dirname(__FILE__) . "/../Abstract/COrgStructureStorage.php";
require_once dirname(__FILE__) . "/../Abstract/CAbsenceTypes.php";

/*
 * Отсутствия из инвентаризации ARMS для выгрузки в Bitrix (читается через REST).
 *
 * Синтаксис PHP 5.6: класс грузится portal-absence.php на хосте Bitrix.
 *
 * Ключ записи в хранилище — тот же ключ, что уходит в свойство SAP_ID элемента
 * инфоблока: для записей источника с натуральным ключом это его external_id
 * (для SAP — Pernr-Awart-Begda-Endda), для записей без него (ручной ввод в ARMS)
 * — "arms-{id}". Формат ключа записей SAP совпадает с тем, что легаси-механизм
 * прямой синхронизации SAP -> Bitrix уже записал десяткам тысяч элементов, поэтому
 * переход на выгрузку через ARMS их обновляет, а не пересоздаёт.
 *
 * Сопоставление с пользователем портала идёт не по user_id ARMS напрямую:
 * отсутствие привязано к конкретному трудоустройству, а пользователь Bitrix — к
 * человеку. Карта строится по uid (хеш СНИЛС) и табельному, см. buildUserMap.
 */
class CArmsAbsenceList extends COrgStructureStorage {

	/** @var array user_id ARMS => ID пользователя Bitrix */
	private $bxUserByArmsId = array();
	/** @var array сколько записей не удалось привязать к пользователю портала */
	private $unresolved = 0;

	/*
	 * Ключ выгрузки (он же значение свойства SAP_ID в Bitrix).
	 * @param array $row запись отсутствия ARMS
	 * @return string
	 */
	public static function bxKey($row) {
		$ext = isset($row['external_id']) ? (string)$row['external_id'] : '';
		//префикс исключает столкновение с составными ключами источников
		return strlen($ext) ? $ext : 'arms-' . $row['id'];
	}

	/*
	 * Грузит отсутствия из ARMS.
	 *
	 * Забираем разом весь ресурс, а не по сотруднику (иначе тысячи запросов):
	 * отбор по нужным сотрудникам делается локально в attachUsers.
	 *
	 * @param CArmsRestClient $client клиент REST API
	 * @param array $orgIds не используется (фильтр по организациям — через список
	 *                      сотрудников, у отсутствия своего org_id нет)
	 * @return bool успех загрузки
	 */
	public function loadFromRest(CArmsRestClient $client, array $orgIds = array()) {
		$this->data = array();
		foreach ($client->getAll('absences') as $r) {
			$key = static::bxKey($r);
			$this->data[$key] = $r;
		}
		echo count($this->data) . " items loaded in ARMS Absence storage\n";
		return is_array($this->data);
	}

	/*
	 * Строит карту "запись сотрудника ARMS -> пользователь Bitrix" по уже
	 * сопоставленным пользователям портала.
	 *
	 * Отсутствие висит на конкретном трудоустройстве (user_id), а сопоставление
	 * пользователя Bitrix даёт основное (getMainEmployment) — это разные записи у
	 * совместителей. Поэтому карта строится не по id записи, а по человеку: uid
	 * (хеш СНИЛС) общий у всех трудоустройств, табельный — запасной ключ для тех,
	 * у кого uid пуст.
	 *
	 * @param CBxUserList $bxUsers пользователи портала (с attachSap)
	 * @param CArmsUserList $armsUsers сотрудники ARMS
	 * @return int сколько пользователей портала удалось сопоставить
	 */
	public function buildUserMap($bxUsers, $armsUsers) {
		$byUid = array();
		$byPernr = array();
		$matched = 0;
		foreach ($bxUsers->getIds() as $bxId) {
			if (!($armsId = $bxUsers->getSapID($bxId))) continue;
			$matched++;
			$uid = (string)$armsUsers->getItemField($armsId, 'uid');
			if (strlen($uid) && !isset($byUid[$uid])) $byUid[$uid] = $bxId;
			$pernr = (string)$armsUsers->getItemField($armsId, 'Pernr');
			if (strlen($pernr) && !isset($byPernr[$pernr])) $byPernr[$pernr] = $bxId;
		}

		//раскладываем всех сотрудников ARMS по найденным пользователям портала
		$this->bxUserByArmsId = array();
		foreach ($armsUsers->getIds() as $armsId) {
			$uid = (string)$armsUsers->getItemField($armsId, 'uid');
			if (strlen($uid) && isset($byUid[$uid])) {
				$this->bxUserByArmsId[$armsId] = $byUid[$uid];
				continue;
			}
			$pernr = (string)$armsUsers->getItemField($armsId, 'Pernr');
			if (strlen($pernr) && isset($byPernr[$pernr]))
				$this->bxUserByArmsId[$armsId] = $byPernr[$pernr];
		}
		return $matched;
	}

	/*
	 * Оставляет только отсутствия сотрудников, попавших в карту (то есть
	 * сотрудников выгружаемых организаций, у которых есть пользователь портала).
	 * @return int сколько записей отброшено
	 */
	public function filterByUserMap() {
		$dropped = 0;
		foreach ($this->getIds() as $id) {
			$userId = (string)$this->getItemField($id, 'user_id');
			if (!isset($this->bxUserByArmsId[$userId])) {
				unset($this->data[$id]);
				$dropped++;
			}
		}
		$this->unresolved = $dropped;
		return $dropped;
	}

	public function getUnresolved() { return $this->unresolved; }

	/* ID пользователя Bitrix для записи отсутствия (null — не сопоставлен). */
	public function getBxUserId($id) {
		$userId = (string)$this->getItemField($id, 'user_id');
		return isset($this->bxUserByArmsId[$userId]) ? $this->bxUserByArmsId[$userId] : null;
	}

	/*
	 * Название элемента графика отсутствий.
	 *
	 * Для командировок — направление из comment ("Командировка в {Город} ({Страна})",
	 * формируется приёмом). Для остальных типов — обобщённое описание из словаря:
	 * график виден всем сотрудникам портала, и текст отсутствия из источника
	 * (SAP Abstext, вроде "Больничный по уходу за ребёнком") в него не выносится.
	 *
	 * @param string $id ключ записи
	 * @return string|null null, если тип неизвестен
	 */
	public function bxName($id) {
		$code = (string)$this->getItemField($id, 'type');
		$descr = CAbsenceTypes::descr($code);
		if (is_null($descr)) return null;
		if ($code === 'ASSIGNMENT') {
			$comment = trim((string)$this->getItemField($id, 'comment'));
			if (strlen($comment)) return $comment;
		}
		return $descr;
	}

	/*
	 * Поля элемента инфоблока отсутствий, которые должны быть в Bitrix.
	 *
	 * Границы дат сохраняют формат легаси-механизма (07:00 - 22:00 рабочего дня):
	 * иначе перевод существующих элементов на новый источник дал бы 30 тысяч
	 * бессмысленных обновлений.
	 *
	 * @param string $id ключ записи
	 * @param int $iblockId ID инфоблока отсутствий
	 * @return array|string набор полей или текст причины, по которой выгрузить нельзя
	 */
	public function bxGenFields($id, $iblockId) {
		$bxUser = $this->getBxUserId($id);
		if (is_null($bxUser)) return "нет пользователя портала (ARMS user_id " . $this->getItemField($id, 'user_id') . ")";

		$code = (string)$this->getItemField($id, 'type');
		$bxType = CAbsenceTypes::bxId($code);
		if (is_null($bxType)) return "неизвестный тип отсутствия: $code";

		$name = $this->bxName($id);
		if (is_null($name) || !strlen($name)) return "не удалось составить название";

		$from = strtotime((string)$this->getItemField($id, 'date_from'));
		$to = strtotime((string)$this->getItemField($id, 'date_to'));
		if (!$from || !$to) return "битые даты: " . $this->getItemField($id, 'date_from') . " .. " . $this->getItemField($id, 'date_to');

		return array(
			'IBLOCK_SECTION_ID' => false,	//в корень инфоблока, без секций
			'IBLOCK_ID'         => $iblockId,
			'ACTIVE'            => 'Y',
			'NAME'              => $name,
			'ACTIVE_FROM'       => date('d.m.Y 07:00:00', $from),
			'ACTIVE_TO'         => date('d.m.Y  22:00:00', $to),
			'PROPERTY_VALUES'   => array(
				'USER'          => $bxUser,
				'ABSENCE_TYPE'  => $bxType,
				'SAP_ID'        => $id,
			),
		);
	}
}

<?php
require_once dirname(__FILE__) . "/../Abstract/COrgStructureStorage.php";

/*
 * Хранилище элементов графика отсутствий Bitrix (инфоблок отсутствий).
 *
 * Синтаксис PHP 5.6: класс грузится на хосте портала.
 *
 * Элементы читаются одной выборкой в память (как оргструктура и пользователи) —
 * поштучный GetList на каждое из десятков тысяч отсутствий превращал бы прогон
 * в часы. Ключ хранилища — ID элемента Bitrix, сопоставление с источником идёт
 * через индекс по свойству SAP_ID.
 *
 * Элементы без SAP_ID — заведённые в портале руками — не трогаются: ни
 * обновлением, ни удалением.
 */
class CBxAbsenceList extends COrgStructureStorage {

	/*
	 * Инфоблок графика отсутствий. В отличие от оргструктуры (её инфоблок ищется
	 * по имени) исторически задан номером — в портале он ID 3.
	 */
	public static $AbsenceIBlockId = 3;

	/** @var array значение SAP_ID => ID элемента Bitrix */
	private $idxSapId = array();

	/*
	 * При создании загружает все элементы инфоблока отсутствий.
	 */
	public function __construct() {
		$select = array(
			'ID', 'NAME', 'ACTIVE', 'ACTIVE_FROM', 'ACTIVE_TO', 'IBLOCK_ID',
			'PROPERTY_SAP_ID', 'PROPERTY_USER', 'PROPERTY_ABSENCE_TYPE',
		);
		$res = CIBlockElement::GetList(
			array('ID' => 'ASC'),
			array('IBLOCK_ID' => static::$AbsenceIBlockId),
			false,
			false,
			$select
		);
		while ($item = $res->GetNext(false, false)) $this->initItem($item, 'ID');

		$this->buildIndexes();
		echo count($this->data) . " items loaded in Bitrix Absence storage (индексировано по SAP_ID: " .
			count($this->idxSapId) . ")\n";
	}

	/* Перестраивает индекс SAP_ID -> ID элемента. */
	public function buildIndexes() {
		$this->idxSapId = array();
		foreach ($this->data as $id => $item) {
			$sapId = isset($item['PROPERTY_SAP_ID_VALUE']) ? (string)$item['PROPERTY_SAP_ID_VALUE'] : '';
			if (!strlen($sapId)) continue;
			//дубль по ключу: в портал попали две записи одного отсутствия — оставляем
			//первую (меньший ID), вторую покажет обратный проход как лишнюю
			if (isset($this->idxSapId[$sapId])) {
				echo "Дубль SAP_ID=$sapId: элементы #" . $this->idxSapId[$sapId] . " и #$id\n";
				continue;
			}
			$this->idxSapId[$sapId] = $id;
		}
	}

	/* ID элемента портала по ключу источника (null — нет такого). */
	public function findBySapId($sapId) {
		$sapId = (string)$sapId;
		return isset($this->idxSapId[$sapId]) ? $this->idxSapId[$sapId] : null;
	}

	/* Ключи источника (SAP_ID) всех элементов, заведённых синхронизацией. */
	public function getSapIds() { return array_keys($this->idxSapId); }

	/*
	 * Загружает один элемент из Bitrix (после записи).
	 * @return bool успех
	 */
	public function reloadItem($id) {
		$res = CIBlockElement::GetList(
			array(),
			array('IBLOCK_ID' => static::$AbsenceIBlockId, 'ID' => $id),
			false,
			false,
			array('ID', 'NAME', 'ACTIVE', 'ACTIVE_FROM', 'ACTIVE_TO', 'IBLOCK_ID',
				'PROPERTY_SAP_ID', 'PROPERTY_USER', 'PROPERTY_ABSENCE_TYPE')
		);
		if ($item = $res->GetNext(false, false)) {
			$this->initItem($item, 'ID');
			return true;
		}
		return false;
	}

	/*
	 * Приводит дату Bitrix к сравнимому виду: поля выборки и поля записи приходят
	 * в формате сайта, но с разным набором пробелов и нулей.
	 * @param string $date
	 * @return string|null
	 */
	public static function dateKey($date) {
		$date = trim((string)$date);
		if (!strlen($date)) return null;
		$ts = MakeTimeStamp($date);
		if (!$ts) $ts = strtotime($date);
		return $ts ? date('d.m.Y H:i:s', $ts) : null;
	}

	/*
	 * Сравнивает элемент портала с тем, каким он должен быть.
	 * @param string $id ID элемента Bitrix
	 * @param array $fields набор полей (bxGenFields источника)
	 * @return array список отличий (пустой — всё совпадает)
	 */
	public function diffItem($id, $fields) {
		$diff = array();
		$item = $this->getItem($id);
		if (!is_array($item)) return array('элемент не загружен');

		if ((string)$item['NAME'] !== (string)$fields['NAME'])
			$diff[] = "NAME: '" . $item['NAME'] . "' -> '" . $fields['NAME'] . "'";

		if ((string)$item['ACTIVE'] !== (string)$fields['ACTIVE'])
			$diff[] = "ACTIVE: " . $item['ACTIVE'] . " -> " . $fields['ACTIVE'];

		foreach (array('ACTIVE_FROM', 'ACTIVE_TO') as $f) {
			$was = static::dateKey(isset($item[$f]) ? $item[$f] : '');
			$now = static::dateKey($fields[$f]);
			if ($was !== $now) $diff[] = "$f: " . $was . " -> " . $now;
		}

		$props = $fields['PROPERTY_VALUES'];

		/*
		 * Свойства сравниваются только если выборка их вернула. Иначе (свойство
		 * переименовали, изменился набор полей выборки) любое отсутствие в портале
		 * выглядело бы отличающимся — это десятки тысяч ложных обновлений.
		 */
		if (isset($item['PROPERTY_USER_VALUE'])) {
			if ((string)$item['PROPERTY_USER_VALUE'] !== (string)$props['USER'])
				$diff[] = "USER: " . $item['PROPERTY_USER_VALUE'] . " -> " . $props['USER'];
		}

		//у списочного свойства сравнивать нужно ID варианта списка, а не его текст
		if (isset($item['PROPERTY_ABSENCE_TYPE_ENUM_ID'])) {
			if ((string)$item['PROPERTY_ABSENCE_TYPE_ENUM_ID'] !== (string)$props['ABSENCE_TYPE'])
				$diff[] = "ABSENCE_TYPE: " . $item['PROPERTY_ABSENCE_TYPE_ENUM_ID'] . " -> " . $props['ABSENCE_TYPE'];
		}

		return $diff;
	}

	/*
	 * Создаёт элемент отсутствия.
	 * @param array $fields набор полей
	 * @return string|null ID нового элемента
	 */
	public function addItem($fields) {
		if ($this->readOnly) {
			echo "[DRY-RUN] создал бы отсутствие " . $fields['PROPERTY_VALUES']['SAP_ID'] .
				" (" . $fields['NAME'] . ", " . $fields['ACTIVE_FROM'] . " .. " . $fields['ACTIVE_TO'] . ")\n";
			return 'DRYRUN-' . $fields['PROPERTY_VALUES']['SAP_ID'];
		}

		$el = new CIBlockElement();
		$id = $el->Add($fields);
		if (!$id) {
			echo "Ошибка создания отсутствия " . $fields['PROPERTY_VALUES']['SAP_ID'] . ": " . $el->LAST_ERROR . "\n";
			return null;
		}
		$this->reloadItem($id);
		$this->idxSapId[(string)$fields['PROPERTY_VALUES']['SAP_ID']] = $id;
		return $id;
	}

	/*
	 * Обновляет элемент отсутствия.
	 * Поля и свойства пишутся раздельно: CIBlockElement::Update свойства не трогает.
	 * @param string $id ID элемента Bitrix
	 * @param array $fields набор полей
	 * @return bool успех
	 */
	public function updItem($id, $fields) {
		$props = $fields['PROPERTY_VALUES'];
		unset($fields['PROPERTY_VALUES']);
		//IBLOCK_ID при обновлении не передаётся: смена инфоблока — не наш случай
		unset($fields['IBLOCK_ID']);

		if ($this->readOnly) {
			echo "[DRY-RUN] отсутствие #$id: обновил бы " . json_encode($fields, JSON_UNESCAPED_UNICODE) .
				" + свойства " . json_encode($props, JSON_UNESCAPED_UNICODE) . "\n";
			return true;
		}

		$el = new CIBlockElement();
		if (!$el->Update($id, $fields)) {
			echo "Ошибка обновления отсутствия #$id: " . $el->LAST_ERROR . "\n";
			return false;
		}
		CIBlockElement::SetPropertyValuesEx($id, static::$AbsenceIBlockId, $props);
		$this->reloadItem($id);
		return true;
	}

	/*
	 * Удаляет элемент отсутствия.
	 * @param string $id ID элемента Bitrix
	 * @return bool успех
	 */
	public function delItem($id) {
		if ($this->readOnly) {
			echo "[DRY-RUN] удалил бы отсутствие #$id (" . $this->getItemField($id, 'NAME') . ", SAP_ID " .
				$this->getItemField($id, 'PROPERTY_SAP_ID_VALUE') . ")\n";
			return true;
		}

		global $DB;
		$DB->StartTransaction();
		if (!CIBlockElement::Delete($id)) {
			$DB->Rollback();
			echo "Ошибка удаления отсутствия #$id\n";
			return false;
		}
		$DB->Commit();

		$sapId = (string)$this->getItemField($id, 'PROPERTY_SAP_ID_VALUE');
		if (strlen($sapId)) unset($this->idxSapId[$sapId]);
		unset($this->data[$id]);
		return true;
	}
}

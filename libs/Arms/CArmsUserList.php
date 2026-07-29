<?php
require_once dirname(__FILE__) . "/CArmsRestClient.php";
require_once dirname(__FILE__) . "/CArmsOrgStructure.php";
require_once dirname(__FILE__) . "/../Abstract/COrgStructureStorage.php";

/*
 * Сотрудники из инвентаризации ARMS (источник src='arms'), читаются через REST.
 *
 * Самостоятельный наследник COrgStructureStorage. Логика сопоставления личностей
 * (getMainEmployment) и генерации полей Bitrix перенесена сюда осознанно, а не
 * унаследована от CSapUserList: SAP-версия пересчитывает uid из СНИЛС и несовместима
 * с PHP 8.2. ARMS отдаёт uid готовым, поле Uvolen — числом, Bday — в формате д-м-г.
 *
 * Ключ записи неймспейсится по организации "{org_id}-{employee_id}" (как XML_ID
 * пользователя в Bitrix), подразделение Orgeh приводится к неймспейс-коду секции.
 */
class CArmsUserList extends COrgStructureStorage {

	private $bxOrg = null;
	private $sapOrg = null;
	private $orgDict = null;
	/** @var CArmsRestClient сохраняется в loadFromRest — нужен для фотографий (сканы) */
	private $client = null;

	/*
	 * Индексы для сопоставления (ключ -> список id записей). Без них getMainEmployment
	 * линейно сканирует всех сотрудников на КАЖДОГО пользователя Bitrix (2600 x 2400
	 * сравнений с html_entity_decode/mb_strtolower — минуты вместо секунд).
	 */
	private $idxPernr = array();
	private $idxUid = array();
	private $idxName = array();

	public function attachBxOrg(&$bx) { $this->bxOrg = $bx; }
	public function attachSapOrg(&$sap) { $this->sapOrg = $sap; }
	public function attachOrgDict(&$dict) { $this->orgDict = $dict; }

	/* Ключ индекса ФИО: регистр, ё->е, лишние пробелы (эквивалент soft-сравнения). */
	public static function nameKey($name) {
		$n = mb_strtolower(trim((string)$name));
		$n = str_replace('ё', 'е', $n);
		return preg_replace('/\s+/u', ' ', $n);
	}

	/* Перестраивает индексы сопоставления по текущему $data. */
	public function buildIndexes() {
		$this->idxPernr = array();
		$this->idxUid = array();
		$this->idxName = array();
		foreach ($this->data as $id => $u) {
			$p = (string)(isset($u['Pernr']) ? $u['Pernr'] : '');
			if (strlen($p)) $this->idxPernr[$p][] = $id;
			$uid = (string)(isset($u['uid']) ? $u['uid'] : '');
			if (strlen($uid)) $this->idxUid[$uid][] = $id;
			$nk = static::nameKey(isset($u['Ename']) ? $u['Ename'] : '');
			if (strlen($nk)) $this->idxName[$nk][] = $id;
		}
	}

	/*
	 * Грузит сотрудников из ARMS.
	 * @param CArmsRestClient $client клиент REST API
	 * @param array $orgIds список org_id (пустой = все организации)
	 * @return bool успех загрузки
	 */
	public function loadFromRest(CArmsRestClient $client, array $orgIds = []) {
		$this->client = $client;
		$this->data = [];
		$rows = [];
		if ($orgIds) {
			foreach ($orgIds as $orgId)
				foreach ($client->getAll('users', ['org_id' => $orgId]) as $r) $rows[] = $r;
		} else {
			$rows = $client->getAll('users');
		}
		foreach ($rows as $r) {
			// ключ — уникальный id записи ARMS: у одного табельного (employee_id) может
			// быть несколько трудоустройств (строк), их нельзя схлопывать — среди них
			// потом выбирает getMainEmployment
			$key = $r['id'];
			$r['Pernr'] = (string)$r['employee_id'];
			$orgeh = isset($r['Orgeh']) ? (string)$r['Orgeh'] : '';
			$r['Orgeh'] = strlen($orgeh) ? CArmsOrgStructure::code($r['org_id'], $orgeh) : '';
			$this->data[$key] = $r;
		}
		$this->buildExFieldsAll();
		$this->buildIndexes();
		return is_array($this->data);
	}

	/* Приводит поля записи к внутреннему виду (признак увольнения, Gbdat, uid). */
	public function buildExFields($id) {
		if (is_null($id) || !$this->exist($id)) return false;
		$u = &$this->data[$id];
		// признак увольнения внутри — булев; ARMS отдаёт числом 0/1 (это единственная
		// точка трактовки значения источника, дальше по коду работаем с boolean)
		$u['_dismissed'] = ((int)(isset($u['Uvolen']) ? $u['Uvolen'] : 0) === 1);
		// Bday '9-02-1984' (д-м-г) -> Gbdat 'Y-m-d' для PERSONAL_BIRTHDAY
		$u['Gbdat'] = static::normalizeBday(isset($u['Bday']) ? $u['Bday'] : '');
		// uid (хеш СНИЛС) ARMS отдаёт готовым — оставляем как есть (может быть пустым)
		$u['uid'] = (string)(isset($u['uid']) ? $u['uid'] : '');
		$u['exFieldsBuilt'] = true;
		return true;
	}

	/* Признак увольнения как внутренний boolean. */
	public function isDismissed($id) { return (bool)$this->getItemField($id, '_dismissed'); }
	public function isActive($id) { return !$this->isDismissed($id); }

	/* Идентификаторы активных (неуволенных) сотрудников подразделения по коду Orgeh. */
	public function getActiveIdsByOrgeh($orgehCode) {
		$res = [];
		foreach ($this->findIdsBy(['Orgeh' => $orgehCode]) as $id)
			if (!$this->isDismissed($id)) $res[] = $id;
		return $res;
	}

	public function buildExFieldsAll() {
		foreach ($this->getIds() as $id) $this->buildExFields($id);
	}

	/* Нормализация даты рождения: '9-02-1984' (д-м-г) -> '1984-02-09'; пустое/0000-00-00 -> ''. */
	public static function normalizeBday($bday) {
		$bday = trim((string)$bday);
		if ($bday === '' || $bday === '0000-00-00') return '';
		$p = explode('-', $bday);
		if (count($p) === 3 && strlen($p[2]) === 4) {
			return sprintf('%04d-%02d-%02d', (int)$p[2], (int)$p[1], (int)$p[0]);
		}
		$t = strtotime($bday);
		return $t ? date('Y-m-d', $t) : '';
	}

	/*
	 * Коды подразделений всех активных трудоустройств человека (по uid).
	 * При пустом uid группировать нельзя — возвращаем собственное подразделение записи.
	 * @param string $uid хеш СНИЛС
	 * @param string|null $ownOrgeh Orgeh записи, с которой пришли (для случая пустого uid)
	 * @return array список CODE подразделений в Bitrix
	 */
	/*
	 * Секция Bitrix по коду подразделения: новый формат, при промахе — легаси
	 * (голый hr_id; коды SAP уникальны между организациями, см. CArmsOrgStructure).
	 */
	private function findBxDep($code) {
		//пустой код искать нельзя: fieldCompare считает совпадением любую секцию
		//без CODE (напр. обёртку портала) и сотрудник уехал бы в чужой узел
		if (!strlen((string)$code)) return null;
		$bxCode = $this->bxOrg->findIdBy('CODE', $code);
		if (!is_null($bxCode)) return $bxCode;
		$bare = CArmsOrgStructure::bareCode($code);
		return strlen($bare) ? $this->bxOrg->findIdBy('CODE', $bare) : null;
	}

	public function getActiveDepartmentsCodes($uid, $ownOrgeh = null) {
		$bxDepartments = [];
		if (strlen((string)$uid)) {
			foreach ($this->findIdsBy(['uid' => $uid]) as $id) {
				if ($this->isDismissed($id)) continue;
				$bxCode = $this->findBxDep($this->getItemField($id, 'Orgeh'));
				if (!is_null($bxCode)) $bxDepartments[$bxCode] = $bxCode;
			}
		} elseif (!is_null($ownOrgeh) && strlen((string)$ownOrgeh)) {
			$bxCode = $this->findBxDep($ownOrgeh);
			if (!is_null($bxCode)) $bxDepartments[$bxCode] = $bxCode;
		}
		//сортировка — чтобы сравнение с Bitrix не давало ложных дифов из-за порядка
		$res = array_keys($bxDepartments);
		sort($res, SORT_NUMERIC);
		return $res;
	}

	/*
	 * Поля, которые должны быть у пользователя Bitrix.
	 * День рождения (PERSONAL_BIRTHDAY) сознательно НЕ передаётся (решение
	 * пользователя, 2026-07-27); уже записанные в Bitrix значения не трогаются.
	 * @param string $id идентификатор записи
	 * @return array
	 */
	public function bxGenFields($id) {
		$departments = $this->getActiveDepartmentsCodes(
			$this->getItemField($id, 'uid'),
			$this->getItemField($id, 'Orgeh')
		);
		$fields = ['ACTIVE' => 'Y'];
		/*
		 * Пустой набор подразделений не пишем: UF_DEPARTMENT в портале фактически
		 * обязательное, и запись [] выкинула бы сотрудника из структуры компании.
		 * Пустым набор выходит, когда подразделения сотрудника нет в портале или
		 * его нет вовсе (ручная запись в ARMS без оргструктуры) — устаревшее
		 * подразделение в этом случае меньшее зло, чем его отсутствие.
		 */
		if ($departments) $fields['UF_DEPARTMENT'] = $departments;
		else echo "ARMS #$id ('" . $this->getItemField($id, 'Ename') . "'): подразделение не определено, UF_DEPARTMENT не трогаем\n";
		if ($this->isDismissed($id)) $fields['ACTIVE'] = 'N';
		return $fields;
	}

	/*
	 * Ставит сотрудников организаций без оргструктуры на синтетические корни
	 * (см. CArmsOrgStructure): в такой организации подразделений нет вовсе, и
	 * собственный Orgeh записи вести некуда — в том числе если он непуст, но
	 * указывает на несуществующий узел (ручной ввод, остатки прежних выгрузок).
	 * @param array $roots карта org_id => код корневого узла
	 * @return int сколько записей переставлено
	 */
	public function applyOrgRoots($roots) {
		if (!is_array($roots) || !count($roots)) return 0;
		$n = 0;
		foreach ($this->data as $id => $u) {
			$org = isset($u['org_id']) ? (string)$u['org_id'] : '';
			if (!strlen($org) || !isset($roots[$org])) continue;
			if ($this->data[$id]['Orgeh'] === $roots[$org]) continue;
			$this->data[$id]['Orgeh'] = $roots[$org];
			$n++;
		}
		return $n;
	}

	/*
	 * Портрет сотрудника из сканов ARMS: сканы с именем photo-{pernr}-{дата},
	 * портретом считается последний по дате (конвенция ARMS). SAP-дата фото
	 * хранится в имени скана — она же пишется в UF_SAP_PHOTO_SYNC пользователя
	 * Bitrix и служит признаком актуальности (сами байты не сравниваются).
	 * @param mixed $id идентификатор записи ARMS (трудоустройства)
	 * @return array|null ['id'=>ид скана, 'date'=>Y-m-d, 'format'=>расширение] или null
	 */
	public function getPortrait($id) {
		if (is_null($this->client) || !$this->exist($id)) return null;
		$pernr = (string)$this->getItemField($id, 'Pernr');
		$best = null;
		foreach ($this->client->getAll('scans', array('users_id' => $id, 'expand' => 'name')) as $s) {
			//имя может приходить с расширением (зависит от версии ARMS) — сравниваем без него
			$name = preg_replace('/\.[a-z0-9]+$/i', '', (string)(isset($s['name']) ? $s['name'] : ''));
			if (!preg_match('/^photo-(.+)-(\d{4}-\d{2}-\d{2})$/', $name, $m)) continue; //не фото
			if ((string)$m[1] !== $pernr) continue; //фото другого трудоустройства
			if (is_null($best) || strcmp($m[2], $best['date']) > 0)
				$best = array(
					'id'     => $s['id'],
					'date'   => $m[2],
					'format' => strlen((string)(isset($s['format']) ? $s['format'] : '')) ? $s['format'] : 'jpg',
				);
		}
		return $best;
	}

	/*
	 * Байты файла скана (для записи фото в Bitrix).
	 * @param mixed $scanId ид скана ARMS
	 * @return string|null сырые байты или null при ошибке
	 */
	public function downloadScan($scanId) {
		if (is_null($this->client)) return null;
		try {
			return $this->client->downloadRaw('scans/download', array('id' => $scanId));
		} catch (RuntimeException $e) {
			echo "ошибка скачивания скана #$scanId: " . $e->getMessage() . "\n";
			return null;
		}
	}

	/*
	 * Основное трудоустройство по фильтру: исключает ДГПХ (Persg 4), предпочитает
	 * неуволенных, возвращает трудоустройство наименьшего типа Persg.
	 * @param array $filter напр. ['Pernr'=>'123'] | ['uid'=>...] | ['Ename'=>...]
	 * @return string|null ключ записи основного трудоустройства
	 * @throws Exception при столкновении двух основных трудоустройств (однофамильцы)
	 */
	public function getMainEmployment($filter) {
		//быстрый путь: одиночные фильтры по Pernr/uid/Ename решаются индексами O(1)
		$users = null;
		$indexed = count($this->idxPernr) || count($this->idxUid) || count($this->idxName);
		if ($indexed && count($filter) === 1) {
			if (isset($filter['Pernr'])) {
				$k = (string)$filter['Pernr'];
				$users = isset($this->idxPernr[$k]) ? $this->idxPernr[$k] : array();
			} elseif (isset($filter['uid'])) {
				$k = (string)$filter['uid'];
				$users = isset($this->idxUid[$k]) ? $this->idxUid[$k] : array();
			} elseif (isset($filter['Ename'])) {
				$k = static::nameKey($filter['Ename']);
				$users = isset($this->idxName[$k]) ? $this->idxName[$k] : array();
			}
		}
		if (is_null($users)) {
			//медленный путь для нестандартных фильтров (и данных без индексов)
			$users = $this->findIdsBy($filter);
			if (!count($users)) $users = $this->findIdsBy($filter, true, false, true); // без учёта Ё
		}
		if (!count($users)) return null;

		$types = [];
		foreach ($users as $user) {
			$type = (int)$this->getItemField($user, 'Persg');
			if ($type == 4) continue; // ДГПХ исключаем

			if (($exst = array_search($type, $types)) !== false) {
				$userActive = $this->isActive($user);
				$exstActive = $this->isActive($exst);
				if ($userActive xor $exstActive) {
					if ($exstActive) continue;
					unset($types[$exst]);
				} else {
					if ($type === 1) {
						if (!$userActive && !$exstActive) continue;
						throw new Exception(EMPLOYMENTSEARCH_MANY_RESULTS_TEXT, EMPLOYMENTSEARCH_MANY_RESULTS);
					}
				}
			}
			$types[$user] = $type;
		}
		if (!count($types)) return null;
		return array_search(min($types), $types);
	}
}

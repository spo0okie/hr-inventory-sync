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

	public function attachBxOrg(&$bx) { $this->bxOrg = $bx; }
	public function attachSapOrg(&$sap) { $this->sapOrg = $sap; }
	public function attachOrgDict(&$dict) { $this->orgDict = $dict; }

	/*
	 * Грузит сотрудников из ARMS.
	 * @param CArmsRestClient $client клиент REST API
	 * @param array $orgIds список org_id (пустой = все организации)
	 * @return bool успех загрузки
	 */
	public function loadFromRest(CArmsRestClient $client, array $orgIds = []) {
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
		$fields = [
			'ACTIVE'        => 'Y',
			'UF_DEPARTMENT' => $departments,
		];
		if ($this->isDismissed($id)) $fields['ACTIVE'] = 'N';
		return $fields;
	}

	/*
	 * Основное трудоустройство по фильтру: исключает ДГПХ (Persg 4), предпочитает
	 * неуволенных, возвращает трудоустройство наименьшего типа Persg.
	 * @param array $filter напр. ['Pernr'=>'123'] | ['uid'=>...] | ['Ename'=>...]
	 * @return string|null ключ записи основного трудоустройства
	 * @throws Exception при столкновении двух основных трудоустройств (однофамильцы)
	 */
	public function getMainEmployment($filter) {
		$users = $this->findIdsBy($filter);
		if (!count($users)) $users = $this->findIdsBy($filter, true, false, true); // без учёта Ё
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

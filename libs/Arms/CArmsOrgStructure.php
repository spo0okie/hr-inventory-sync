<?php
require_once dirname(__FILE__) . "/CArmsRestClient.php";
require_once dirname(__FILE__) . "/../Abstract/COrgStructureStorage.php";

/*
 * Оргструктура из инвентаризации ARMS (источник src='arms'), читается через REST.
 *
 * Самостоятельный наследник COrgStructureStorage (не от SAP-классов — те завязаны
 * на формат SAP и вдобавок несовместимы с PHP 8.2 по сигнатурам методов загрузки).
 *
 * Ключ записи и Bitrix CODE неймспейсятся по организации: "{org_id}-{hr_id}".
 * Причина: hr_id уникален только внутри org_id, а в Bitrix агрегируется несколько
 * юрлиц — без префикса подразделения разных орг с одинаковым hr_id склеятся.
 * Дерево строится по parent_hr_id -> hr_id (в пределах одной организации).
 */
class CArmsOrgStructure extends COrgStructureStorage {

	/** @var CBxOrgStructure оргструктура Bitrix для сверки/создания секций */
	private $bx = null;
	/** @var CArmsUserList сотрудники — для оценки заполненности подразделений */
	private $users = null;

	/** Неймспейс-код подразделения (== Bitrix CODE). Единая точка формирования. */
	public static function code($orgId, $hrId) {
		return $orgId . '-' . $hrId;
	}

	/** Голый hr_id из неймспейс-кода ('1-10000001' -> '10000001'). */
	public static function bareCode($code) {
		$p = strpos((string)$code, '-');
		return $p === false ? '' : substr((string)$code, $p + 1);
	}

	/*
	 * Секция Bitrix по коду подразделения: сначала новый формат {org_id}-{hr_id},
	 * при промахе — легаси-формат (голый hr_id, как писал старый механизм).
	 * Голый код однозначен: по соглашению в SAP коды подразделений уникальны
	 * между организациями. Найденная по легаси-коду секция затем переименуется
	 * в новый формат обычным correctItem (CODE входит в bxGenFields).
	 */
	public function findBxSection($code) {
		if (!is_null($bxID = $this->bx->findIDBy('CODE', $code))) return $bxID;
		$bare = static::bareCode($code);
		if (strlen($bare) && !is_null($bxID = $this->bx->findIDBy('CODE', $bare))) return $bxID;
		return null;
	}

	public function attachBx(&$bx) { $this->bx = $bx; }
	public function attachUsers(&$users) { $this->users = $users; }

	/*
	 * Грузит оргструктуру из ARMS.
	 * @param CArmsRestClient $client клиент REST API
	 * @param array $orgIds список org_id для выборки (пустой = все организации)
	 * @return bool успех загрузки
	 */
	public function loadFromRest(CArmsRestClient $client, array $orgIds = []) {
		$this->data = [];
		$rows = [];
		if ($orgIds) {
			foreach ($orgIds as $orgId)
				foreach ($client->getAll('org-struct', ['org_id' => $orgId]) as $r) $rows[] = $r;
		} else {
			$rows = $client->getAll('org-struct');
		}
		foreach ($rows as $r) {
			$code = static::code($r['org_id'], $r['hr_id']);
			$parentHr = isset($r['parent_hr_id']) ? (string)$r['parent_hr_id'] : '';
			$this->data[$code] = [
				'Objid'        => $code,        // роль SAP Objid == Bitrix CODE
				'Orgtx'        => $r['name'],   // имя подразделения
				'org_id'       => $r['org_id'],
				'hr_id'        => (string)$r['hr_id'],
				'parent_hr_id' => $parentHr,
				'parent_code'  => strlen($parentHr) ? static::code($r['org_id'], $parentHr) : null,
			];
		}
		return is_array($this->data);
	}

	/* Родитель — по заранее вычисленному parent_code (или null для корня). */
	public function getParent($id) {
		$p = $this->getItemField($id, 'parent_code');
		return (!is_null($p) && $this->exist($p)) ? $p : null;
	}

	/* Дочерние узлы — те, у кого parent_code указывает на $id. */
	public function getChildren($id) {
		return $this->findIdsBy(['parent_code' => $id]);
	}

	/* Активные (неуволенные) сотрудники подразделения. Результат кэшируется в записи. */
	public function getActiveUsers($id) {
		if (is_array($u = $this->getItemField($id, 'userList'))) return $u;
		if (!is_object($this->users)) return [];
		$u = $this->users->getActiveIdsByOrgeh($id);
		$this->data[$id]['userList'] = $u;
		return $u;
	}

	/* Активные сотрудники узла и всех вложенных подразделений. */
	public function getActiveUsersRecursive($id) {
		$users = $this->getActiveUsers($id);
		foreach ($this->getChildren($id) as $node)
			$users = array_merge($users, $this->getActiveUsersRecursive($node));
		return $users;
	}

	/*
	 * Поля, которые должны быть у секции Bitrix, соответствующей этому подразделению.
	 * @param string $id идентификатор (неймспейс-код) подразделения
	 * @return array
	 */
	public function bxGenFields($id) {
		$parent = $this->getParent($id);
		$fields = [
			"ACTIVE"            => count($this->getActiveUsersRecursive($id)) ? 'Y' : 'N',
			"IBLOCK_ID"         => $this->bx->getStructIBlock(),
			"NAME"              => $this->getItemField($id, 'Orgtx'),
			"CODE"              => $id,
		];
		//у корневого узла положение секции не трогаем: где администратор портала её
		//разместил (например, под обёрткой «Организации»), там она и остаётся
		if (!is_null($parent)) $fields["IBLOCK_SECTION_ID"] = $this->findBxSection($parent);
		return $fields;
	}

	/*
	 * Проверяет (создавая при отсутствии) наличие секции Bitrix, поднимаясь до корня.
	 * @return string|null id секции Bitrix или null
	 */
	public function ckBxItemExist($id) {
		if (is_null($this->bx)) die('No bx object attached!');
		if (!is_null($res = $this->getItemField($id, 'bx_item_id'))) return $res;
		if (!is_null($parent = $this->getParent($id)))
			if (!$this->ckBxItemExist($parent)) return null;
		if (
			is_null($bxID = $this->findBxSection($id)) &&  //новый формат, затем легаси-код
			is_null($bxID = $this->bx->addItem($this->bxGenFields($id)))
		) return null;
		return $this->data[$id]['bx_item_id'] = $bxID;
	}

	/*
	 * Проверяет (правя при расхождении) поля секции Bitrix, поднимаясь до корня.
	 * @return bool
	 */
	public function ckBxItemFields($id) {
		if (is_null($this->bx)) die('No bx object attached!');
		if ($this->getItemField($id, 'checked_bxfields_ok')) return true;
		if (is_null($bxID = $this->ckBxItemExist($id))) return false;
		if (!is_null($parent = $this->getParent($id)))
			if (!$this->ckBxItemFields($parent)) return false;
		return $this->data[$id]['checked_bxfields_ok'] = $this->bx->correctItem($bxID, $this->bxGenFields($id));
	}
}

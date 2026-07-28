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
 *
 * Организация без оргструктуры (заведена в инвентаризации руками, узлов нет)
 * получает синтетический корень "{org_id}-org" с названием самой организации —
 * иначе её сотрудникам некуда встать в дереве подразделений портала. Синтез
 * только при полном отсутствии узлов: у организации со структурой корень уже
 * есть и уже играет роль юрлица, второй узел дал бы задвоение в портале.
 */
class CArmsOrgStructure extends COrgStructureStorage {

	/** @var CBxOrgStructure оргструктура Bitrix для сверки/создания секций */
	private $bx = null;
	/** @var CArmsUserList сотрудники — для оценки заполненности подразделений */
	private $users = null;
	/** @var array синтезированные корни организаций: org_id => код узла */
	private $orgRoots = array();
	/** @var string|null|bool секция-обёртка портала для корней; false — ещё не искали */
	private $rootParentBx = false;

	/** hr_id синтетического корня организации (реальные hr_id в ARMS — целые) */
	const ORG_ROOT_HR = 'org';

	/** Неймспейс-код подразделения (== Bitrix CODE). Единая точка формирования. */
	public static function code($orgId, $hrId) {
		return $orgId . '-' . $hrId;
	}

	/*
	 * Голый hr_id из неймспейс-кода ('1-10000001' -> '10000001').
	 * Легаси-код — голый Objid SAP, всегда числовой, поэтому нечисловой хвост
	 * (синтетический корень '{org_id}-org') легаси-секции соответствовать не может
	 * и наружу не отдаётся — иначе поиск ушёл бы искать секцию с CODE='org'.
	 */
	public static function bareCode($code) {
		$p = strpos((string)$code, '-');
		if ($p === false) return '';
		$bare = substr((string)$code, $p + 1);
		return ctype_digit($bare) ? $bare : '';
	}

	/** Карта синтезированных корней организаций: org_id => код узла. */
	public function getOrgRoots() { return $this->orgRoots; }

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
		$this->orgRoots = array();
		$rows = [];
		if ($orgIds) {
			foreach ($orgIds as $orgId)
				foreach ($client->getAll('org-struct', ['org_id' => $orgId]) as $r) $rows[] = $r;
		} else {
			$rows = $client->getAll('org-struct');
		}
		$withNodes = array();
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
			$withNodes[(string)$r['org_id']] = true;
		}
		/*
		 * Организациям без единого узла — синтетический корень из самой организации.
		 * Считается по факту загруженного, а не по флагу в конфиге: как только в ARMS
		 * появятся настоящие узлы, синтез для этой организации прекратится сам.
		 * Возможно только при явном списке организаций: без него неоткуда взять
		 * перечень тех, у кого узлов нет (в ARMS их заводят администраторы).
		 */
		foreach ($orgIds as $orgId)
			if (!isset($withNodes[(string)$orgId])) $this->addOrgRoot($client, $orgId);

		return is_array($this->data);
	}

	/*
	 * Добавляет синтетический корень организации (узел, представляющий юрлицо целиком).
	 * @return string код добавленного узла
	 */
	private function addOrgRoot(CArmsRestClient $client, $orgId) {
		$code = static::code($orgId, static::ORG_ROOT_HR);
		$name = static::fetchOrgName($client, $orgId);
		$this->data[$code] = array(
			'Objid'        => $code,
			'Orgtx'        => $name,
			'org_id'       => $orgId,
			'hr_id'        => static::ORG_ROOT_HR,
			'parent_hr_id' => '',
			'parent_code'  => null,
			'synthetic'    => true,
		);
		$this->orgRoots[(string)$orgId] = $code;
		echo "Организация $orgId: оргструктуры в ARMS нет, корневой узел из самой организации — '$name' ($code)\n";
		return $code;
	}

	/*
	 * Название организации из ARMS (partners): бренд, иначе юр. название — тот же
	 * порядок, что у вычисляемого Partners::getName() в ARMS (в API оно не отдаётся,
	 * поэтому собираем сами). При недоступности — заглушка, чтобы секция завелась.
	 * @return string
	 */
	private static function fetchOrgName(CArmsRestClient $client, $orgId) {
		$p = array();
		try {
			$p = $client->get('partners/' . $orgId);
		} catch (Exception $e) {
			echo "ВНИМАНИЕ: название организации $orgId не получено: " . $e->getMessage() . "\n";
		}
		foreach (array('name', 'bname', 'uname') as $f)
			if (isset($p[$f]) && strlen(trim((string)$p[$f]))) return trim((string)$p[$f]);
		return 'Организация ' . $orgId;
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
		if (is_null($bxID = $this->findBxSection($id))) {  //новый формат, затем легаси-код
			$fields = $this->bxGenFields($id);
			/*
			 * Корень нельзя создать на верхнем уровне: Bitrix допускает в структуре
			 * компании только один раздел верхнего уровня («В структуре компании
			 * должен быть только один раздел верхнего уровня»). Кладём новый корень
			 * в ту же секцию-обёртку, где уже лежат корни других организаций.
			 * Положение УЖЕ существующих корней при этом по-прежнему не сверяется:
			 * bxGenFields корня не содержит IBLOCK_SECTION_ID, поле добавляется
			 * только здесь, в момент создания.
			 */
			if (is_null($this->getParent($id))) {
				if (is_null($wrap = $this->rootParentBxId())) {
					echo "секция-обёртка для корневого узла $id не определена, секция не создана\n";
					return null;
				}
				$fields['IBLOCK_SECTION_ID'] = $wrap;
			}
			if (is_null($bxID = $this->bx->addItem($fields))) return null;
		}
		return $this->data[$id]['bx_item_id'] = $bxID;
	}

	/*
	 * Секция портала, внутри которой лежат корни организаций.
	 * Определяется по факту: родитель тех корневых узлов, что уже есть в портале
	 * (так новый корень встаёт рядом с остальными юрлицами, а не куда придётся).
	 * Если корней ARMS в портале ещё нет — единственный раздел верхнего уровня.
	 * @return string|null id секции Bitrix
	 */
	public function rootParentBxId() {
		if ($this->rootParentBx !== false) return $this->rootParentBx;
		$counts = array();
		foreach ($this->getIds() as $id) {
			if (!is_null($this->getParent($id))) continue;
			if (is_null($bxID = $this->findBxSection($id))) continue;
			$p = $this->bx->getItemField($bxID, 'IBLOCK_SECTION_ID');
			if (!strlen((string)$p)) continue;
			if (!isset($counts[$p])) $counts[$p] = 0;
			$counts[$p]++;
		}
		if (count($counts)) {
			arsort($counts);
			$keys = array_keys($counts);
			return $this->rootParentBx = $keys[0];
		}
		return $this->rootParentBx = $this->bx->getTopSectionId();
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

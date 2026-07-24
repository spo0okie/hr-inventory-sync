<?php
require_once dirname(__FILE__) . "/CArmsWriter.php";
require_once dirname(__FILE__) . "/../Abstract/COrgStructureStorage.php";
require_once dirname(__FILE__) . "/../SAP/CSapAbsentsStructure.php";

/*
 * Оркестрация приёма источника (SAP/1C) в ARMS через REST.
 *
 * Читает уже загруженные объекты оргструктуры и сотрудников (COrgStructureStorage:
 * getParent/hasParent/getItem...) и заливает их в ARMS через CArmsWriter (upsert по
 * натуральному ключу). Это REST-замена прямых SQL-INSERT/UPDATE из inventory-SAP.php.
 *
 * Разложение по юрлицам — как в inventory-SAP.php: $inventoryOrgs сопоставляет
 * Objid верхнего узла SAP с org_id в ARMS; для каждого узла/сотрудника юрлицо
 * определяется поиском такого предка (hasParent).
 */
class CArmsIngest {

	private CArmsWriter $writer;
	/** @var array Objid(SAP) => org_id(ARMS) */
	private array $inventoryOrgs;

	public function __construct(CArmsWriter $writer, array $inventoryOrgs) {
		$this->writer = $writer;
		$this->inventoryOrgs = $inventoryOrgs;
	}

	/* Определяет org_id (ARMS) по принадлежности узла к юрлицу в дереве SAP. */
	private function orgIdFor($sapOrg, $nodeId): ?int {
		//пустой или отсутствующий узел (напр. битый/пустой Orgeh у сотрудника) — не юрлицо
		if (!strlen((string)$nodeId) || !$sapOrg->exist($nodeId)) return null;
		$orgId = null;
		foreach ($this->inventoryOrgs as $sapObjid => $invId)
			if ($sapOrg->hasParent($nodeId, $sapObjid)) $orgId = $invId;
		return $orgId;
	}

	/*
	 * Приём оргструктуры. Objid(SAP) -> hr_id(ARMS), Orgtx -> name, родитель -> parent_hr_id.
	 * @return array счётчики [processed, skipped]
	 */
	public function ingestOrgStruct($sapOrg): array {
		//карты hr_id=>id по каждому юрлицу (чтобы upsert решал POST/PUT без запроса на узел)
		$maps = [];
		foreach (array_unique(array_values($this->inventoryOrgs)) as $orgId)
			$maps[$orgId] = $this->writer->fetchOrgMap($orgId);

		$processed = 0; $skipped = 0;
		foreach ($sapOrg->getIds() as $id) {
			$orgId = $this->orgIdFor($sapOrg, $id);
			if (!$orgId) { $skipped++; continue; }
			$hr = $sapOrg->getItemField($id, 'Objid');
			$name = $sapOrg->getItemField($id, 'Orgtx');
			$parent = $sapOrg->getParent($id);
			$parentHr = $parent ? $sapOrg->getItemField($parent, 'Objid') : '';
			$this->writer->upsertOrgStruct($orgId, $hr, $name, $parentHr, $maps[$orgId]);
			$processed++;
		}
		return ['processed' => $processed, 'skipped' => $skipped];
	}

	/*
	 * Соответствие ID типа отсутствия Bitrix -> нормализованный код ARMS.
	 * Группировка кодов SAP (Awart) по типам остаётся в согласованной таблице
	 * CSapAbsentsStructure::$absentsTypes — здесь только конвертация её результата.
	 */
	public static array $absTypeCodes = [
		8   => 'VACATION',
		9   => 'ASSIGNMENT',
		10  => 'LEAVESICK',
		11  => 'LEAVEMATERNITY',
		12  => 'LEAVEUNPAYED',
		13  => 'UNKNOWN',
		14  => 'OTHER',
		293 => 'PERSONAL',
	];

	/*
	 * Текст командировки по данным SAP: "Командировка в {Город} ({Страна})".
	 * Продления не различаются (в SAP нет надёжного признака), даты не включаются
	 * (период и так есть у отсутствия). Страна достраивается из ISO-кода CountryTo
	 * (intl, по-русски), только если CityTo сам не содержит расшифровку в скобках
	 * (формат CityTo непоследователен: "Иркутск (Россия)" vs "г. Каир").
	 * @param array $a запись отсутствия SAP
	 * @return string|null текст или null (нет города — пусть выгрузка возьмёт descr типа)
	 */
	public static function tripComment(array $a): ?string {
		$city = trim((string)($a['CityTo'] ?? ''));
		if (!strlen($city)) return null;
		if (strpos($city, '(') === false) {
			$iso = trim((string)($a['CountryTo'] ?? ''));
			if (strlen($iso) && class_exists('Locale')) {
				$country = Locale::getDisplayRegion('-' . $iso, 'ru');
				if (strlen($country) && strcasecmp($country, $iso) !== 0) $city .= " ($country)";
			}
		}
		return "Командировка в $city";
	}

	/*
	 * Приём отсутствий из SAP в ARMS.
	 *
	 * external_id = составной ключ источника Pernr-Awart-Begda-Endda (он же ключ
	 * записи в CSapAbsentsStructure). Awart нормализуется в код типа ARMS;
	 * неизвестные коды пропускаются (как в absence.php — никогда не угадываются).
	 * Сотрудник резолвится по табельному через карту employee_id -> users.id ARMS;
	 * отсутствия сотрудников, которых нет в ARMS, пропускаются.
	 *
	 * Обратный проход: отсутствия source=sap, исчезнувшие из выгрузки SAP,
	 * удаляются из ARMS (manual и другие источники не трогаются).
	 *
	 * @param CSapAbsentsStructure $sapAbsents загруженные отсутствия SAP
	 * @param int|null $limit ограничение (для тестов)
	 * @return array счётчики
	 */
	public function ingestAbsences($sapAbsents, $limit = null): array {
		//карта табельный -> id ARMS по всем сотрудникам (табельник уникален между орг-ми)
		$empMap = [];
		foreach ($this->writer->fetchUsersMap() as $emp => $uid) $empMap[$emp] = $uid;

		//карта уже существующих в ARMS отсутствий источника sap
		$existing = $this->writer->fetchAbsenceMap('sap');
		$seen = [];

		$processed = 0; $skippedUser = 0; $skippedType = 0;
		foreach ($sapAbsents->getIds() as $id) {
			if (!is_null($limit) && $processed >= $limit) break;
			$a = $sapAbsents->getItem($id);

			//нормализация типа: Awart -> Bitrix ID (согласованная таблица) -> код ARMS
			$bxType = CSapAbsentsStructure::absTypeFromSap($a['Awart']);
			$code = static::$absTypeCodes[$bxType] ?? null;
			if (is_null($code)) { $skippedType++; continue; }

			//резолв сотрудника по табельному
			$userId = $empMap[(string)$a['Pernr']] ?? null;
			if (is_null($userId)) { $skippedUser++; continue; }

			//для командировок в comment кладётся направление ("Командировка в ..."),
			//выгрузка в Bitrix использует его как название; для прочих — текст источника
			$comment = ($code === 'ASSIGNMENT') ? static::tripComment($a) : ($a['Abstext'] ?? null);

			$this->writer->upsertAbsence([
				'user_id'     => $userId,
				'type'        => $code,
				'date_from'   => $a['Begda'],
				'date_to'     => $a['Endda'],
				'comment'     => $comment,
				'source'      => 'sap',
				'external_id' => $id,
			]);
			$seen[$id] = true;
			$processed++;
		}

		//обратный проход: чистим sap-отсутствия, которых больше нет в источнике
		//(только при полном проходе — с limit сверка была бы ложной)
		$removed = 0;
		if (is_null($limit)) {
			foreach ($existing as $extId => $armsId) {
				if (!isset($seen[$extId]) && !$sapAbsents->exist($extId)) {
					$this->writer->delete('absences', $armsId);
					$removed++;
				}
			}
		}

		return [
			'processed'   => $processed,
			'skippedUser' => $skippedUser,
			'skippedType' => $skippedType,
			'removed'     => $removed,
		];
	}

	/*
	 * Приём сотрудников. Признак увольнения на границе: внутренний 'Уволен'/'' -> 0/1.
	 * @param int|null $limit ограничение числа записей (для тестов)
	 * @return array счётчики [processed, skipped]
	 */
	public function ingestUsers($sapUsers, $sapOrg, $limit = null): array {
		$processed = 0; $skipped = 0;
		foreach ($sapUsers->getIds() as $id) {
			if (!is_null($limit) && $processed >= $limit) break;
			$u = $sapUsers->getItem($id);
			$orgId = $this->orgIdFor($sapOrg, $u['Orgeh'] ?? '');
			if (!$orgId) { $skipped++; continue; }
			$fields = [
				'employee_id' => (string)$u['Pernr'],
				'Orgeh'       => (string)($u['Orgeh'] ?? ''),
				'Doljnost'    => $u['Doljnost'] ?? '',
				'Ename'       => $u['Ename'] ?? '',
				'Persg'       => (int)($u['Persg'] ?? 0),
				'Uvolen'      => (($u['Uvolen'] ?? '') === 'Уволен') ? 1 : 0,
				'Bday'        => $u['Gbdat'] ?? '',
				'uid'         => $u['uid'] ?? '',
				'employ_date' => $u['Employ_date'] ?? null,
				'resign_date' => $u['Resign_date'] ?? null,
			];
			$this->writer->upsertUser($orgId, $fields);
			$processed++;
		}
		return ['processed' => $processed, 'skipped' => $skipped];
	}
}

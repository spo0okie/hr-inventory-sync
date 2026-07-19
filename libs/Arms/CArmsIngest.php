<?php
require_once dirname(__FILE__) . "/CArmsWriter.php";

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

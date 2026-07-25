<?php
require_once dirname(__FILE__) . "/CArmsRestClient.php";

/*
 * Запись данных в инвентаризацию ARMS через REST (плечо приёма SAP/1C -> ARMS).
 *
 * Идемпотентный upsert по натуральному ключу: поиск существующей записи, затем
 * POST (создать) или PUT (обновить). Пишущая логика вынесена сюда, транспорт —
 * в CArmsRestClient.
 *
 * Ключи:
 *  - оргструктура: (org_id, hr_id) — уникальна в модели ARMS;
 *  - сотрудник:    (org_id, employee_id) — трудоустройство.
 *
 * Признак увольнения на границе: внутренний boolean -> число 0/1 для ARMS.
 */
class CArmsWriter {

	private CArmsRestClient $client;

	/** dry-run: не писать, только логировать намерение */
	public bool $dryRun = false;

	public function __construct(CArmsRestClient $client) {
		$this->client = $client;
	}

	/*
	 * Карта hr_id => id записи ARMS для организации (для upsert оргструктуры без
	 * запроса на каждый узел; фильтра по hr_id в API нет, поэтому тянем всю орг).
	 */
	public function fetchOrgMap($orgId): array {
		$map = [];
		foreach ($this->client->getAll('org-struct', ['org_id' => $orgId]) as $r)
			$map[(string)$r['hr_id']] = $r['id'];
		return $map;
	}

	/*
	 * Upsert подразделения. $existingByHr — карта из fetchOrgMap(), обновляется на месте.
	 * @return mixed id записи ARMS (или null в dry-run при создании)
	 */
	public function upsertOrgStruct($orgId, $hrId, $name, $parentHr, array &$existingByHr) {
		$hrId = (string)$hrId;
		$body = [
			'org_id'       => $orgId,
			'hr_id'        => $hrId,
			'parent_hr_id' => (string)$parentHr,
			'name'         => $name,
		];
		if (isset($existingByHr[$hrId])) {
			$id = $existingByHr[$hrId];
			if ($this->dryRun) { echo "[DRY] PUT org-struct/$id (hr=$hrId, name=$name)\n"; return $id; }
			$this->client->update('org-struct', $id, $body);
			return $id;
		}
		if ($this->dryRun) { echo "[DRY] POST org-struct (hr=$hrId, name=$name)\n"; return null; }
		$res = $this->client->create('org-struct', $body);
		$id = $res['id'] ?? null;
		if (!is_null($id)) $existingByHr[$hrId] = $id;
		return $id;
	}

	/*
	 * Upsert сотрудника по (org_id, employee_id).
	 * @param array $fields поля ARMS (employee_id, Orgeh, Doljnost, Ename, Persg,
	 *                      Uvolen(0/1), Bday, uid, ... ) — org_id проставляется тут
	 * @param mixed $knownId id существующей записи, если вызывающий уже знает его
	 *                       из карты (fetchUsersMap): null — записи точно нет,
	 *                       false — неизвестно (сходить в /search)
	 * @return mixed id записи ARMS (или null в dry-run при создании)
	 */
	public function upsertUser($orgId, array $fields, $knownId = false) {
		$body = $fields + ['org_id' => $orgId];
		$existing = ($knownId === false)
			? $this->client->findOne('users', [
				'org_id'      => $orgId,
				'employee_id' => $fields['employee_id'],
			])
			: (is_null($knownId) ? null : ['id' => $knownId]);
		if (!is_null($existing)) {
			$id = $existing['id'];
			if ($this->dryRun) { echo "[DRY] PUT users/$id (emp {$fields['employee_id']})\n"; return $id; }
			$this->client->update('users', $id, $body);
			return $id;
		}
		if ($this->dryRun) { echo "[DRY] POST users (emp {$fields['employee_id']})\n"; return null; }
		$res = $this->client->create('users', $body);
		return $res['id'] ?? null;
	}

	/*
	 * Карта табельный (employee_id) => id записи ARMS по всем сотрудникам.
	 * Табельный уникален между организациями.
	 */
	public function fetchUsersMap(): array {
		$map = [];
		foreach ($this->client->getAll('users') as $r)
			$map[(string)$r['employee_id']] = $r['id'];
		return $map;
	}

	/*
	 * Upsert отсутствия по ключу идемпотентности (source, external_id).
	 * @param array $fields поля ARMS: user_id, type, date_from, date_to, comment, source, external_id
	 * @param mixed $knownId id существующей записи из карты (fetchAbsenceMap):
	 *                       null — записи точно нет, false — неизвестно (в /search)
	 * @return mixed id записи ARMS (или null в dry-run при создании)
	 */
	public function upsertAbsence(array $fields, $knownId = false) {
		$existing = ($knownId === false)
			? $this->client->findOne('absences', [
				'source'      => $fields['source'],
				'external_id' => $fields['external_id'],
			])
			: (is_null($knownId) ? null : ['id' => $knownId]);
		if (!is_null($existing)) {
			$id = $existing['id'];
			if ($this->dryRun) { echo "[DRY] PUT absences/$id ({$fields['external_id']})\n"; return $id; }
			$this->client->update('absences', $id, $fields);
			return $id;
		}
		if ($this->dryRun) { echo "[DRY] POST absences ({$fields['external_id']})\n"; return null; }
		$res = $this->client->create('absences', $fields);
		return $res['id'] ?? null;
	}

	/*
	 * Все отсутствия источника (для обратного прохода).
	 * @return array external_id => id записи ARMS
	 */
	public function fetchAbsenceMap(string $source): array {
		$map = [];
		foreach ($this->client->getAll('absences', ['source' => $source]) as $r)
			$map[(string)$r['external_id']] = $r['id'];
		return $map;
	}

	/*
	 * Синхронизация фотографии сотрудника (скан, привязанный к users_id).
	 *
	 * Скан-портрет именуется "photo-{pernr}-{date}" — SAP-дата фото хранится в
	 * имени, т.к. fileDate скана = момент загрузки, а не дата фотографии.
	 * Портрет по конвенции ARMS = последнее по дате изображение сотрудника,
	 * поэтому устаревшие фото-сканы этого pernr удаляются.
	 *
	 * $getBlob вызывается только если фото реально нужно обновить (дорогое
	 * обращение к SAP откладывается до необходимости).
	 *
	 * @param mixed    $userId  id сотрудника в ARMS
	 * @param string   $pernr   табельный
	 * @param string   $date    дата фото из SAP (PhotoDate, Y-m-d)
	 * @param callable $getBlob fn(): ?string — бинарник изображения (jpeg) или null
	 * @return string  ok|updated|fetch_failed
	 */
	public function syncUserPhoto($userId, string $pernr, string $date, callable $getBlob): string {
		$want = "photo-$pernr-$date";
		$have = false;
		$stale = [];
		foreach ($this->client->getAll('scans', ['users_id' => $userId, 'expand' => 'name']) as $s) {
			//имя может приходить с расширением (зависит от версии ARMS) — сравниваем без него
			$name = preg_replace('/\.[a-z0-9]+$/i', '', (string)($s['name'] ?? ''));
			if (strpos($name, "photo-$pernr-") !== 0) continue; //чужие/не-фото сканы не трогаем
			if ($name === $want) { $have = true; continue; }
			$stale[] = $s['id'];
		}

		if (!$have) {
			$blob = $getBlob();
			if (is_null($blob) || !strlen($blob)) return 'fetch_failed'; //старые фото не трогаем
			if ($this->dryRun) {
				echo "[DRY] upload scans/$want.jpg (user $userId)\n";
			} else {
				$tmp = tempnam(sys_get_temp_dir(), 'sapph');
				file_put_contents($tmp, $blob);
				try {
					$res = $this->client->uploadFile('scans/upload', $tmp, "$want.jpg", ['users_id' => $userId]);
					if (!isset($res['id'])) throw new RuntimeException('upload не вернул id: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
				} finally {
					unlink($tmp);
				}
			}
		}

		foreach ($stale as $sid) $this->delete('scans', $sid);
		return $have ? 'ok' : 'updated';
	}

	/*
	 * Удаление записи (для обратного прохода: чистка того, чего больше нет в источнике).
	 */
	public function delete(string $resource, $id): void {
		if ($this->dryRun) { echo "[DRY] DELETE $resource/$id\n"; return; }
		$this->client->delete($resource, $id);
	}
}

<?php
require_once dirname(__FILE__) . "/CArmsRestClient.php";

/*
 * Запись данных в инвентаризацию ARMS через REST (плечо приёма SAP/1C -> ARMS).
 *
 * Идемпотентный upsert по натуральному ключу с локальным сравнением: существующие
 * записи загружаются пачкой (fetch*Map, ключ => полная запись), каждая новая
 * сравнивается с текущей по полям, и PUT уходит только при реальных отличиях.
 * В dry-run печатается диф полей ("поле: 'старое' -> 'новое'") — по нему видно,
 * что именно изменит боевой прогон.
 *
 * Ключи:
 *  - оргструктура: (org_id, hr_id) — уникальна в модели ARMS;
 *  - сотрудник:    (org_id, employee_id) — трудоустройство;
 *  - отсутствие:   (source, external_id).
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
	 * Отличия $new от $old по полям $new (сравнение строковое, null == '').
	 * @return array поле => [старое, новое]
	 */
	public static function diff(array $new, array $old): array {
		$changes = [];
		foreach ($new as $k => $v) {
			$oldV = $old[$k] ?? null;
			if ((string)($v ?? '') !== (string)($oldV ?? '')) $changes[$k] = [$oldV, $v];
		}
		return $changes;
	}

	/* Диф в человекочитаемую строку: поле: 'старое' -> 'новое', ... */
	public static function diffText(array $changes): string {
		$parts = [];
		foreach ($changes as $k => [$old, $new])
			$parts[] = "$k: '" . ($old ?? '') . "' -> '" . ($new ?? '') . "'";
		return implode(', ', $parts);
	}

	/*
	 * Существующие подразделения организации: hr_id => полная запись.
	 * (фильтра по hr_id в API нет, поэтому тянем организацию целиком)
	 *
	 * Дубли hr_id внутри организации — признак данных легаси-формата (старый
	 * механизм прямой записи в SQL клал в id Objid узла, а в hr_id — Objid
	 * РОДИТЕЛЯ, одинаковый у всех детей). Работать поверх таких данных нельзя
	 * (карта неоднозначна, PUT упрётся в unique-валидацию) — останавливаемся
	 * с инструкцией по конвертации до первой записи.
	 */
	public function fetchOrgMap($orgId): array {
		$map = [];
		$dupes = [];
		foreach ($this->client->getAll('org-struct', ['org_id' => $orgId]) as $r) {
			$hr = (string)$r['hr_id'];
			if (isset($map[$hr])) $dupes[$hr][] = $r['id'];
			$map[$hr] = $r;
		}
		if (count($dupes)) {
			$hr = array_key_first($dupes);
			throw new RuntimeException(
				"в оргструктуре ARMS (org_id=$orgId) дубли hr_id — данные похожи на легаси-формат " .
				"(hr_id хранит родителя, id — Objid узла). Пример: hr_id='$hr' у записей id=" .
				implode(',', $dupes[$hr]) . " и ещё. Перед приёмом конвертируйте данные на стороне ARMS: " .
				"UPDATE org_struct SET parent_hr_id = hr_id, hr_id = id; — либо очистите org_struct " .
				"(приём пересоздаст структуру, users.Orgeh конвертации не требует)."
			);
		}
		return $map;
	}

	/*
	 * Upsert подразделения. $existingByHr — карта из fetchOrgMap(), обновляется на месте.
	 * @return array ['id' => mixed, 'action' => 'created'|'updated'|'ok']
	 */
	public function upsertOrgStruct($orgId, $hrId, $name, $parentHr, array &$existingByHr): array {
		$hrId = (string)$hrId;
		$body = [
			'org_id'       => $orgId,
			'hr_id'        => $hrId,
			'parent_hr_id' => (string)$parentHr,
			'name'         => $name,
		];
		if (isset($existingByHr[$hrId])) {
			$row = $existingByHr[$hrId];
			$id = $row['id'];
			$changes = static::diff($body, $row);
			if (!count($changes)) return ['id' => $id, 'action' => 'ok'];
			if ($this->dryRun) {
				echo "[DRY] PUT org-struct/$id (hr=$hrId): " . static::diffText($changes) . "\n";
			} else {
				$this->client->update('org-struct', $id, $body);
			}
			return ['id' => $id, 'action' => 'updated'];
		}
		if ($this->dryRun) {
			echo "[DRY] POST org-struct (hr=$hrId, name=$name)\n";
			return ['id' => null, 'action' => 'created'];
		}
		$res = $this->client->create('org-struct', $body);
		$id = $res['id'] ?? null;
		if (!is_null($id)) $existingByHr[$hrId] = $res;
		return ['id' => $id, 'action' => 'created'];
	}

	/*
	 * Все существующие сотрудники (полные записи, включая записи без табельника).
	 */
	public function fetchUsers(): array {
		return $this->client->getAll('users');
	}

	/*
	 * Существующие сотрудники: employee_id => полная запись.
	 * Табельный уникален между организациями. Записи без табельника в карту
	 * не попадают (их адресуют по uid/ФИО, см. CArmsIngest::ingestUsers).
	 */
	public function fetchUsersMap(): array {
		$map = [];
		foreach ($this->fetchUsers() as $r)
			if (strlen((string)$r['employee_id'])) $map[(string)$r['employee_id']] = $r;
		return $map;
	}

	/*
	 * Upsert сотрудника по (org_id, employee_id).
	 * @param array $fields поля ARMS (employee_id, Orgeh, Doljnost, Ename, Persg,
	 *                      Uvolen(0/1), Bday, uid, ...) — org_id проставляется тут
	 * @param mixed $known  запись из карты (fetchUsersMap): null — записи точно нет,
	 *                      false — неизвестно (сходить в /search)
	 * @return array ['id' => mixed, 'action' => 'created'|'updated'|'ok']
	 */
	public function upsertUser($orgId, array $fields, $known = false): array {
		$body = $fields + ['org_id' => $orgId];
		if ($known === false)
			$known = $this->client->findOne('users', [
				'org_id'      => $orgId,
				'employee_id' => $fields['employee_id'],
			]);
		if (is_array($known)) {
			$id = $known['id'];
			$changes = static::diff($body, $known);
			if (!count($changes)) return ['id' => $id, 'action' => 'ok'];
			if ($this->dryRun) {
				echo "[DRY] PUT users/$id (emp {$fields['employee_id']}): " . static::diffText($changes) . "\n";
			} else {
				$this->client->update('users', $id, $body);
			}
			return ['id' => $id, 'action' => 'updated'];
		}
		if ($this->dryRun) {
			echo "[DRY] POST users (emp {$fields['employee_id']}, {$fields['Ename']})\n";
			return ['id' => null, 'action' => 'created'];
		}
		$res = $this->client->create('users', $body);
		return ['id' => $res['id'] ?? null, 'action' => 'created'];
	}

	/*
	 * Существующие отсутствия источника: external_id => полная запись.
	 */
	public function fetchAbsenceMap(string $source): array {
		$map = [];
		foreach ($this->client->getAll('absences', ['source' => $source]) as $r)
			$map[(string)$r['external_id']] = $r;
		return $map;
	}

	/*
	 * Upsert отсутствия по ключу идемпотентности (source, external_id).
	 * @param array $fields user_id, type, date_from, date_to, comment, source, external_id
	 * @param mixed $known  запись из карты (fetchAbsenceMap): null — записи точно нет,
	 *                      false — неизвестно (в /search)
	 * @return array ['id' => mixed, 'action' => 'created'|'updated'|'ok']
	 */
	public function upsertAbsence(array $fields, $known = false): array {
		if ($known === false)
			$known = $this->client->findOne('absences', [
				'source'      => $fields['source'],
				'external_id' => $fields['external_id'],
			]);
		if (is_array($known)) {
			$id = $known['id'];
			$changes = static::diff($fields, $known);
			if (!count($changes)) return ['id' => $id, 'action' => 'ok'];
			if ($this->dryRun) {
				echo "[DRY] PUT absences/$id ({$fields['external_id']}): " . static::diffText($changes) . "\n";
			} else {
				$this->client->update('absences', $id, $fields);
			}
			return ['id' => $id, 'action' => 'updated'];
		}
		if ($this->dryRun) {
			echo "[DRY] POST absences ({$fields['external_id']}: {$fields['type']} {$fields['date_from']}..{$fields['date_to']})\n";
			return ['id' => null, 'action' => 'created'];
		}
		$res = $this->client->create('absences', $fields);
		return ['id' => $res['id'] ?? null, 'action' => 'created'];
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
			if ($this->dryRun) {
				//в dry-run бинарник у SAP не запрашивается — печатаем только намерение
				echo "[DRY] upload scans/$want.jpg (user $userId, фото от $date)\n";
			} else {
				$blob = $getBlob();
				if (is_null($blob) || !strlen($blob)) return 'fetch_failed'; //старые фото не трогаем
				//расширение — по фактическому содержимому: валидатор ARMS сверяет
				//расширение имени с MIME файла, несовпадение (SAP может отдать не-JPEG) режется
				$ext = static::imageExt($blob);
				$tmp = tempnam(sys_get_temp_dir(), 'sapph');
				file_put_contents($tmp, $blob);
				try {
					$res = $this->client->uploadFile('scans/upload', $tmp, "$want.$ext", ['users_id' => $userId]);
					if (!isset($res['id']))
						//размер в сообщении: «Загрузите файл» при непустом файле обычно
						//означает лимиты PHP на стороне ARMS (upload_max_filesize/post_max_size/file_uploads)
						throw new RuntimeException(
							"upload $want.$ext (" . strlen($blob) . " байт) не вернул id: " .
							json_encode($res, JSON_UNESCAPED_UNICODE)
						);
				} finally {
					unlink($tmp);
				}
			}
		}

		foreach ($stale as $sid) $this->delete('scans', $sid);
		return $have ? 'ok' : 'updated';
	}

	/*
	 * Расширение файла изображения по его содержимому (jpg по умолчанию).
	 */
	public static function imageExt(string $blob): string {
		$mime = '';
		if (function_exists('finfo_buffer')) {
			$f = finfo_open(FILEINFO_MIME_TYPE);
			$mime = (string)finfo_buffer($f, $blob);
			finfo_close($f);
		}
		return match ($mime) {
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/bmp', 'image/x-ms-bmp' => 'bmp',
			'image/tiff' => 'tiff',
			default      => 'jpg',
		};
	}

	/*
	 * Удаление записи (для обратного прохода: чистка того, чего больше нет в источнике).
	 */
	public function delete(string $resource, $id): void {
		if ($this->dryRun) { echo "[DRY] DELETE $resource/$id\n"; return; }
		$this->client->delete($resource, $id);
	}
}

<?php
require_once dirname(__FILE__) . "/CArmsWriter.php";
require_once dirname(__FILE__) . "/../Abstract/COrgStructureStorage.php";
require_once dirname(__FILE__) . "/../SAP/CSapAbsentsStructure.php";
require_once dirname(__FILE__) . "/../Abstract/CAbsenceTypes.php";

/*
 * Оркестрация приёма источника (SAP/1C) в ARMS через REST.
 *
 * Читает уже загруженные объекты оргструктуры и сотрудников (COrgStructureStorage:
 * getParent/hasParent/getItem...) и заливает их в ARMS через CArmsWriter (upsert по
 * натуральному ключу).
 *
 * Разложение по юрлицам: $inventoryOrgs сопоставляет Objid верхнего узла SAP с
 * org_id в ARMS; для каждого узла/сотрудника юрлицо определяется поиском такого
 * предка (hasParent).
 */
class CArmsIngest {

	/** порог ошибок подряд при приёме отсутствий: дальше это уже не данные, а API */
	const MAX_FAIL_STREAK = 20;

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
	 * Существующие записи сравниваются локально, PUT только при отличиях.
	 * @return array счётчики [created, updated, ok, skipped]
	 */
	public function ingestOrgStruct($sapOrg): array {
		//карты hr_id=>запись по каждому юрлицу (сравнение пачкой, без запроса на узел)
		$maps = [];
		foreach (array_unique(array_values($this->inventoryOrgs)) as $orgId)
			$maps[$orgId] = $this->writer->fetchOrgMap($orgId);

		$c = ['created' => 0, 'updated' => 0, 'ok' => 0, 'skipped' => 0];
		foreach ($sapOrg->getIds() as $id) {
			$orgId = $this->orgIdFor($sapOrg, $id);
			if (!$orgId) { $c['skipped']++; continue; }
			$hr = $sapOrg->getItemField($id, 'Objid');
			$name = $sapOrg->getItemField($id, 'Orgtx');
			$parent = $sapOrg->getParent($id);
			$parentHr = $parent ? $sapOrg->getItemField($parent, 'Objid') : '';
			$res = $this->writer->upsertOrgStruct($orgId, $hr, $name, $parentHr, $maps[$orgId]);
			$c[$res['action']]++;
		}
		return $c;
	}

	/*
	 * Код запланированного, но ещё не утверждённого отпуска.
	 *
	 * В SAP такие записи приходят из плановой выгрузки заблокированными (Sprps=X,
	 * Sperrkennzeichen инфотипа) — тип у них тот же основной отпуск, отличается статус.
	 * Отдельный код нужен, чтобы инвентаризация и портал показывали их как
	 * «Запланированный отпуск»: даты у них могут ещё сдвинуться.
	 *
	 * Awart в источнике при этом не подменяется: он входит в составной ключ записи
	 * (external_id), а утверждение отпуска — смена статуса той же записи, а не новая.
	 * Так ключ остаётся натуральным, а upsert превращает плановый отпуск в обычный
	 * без пересоздания записи.
	 */
	const ABS_TYPE_PLANNED = 'VACATION_PLAN';

	/*
	 * Соответствие ID типа отсутствия Bitrix -> нормализованный код ARMS живёт в
	 * общем словаре CAbsenceTypes: он же нужен выгрузке ARMS -> Bitrix на другом
	 * хосте, а две копии таблицы разъехались бы молча.
	 * Группировка кодов SAP (Awart) по типам остаётся в согласованной таблице
	 * CSapAbsentsStructure::$absentsTypes — здесь только конвертация её результата.
	 */

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
	 * Записи, на которых API ответил ошибкой, считаются в failed и пропускаются
	 * (в ARMS на (source, external_id) уникальный индекс, и сам upsert умеет
	 * подобрать существующую запись, если её не было в карте — см. CArmsWriter).
	 *
	 * @param CSapAbsentsStructure $sapAbsents загруженные отсутствия SAP
	 * @param int|null $limit ограничение (для тестов)
	 * @return array счётчики
	 */
	public function ingestAbsences($sapAbsents, $limit = null): array {
		//карта табельный -> id ARMS по всем сотрудникам (табельник уникален между орг-ми)
		$empMap = [];
		foreach ($this->writer->fetchUsersMap() as $emp => $row) $empMap[$emp] = $row['id'];

		//карта уже существующих в ARMS отсутствий источника sap (для сравнения пачкой)
		$existing = $this->writer->fetchAbsenceMap('sap');
		$seen = [];

		$c = ['created' => 0, 'updated' => 0, 'ok' => 0];
		$processed = 0; $skippedUser = 0; $skippedType = 0; $failed = 0; $failStreak = 0;
		$planned = 0; $plannedOther = 0;
		foreach ($sapAbsents->getIds() as $id) {
			if (!is_null($limit) && $processed >= $limit) break;
			$a = $sapAbsents->getItem($id);

			//нормализация типа: Awart -> Bitrix ID (согласованная таблица) -> код ARMS
			$bxType = CSapAbsentsStructure::absTypeFromSap($a['Awart']);
			$code = CAbsenceTypes::codeByBxId($bxType);
			if (is_null($code)) { $skippedType++; continue; }

			//заблокированная запись инфотипа = отпуск запланирован, но не утверждён
			if (($a['Sprps'] ?? '') === 'X') {
				if ($code === 'VACATION') { $code = static::ABS_TYPE_PLANNED; $planned++; }
				//флаг на другом типе — сигнал, а не повод угадывать: грузим как обычное
				else $plannedOther++;
			}

			//резолв сотрудника по табельному
			$userId = $empMap[(string)$a['Pernr']] ?? null;
			if (is_null($userId)) { $skippedUser++; continue; }

			//для командировок в comment кладётся направление ("Командировка в ..."),
			//выгрузка в Bitrix использует его как название; для прочих — текст источника
			$comment = ($code === 'ASSIGNMENT') ? static::tripComment($a) : ($a['Abstext'] ?? null);

			//отдельная битая запись не должна ронять весь приём: считаем и идём дальше
			//(в $seen такая не попадает, но обратный проход её не тронет — она есть в SAP)
			try {
				$res = $this->writer->upsertAbsence([
					'user_id'     => $userId,
					'type'        => $code,
					'date_from'   => $a['Begda'],
					'date_to'     => $a['Endda'],
					'comment'     => $comment,
					'source'      => 'sap',
					'external_id' => $id,
				], $existing[$id] ?? null);
			} catch (RuntimeException $e) {
				fwrite(STDERR, "ОШИБКА: отсутствие $id: " . $e->getMessage() . "\n");
				$failed++;
				//сыплется подряд — дело не в записях, а в API: дальше идти бессмысленно
				if (++$failStreak > static::MAX_FAIL_STREAK)
					throw new RuntimeException("приём отсутствий: " . static::MAX_FAIL_STREAK .
						" ошибок подряд (всего $failed), прерываюсь");
				continue;
			}
			$failStreak = 0;
			$c[$res['action']]++;
			$seen[$id] = true;
			$processed++;
		}

		//обратный проход: чистим sap-отсутствия, которых больше нет в источнике
		//(только при полном проходе — с limit сверка была бы ложной)
		$removed = 0;
		if (is_null($limit)) {
			foreach ($existing as $extId => $row) {
				if (!isset($seen[$extId]) && !$sapAbsents->exist($extId)) {
					$this->writer->delete('absences', $row['id']);
					$removed++;
				}
			}
		}

		if ($plannedOther)
			fwrite(STDERR, "ВНИМАНИЕ: записей с Sprps=X не на отпуске: $plannedOther " .
				"(загружены как обычные отсутствия)\n");

		return $c + [
			'skippedUser' => $skippedUser,
			'skippedType' => $skippedType,
			'removed'     => $removed,
			'failed'      => $failed,
			'planned'     => $planned,
		];
	}

	/*
	 * Приём фотографий сотрудников из SAP в ARMS (сканы, привязанные к users_id).
	 *
	 * У кого фото есть и когда оно обновлялось — известно из выгрузки сотрудников
	 * (PhotoDate); сам бинарник запрашивается через $photoFetcher только для тех,
	 * у кого дата не совпала с уже загруженной (SAP-дата хранится в имени скана).
	 * Уволенные и сотрудники вне настроенных юрлиц пропускаются.
	 *
	 * @param CSapUserList $sapUsers сотрудники SAP (с полем PhotoDate)
	 * @param CSapOrgStructure $sapOrg оргструктура (для раскладки по юрлицам)
	 * @param callable $photoFetcher fn(string $pernr): ?string — jpeg-бинарник или null
	 * @param int|null $limit ограничение (для тестов)
	 * @return array счётчики
	 */
	public function ingestPhotos($sapUsers, $sapOrg, callable $photoFetcher, $limit = null): array {
		$empMap = $this->writer->fetchUsersMap();

		$ok = 0; $updated = 0; $failed = 0;
		$noPhoto = 0; $skippedOrg = 0; $skippedUser = 0; $checked = 0;
		foreach ($sapUsers->getIds() as $id) {
			if (!is_null($limit) && $checked >= $limit) break;
			$u = $sapUsers->getItem($id);

			if (($u['Uvolen'] ?? '') === 'Уволен') continue;
			$date = (string)($u['PhotoDate'] ?? '');
			if (!strlen($date) || $date === '0000-00-00') { $noPhoto++; continue; }
			if (is_null($this->orgIdFor($sapOrg, $u['Orgeh'] ?? ''))) { $skippedOrg++; continue; }
			$pernr = (string)$u['Pernr'];
			$userId = isset($empMap[$pernr]) ? $empMap[$pernr]['id'] : null;
			if (is_null($userId)) { $skippedUser++; continue; }

			$checked++;
			//ошибка одного фото (лимиты загрузки, битый файл) не должна ронять весь прогон
			try {
				$res = $this->writer->syncUserPhoto($userId, $pernr, $date, fn() => $photoFetcher($pernr));
			} catch (RuntimeException $e) {
				echo "  фото $pernr: ОШИБКА: " . $e->getMessage() . "\n";
				$failed++;
				continue;
			}
			if ($res === 'ok') $ok++;
			elseif ($res === 'updated') $updated++;
			else $failed++;
		}
		return [
			'checked' => $checked, 'ok' => $ok, 'updated' => $updated, 'failed' => $failed,
			'noPhoto' => $noPhoto, 'skippedOrg' => $skippedOrg, 'skippedUser' => $skippedUser,
		];
	}

	/*
	 * Обратная синхронизация контактов ARMS -> SAP.
	 *
	 * Контакты (email, телефоны) ведутся не в SAP: email и мобильный приходят в ARMS
	 * из AD, внутренний номер — из самой инвентаризации. SAP же должен их получать
	 * обратно. Сверка — против значений SAP из выгрузки сотрудников (Email/ArbPhone/
	 * MobPhone), отправка — через $pusher (внешний скрипт SAP-прокси) по одному
	 * контакту: fn($pernr, $type, $value), типы SAP: 0010 email, 0020 внутренний,
	 * CELL мобильный.
	 *
	 * Семантика сверки — как в легаси (годы на проде):
	 *   - уволенные и сотрудники вне настроенных юрлиц пропускаются;
	 *   - email отправляется только непустой и только при регистронезависимом
	 *     отличии (пустой email в ARMS не затирает адрес в SAP);
	 *   - телефоны отправляются при любом отличии, включая пустое значение —
	 *     удаление номера в инвентаризации удаляет его и в SAP.
	 *
	 * @param CSapUserList $sapUsers сотрудники SAP (текущие значения контактов)
	 * @param CSapOrgStructure $sapOrg оргструктура (для раскладки по юрлицам)
	 * @param callable $pusher fn(string $pernr, string $type, string $value): void
	 * @param int|null $limit ограничение (для тестов)
	 * @return array счётчики
	 */
	public function pushContacts($sapUsers, $sapOrg, callable $pusher, $limit = null): array {
		$empMap = $this->writer->fetchUsersMap();

		$c = ['checked' => 0, 'email' => 0, 'phone' => 0, 'mobile' => 0,
			'skippedOrg' => 0, 'skippedUser' => 0];
		foreach ($sapUsers->getIds() as $id) {
			if (!is_null($limit) && $c['checked'] >= $limit) break;
			$u = $sapUsers->getItem($id);

			if (($u['Uvolen'] ?? '') === 'Уволен') continue;
			if (is_null($this->orgIdFor($sapOrg, $u['Orgeh'] ?? ''))) { $c['skippedOrg']++; continue; }
			$pernr = (string)$u['Pernr'];
			$arms = $empMap[$pernr] ?? null;
			if (is_null($arms)) { $c['skippedUser']++; continue; }
			$c['checked']++;

			$email = trim((string)($arms['Email'] ?? ''));
			if (strlen($email) && strcasecmp($email, trim((string)($u['Email'] ?? ''))) !== 0) {
				echo "  контакты $pernr: email SAP '" . trim((string)($u['Email'] ?? '')) . "' -> '$email'\n";
				$pusher($pernr, '0010', $email);
				$c['email']++;
			}

			$phone = trim((string)($arms['Phone'] ?? ''));
			if ($phone !== trim((string)($u['ArbPhone'] ?? ''))) {
				echo "  контакты $pernr: раб. тел SAP '" . trim((string)($u['ArbPhone'] ?? '')) . "' -> '$phone'\n";
				$pusher($pernr, '0020', $phone);
				$c['phone']++;
			}

			$mobile = trim((string)($arms['Mobile'] ?? ''));
			if ($mobile !== trim((string)($u['MobPhone'] ?? ''))) {
				echo "  контакты $pernr: моб. тел SAP '" . trim((string)($u['MobPhone'] ?? '')) . "' -> '$mobile'\n";
				$pusher($pernr, 'CELL', $mobile);
				$c['mobile']++;
			}
		}
		return $c;
	}

	/* Нормализация ФИО для сопоставления: регистр, ё->е, лишние пробелы. */
	public static function normName($name): string {
		$n = mb_strtolower(trim((string)$name));
		$n = str_replace('ё', 'е', $n);
		return preg_replace('/\s+/u', ' ', $n);
	}

	/*
	 * Приём сотрудников. Признак увольнения на границе: внутренний 'Уволен'/'' -> 0/1.
	 * Существующие записи сравниваются локально, PUT только при отличиях.
	 *
	 * Сопоставление с существующими записями ARMS: наличие ключа у записи делает
	 * его единственно допустимым способом её сматчить.
	 *   - запись с табельником (employee_id) матчится ТОЛЬКО по табельнику;
	 *   - запись без табельника, но с uid (хеш СНИЛС) — ТОЛЬКО по uid;
	 *   - запись без табельника и без uid — по ФИО;
	 *   - никто не сматчился — создаётся новая запись.
	 * Матч по uid/ФИО (усыновление заведённых вручную) — в пределах организации
	 * и только однозначный (двое ручных с одинаковым ФИО -> обе не усыновляются).
	 * Подготовка данных при подключении организации — см. README: неправильные
	 * кадровые ключи у ручных записей нужно очистить, выдуманный табельник может
	 * ложно сматчиться с настоящим.
	 *
	 * @param int|null $limit ограничение числа записей (для тестов)
	 * @return array счётчики [created, updated, adopted, ok, skipped]
	 */
	public function ingestUsers($sapUsers, $sapOrg, $limit = null): array {
		//все существующие сотрудники одной выборкой (сравнение пачкой, без /search)
		$rows = $this->writer->fetchUsers();

		//карта по табельнику + карты кандидатов на усыновление (записи без табельника);
		//ключи карт кандидатов — "org|значение", false = неоднозначно (двое и больше)
		$empMap = []; $byUid = []; $byName = [];
		foreach ($rows as $row) {
			$emp = (string)($row['employee_id'] ?? '');
			if (strlen($emp)) { $empMap[$emp] = $row; continue; } //есть табельник — матч только по нему
			$org = $row['org_id'];
			$uid = (string)($row['uid'] ?? '');
			if (strlen($uid)) {
				//есть uid — матч только по uid, по ФИО такую запись не ищем
				$k = $org . '|' . $uid;
				$byUid[$k] = isset($byUid[$k]) ? false : $row;
				continue;
			}
			$nn = static::normName($row['Ename'] ?? '');
			if (strlen($nn)) {
				$k = $org . '|' . $nn;
				$byName[$k] = isset($byName[$k]) ? false : $row;
			}
		}
		$claimed = []; //ручные записи, уже усыновлённые в этом прогоне

		$c = ['created' => 0, 'updated' => 0, 'adopted' => 0, 'ok' => 0, 'skipped' => 0];
		$processed = 0;
		foreach ($sapUsers->getIds() as $id) {
			if (!is_null($limit) && $processed >= $limit) break;
			$u = $sapUsers->getItem($id);
			$orgId = $this->orgIdFor($sapOrg, $u['Orgeh'] ?? '');
			if (!$orgId) { $c['skipped']++; continue; }
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
			//цепочка сопоставления: табельник -> uid -> ФИО -> новый
			$pernr = (string)$u['Pernr'];
			$known = $empMap[$pernr] ?? null;
			if (is_null($known)) {
				$adopted = null; $how = '';
				$uid = (string)($u['uid'] ?? '');
				if (strlen($uid)) {
					$cand = $byUid[$orgId . '|' . $uid] ?? null;
					if (is_array($cand) && !isset($claimed[$cand['id']])) { $adopted = $cand; $how = 'uid'; }
				}
				if (is_null($adopted)) {
					$nn = static::normName($u['Ename'] ?? '');
					if (strlen($nn)) {
						$cand = $byName[$orgId . '|' . $nn] ?? null;
						if (is_array($cand) && !isset($claimed[$cand['id']])) { $adopted = $cand; $how = 'ФИО'; }
					}
				}
				if (!is_null($adopted)) {
					$claimed[$adopted['id']] = true;
					$known = $adopted;
					echo "[ADOPT] ARMS #{$adopted['id']} '{$adopted['Ename']}' (emp '{$adopted['employee_id']}') <- табельный $pernr (по $how)\n";
					$c['adopted']++;
				}
			}

			$res = $this->writer->upsertUser($orgId, $fields, $known);
			$c[$res['action']]++;
			$processed++;
		}
		return $c;
	}
}

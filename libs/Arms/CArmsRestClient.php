<?php
/*
 * Тонкий REST-клиент к API инвентаризации ARMS.
 *
 * Транспорт для источника 'arms': оргструктура, сотрудники (и, в перспективе,
 * отсутствия) читаются через REST вместо прямого доступа к БД инвентаризации.
 *
 * ВНИМАНИЕ: файл загружается portal.php на хосте Bitrix с PHP 5.6 — синтаксис
 * держать совместимым с 5.6 (без ??, типов свойств/параметров/возвратов,
 * короткой деструктуризации [] и match). См. CLAUDE.md.
 *
 * Аутентификация — HTTP Basic сервисным пользователем ARMS. Опциональна: если
 * логин пуст, запрос уходит без авторизации (локальный инстанс с отключенным
 * RBAC отвечает и так). Постраничная выборка собирается по заголовкам
 * X-Pagination-Page-Count. Все методы работают с уже декодированным JSON.
 *
 * КРИТИЧНО: экшн index (GET /{resource}) отдаёт всё и игнорирует фильтры.
 * Фильтрация только через /{resource}/filter (список) и /{resource}/search
 * (одна запись, 404 если нет).
 */
class CArmsRestClient {
	/** @var string базовый URL API (https://host/web/api) */
	private $baseUrl;
	/** @var string */
	private $user;
	/** @var string */
	private $password;
	/** @var int таймаут запроса, сек */
	private $timeout;

	/** @var int размер страницы при постраничной выборке getAll() */
	public $perPage = 200;

	/** предохранитель от бесконечного листания, если сервер не отдаёт границу выборки */
	const MAX_PAGES = 1000;

	public function __construct($baseUrl, $user = '', $password = '', $timeout = 30) {
		$this->baseUrl  = rtrim($baseUrl, '/');
		$this->user     = $user;
		$this->password = $password;
		$this->timeout  = $timeout;
	}

	/*
	 * Один HTTP-запрос.
	 * @return array [int $httpCode, array $headers (ключи в нижнем регистре), mixed $decodedBody]
	 * @throws RuntimeException при сетевой ошибке или невалидном JSON
	 */
	private function request($method, $resource, $query = array(), $body = null) {
		$url = $this->baseUrl . '/' . ltrim($resource, '/');
		if ($query) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);

		$headers = array();
		$ch = curl_init($url);
		$reqHeaders = array('Accept: application/json');
		if ($body !== null) $reqHeaders[] = 'Content-Type: application/json';

		$opts = array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_HTTPHEADER     => $reqHeaders,
			CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
				$p = strpos($line, ':');
				if ($p !== false) $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
				return strlen($line);
			},
		);
		if (strlen($this->user)) {
			$opts[CURLOPT_USERPWD]  = $this->user . ':' . $this->password;
			$opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
		}
		if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);

		curl_setopt_array($ch, $opts);
		$raw = curl_exec($ch);
		if ($raw === false) {
			$err = curl_error($ch);
			curl_close($ch);
			throw new RuntimeException("ARMS API $method $url: $err");
		}
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$data = strlen($raw) ? json_decode($raw, true) : null;
		if (strlen($raw) && json_last_error() !== JSON_ERROR_NONE)
			throw new RuntimeException("ARMS API $method $url: невалидный JSON: " . json_last_error_msg());

		return array($code, $headers, $data);
	}

	/*
	 * Ошибка API с телом ответа: в теле ARMS отдаёт причину (403 RBAC, 422 валидация).
	 */
	private static function apiError($what, $code, $body) {
		$text = is_null($body) ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);
		if (strlen($text) > 300) $text = substr($text, 0, 300) . '...';
		return new RuntimeException("ARMS API $what -> HTTP $code" . (strlen($text) ? ": $text" : ''));
	}

	/*
	 * Полная выборка ресурса с фильтрами: собирает все страницы по X-Pagination-Page-Count.
	 *
	 * КРИТИЧНО: неполная выборка тихо ломает идемпотентность приёма — отсутствующую
	 * в карте запись сочтут несуществующей и создадут заново (дубль либо, при
	 * уникальном индексе, HTTP 500 на POST). Отсюда три предосторожности:
	 *
	 *  - сортировка (sort=id): LIMIT/OFFSET без ORDER BY не даёт СУБД никаких
	 *    обязательств по порядку, и страницы одной выборки могут пересечься —
	 *    часть записей приходит дважды, а часть не приходит вовсе;
	 *  - дедупликация по id: пересёкшиеся страницы не должны раздувать итог
	 *    (иначе сверка с total ничего не поймает — количество сойдётся);
	 *  - если заголовка с числом страниц нет (прокси срезал, старая версия API) —
	 *    листаем, пока страницы приходят полными.
	 *
	 * @param string $resource напр. 'users' или 'org-struct'
	 * @param array  $filter   фильтр, напр. ['org_id'=>1]
	 * @return array список записей (ассоциативные массивы)
	 */
	public function getAll($resource, $filter = array()) {
		//фильтрация в ARMS — через экшн /filter (index отдаёт всё без фильтра!)
		$path = $filter ? $resource . '/filter' : $resource;
		$out = array();
		$seen = array();
		$page = 1;
		$total = null;
		do {
			$query = $filter + array('sort' => 'id', 'page' => $page, 'per-page' => $this->perPage);
			list($code, $h, $data) = $this->request('GET', $path, $query);
			if ($code !== 200) throw static::apiError("GET $path", $code, $data);
			$got = is_array($data) ? count($data) : 0;
			if (is_array($data)) foreach ($data as $row) {
				if (isset($row['id'])) {
					if (isset($seen[$row['id']])) continue;
					$seen[$row['id']] = true;
				}
				$out[] = $row;
			}
			if (is_null($total) && isset($h['x-pagination-total-count'])) $total = (int)$h['x-pagination-total-count'];
			$more = isset($h['x-pagination-page-count'])
				? ($page < (int)$h['x-pagination-page-count'])
				: ($got >= $this->perPage);
			$page++;
		} while ($more && $page <= static::MAX_PAGES);

		if (!is_null($total) && count($out) < $total)
			fwrite(STDERR, "ВНИМАНИЕ: GET $path отдал " . count($out) . " записей из $total — выборка неполная " .
				"(страницы пересеклись или записи менялись во время выборки)\n");

		return $out;
	}

	/*
	 * Поиск ОДНОЙ записи по уникальному фильтру через /{resource}/search.
	 * @return array|null запись или null, если не найдено (ARMS отвечает HTTP 404)
	 */
	public function findOne($resource, $filter) {
		list($code, $h, $data) = $this->request('GET', $resource . '/search', $filter);
		if ($code === 404) return null;
		if ($code !== 200) throw static::apiError("GET $resource/search", $code, $data);
		return is_array($data) ? $data : null;
	}

	/*
	 * Одиночная выборка (без сбора страниц). Возвращает то, что отдал API.
	 */
	public function get($resource, $filter = array()) {
		list($code, $h, $data) = $this->request('GET', $resource, $filter);
		if ($code !== 200) throw static::apiError("GET $resource", $code, $data);
		return is_array($data) ? $data : array();
	}

	/*
	 * Проверка авторизации/доступности: /users/whoami возвращает текущего
	 * пользователя (или null при отключенном RBAC / отсутствии авторизации).
	 */
	public function whoami() {
		list($code, $h, $data) = $this->request('GET', 'users/whoami');
		return $code === 200 ? $data : null;
	}

	/*
	 * Загрузка файла (multipart/form-data), напр. POST scans/upload.
	 * @param string $action   путь экшена (scans/upload)
	 * @param string $filePath локальный файл
	 * @param string $postName имя файла для сервера (задаёт имя скана в ARMS)
	 * @param array  $fields   дополнительные POST-поля (users_id и т.п.)
	 * @return array декодированный ответ
	 */
	public function uploadFile($action, $filePath, $postName, $fields = array()) {
		$url = $this->baseUrl . '/' . ltrim($action, '/');
		$ch = curl_init($url);
		$mime = function_exists('mime_content_type') ? mime_content_type($filePath) : '';
		if (!$mime) $mime = 'application/octet-stream';
		$opts = array(
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_HTTPHEADER     => array('Accept: application/json'),
			CURLOPT_POSTFIELDS     => $fields + array('scanFile' => new CURLFile($filePath, $mime, $postName)),
		);
		if (strlen($this->user)) {
			$opts[CURLOPT_USERPWD]  = $this->user . ':' . $this->password;
			$opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
		}
		curl_setopt_array($ch, $opts);
		$raw = curl_exec($ch);
		if ($raw === false) {
			$err = curl_error($ch);
			curl_close($ch);
			throw new RuntimeException("ARMS API upload $url: $err");
		}
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($code < 200 || $code >= 300) throw new RuntimeException("ARMS API upload $action -> HTTP $code: " . substr($raw, 0, 200));
		$data = json_decode($raw, true);
		return is_array($data) ? $data : array();
	}

	// --- запись (приём SAP/1C -> ARMS) ---

	public function create($resource, $body) {
		list($code, $h, $data) = $this->request('POST', $resource, array(), $body);
		if ($code < 200 || $code >= 300) throw static::apiError("POST $resource", $code, $data);
		return is_array($data) ? $data : array();
	}

	public function update($resource, $id, $body) {
		list($code, $h, $data) = $this->request('PUT', "$resource/$id", array(), $body);
		if ($code < 200 || $code >= 300) throw static::apiError("PUT $resource/$id", $code, $data);
		return is_array($data) ? $data : array();
	}

	public function delete($resource, $id) {
		list($code, $h, $data) = $this->request('DELETE', "$resource/$id");
		if ($code < 200 || $code >= 300) throw static::apiError("DELETE $resource/$id", $code, $data);
	}
}

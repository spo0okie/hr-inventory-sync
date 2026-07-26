<?php
/*
 * Тонкий REST-клиент к API инвентаризации ARMS.
 *
 * Транспорт для источника 'arms': оргструктура, сотрудники (и, в перспективе,
 * отсутствия) читаются через REST вместо прямого доступа к БД инвентаризации.
 *
 * Аутентификация — HTTP Basic сервисным пользователем ARMS. Опциональна: если
 * логин пуст, запрос уходит без авторизации (локальный инстанс с отключенным
 * RBAC отвечает и так). Постраничная выборка собирается по заголовкам
 * X-Pagination-Page-Count. Все методы работают с уже декодированным JSON.
 *
 * Пишущие методы (create/update/delete) понадобятся для приёма SAP/1C -> ARMS.
 */
class CArmsRestClient {
	private string $baseUrl;
	private string $user;
	private string $password;
	private int    $timeout;

	/** размер страницы при постраничной выборке getAll() */
	public int $perPage = 200;

	public function __construct(string $baseUrl, string $user = '', string $password = '', int $timeout = 30) {
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
	private function request(string $method, string $resource, array $query = [], ?array $body = null): array {
		$url = $this->baseUrl . '/' . ltrim($resource, '/');
		if ($query) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);

		$headers = [];
		$ch = curl_init($url);
		$reqHeaders = ['Accept: application/json'];
		if ($body !== null) $reqHeaders[] = 'Content-Type: application/json';

		$opts = [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_HTTPHEADER     => $reqHeaders,
			CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
				$p = strpos($line, ':');
				if ($p !== false) $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
				return strlen($line);
			},
		];
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

		return [$code, $headers, $data];
	}

	/*
	 * Ошибка API с телом ответа: в теле ARMS отдаёт причину (403 RBAC, 422 валидация).
	 */
	private static function apiError(string $what, int $code, $body): RuntimeException {
		$text = is_null($body) ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);
		if (strlen($text) > 300) $text = substr($text, 0, 300) . '...';
		return new RuntimeException("ARMS API $what -> HTTP $code" . (strlen($text) ? ": $text" : ''));
	}

	/*
	 * Полная выборка ресурса с фильтрами: собирает все страницы по X-Pagination-Page-Count.
	 * @param string $resource напр. 'users' или 'org-struct'
	 * @param array  $filter   фильтр, напр. ['org_id'=>1]
	 * @return array список записей (ассоциативные массивы)
	 */
	public function getAll(string $resource, array $filter = []): array {
		//фильтрация в ARMS — через экшн /filter (index отдаёт всё без фильтра!)
		$path = $filter ? $resource . '/filter' : $resource;
		$out = [];
		$page = 1;
		$pageCount = 1;
		do {
			[$code, $h, $data] = $this->request('GET', $path, $filter + ['page' => $page, 'per-page' => $this->perPage]);
			if ($code !== 200) throw static::apiError("GET $path", $code, $data);
			if (is_array($data)) foreach ($data as $row) $out[] = $row;
			$pageCount = isset($h['x-pagination-page-count']) ? (int)$h['x-pagination-page-count'] : 1;
			$page++;
		} while ($page <= $pageCount);
		return $out;
	}

	/*
	 * Поиск ОДНОЙ записи по уникальному фильтру через /{resource}/search.
	 * @return array|null запись или null, если не найдено (ARMS отвечает HTTP 404)
	 */
	public function findOne(string $resource, array $filter): ?array {
		[$code, $h, $data] = $this->request('GET', $resource . '/search', $filter);
		if ($code === 404) return null;
		if ($code !== 200) throw static::apiError("GET $resource/search", $code, $data);
		return is_array($data) ? $data : null;
	}

	/*
	 * Одиночная выборка (без сбора страниц). Возвращает то, что отдал API.
	 */
	public function get(string $resource, array $filter = []): array {
		[$code, $h, $data] = $this->request('GET', $resource, $filter);
		if ($code !== 200) throw static::apiError("GET $resource", $code, $data);
		return is_array($data) ? $data : [];
	}

	/*
	 * Проверка авторизации/доступности: /users/whoami возвращает текущего
	 * пользователя (или null при отключенном RBAC / отсутствии авторизации).
	 */
	public function whoami() {
		[$code, $h, $data] = $this->request('GET', 'users/whoami');
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
	public function uploadFile(string $action, string $filePath, string $postName, array $fields = []): array {
		$url = $this->baseUrl . '/' . ltrim($action, '/');
		$ch = curl_init($url);
		$opts = [
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_HTTPHEADER     => ['Accept: application/json'],
			CURLOPT_POSTFIELDS     => $fields + ['scanFile' => new CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', $postName)],
		];
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
		return is_array($data) ? $data : [];
	}

	// --- запись (приём SAP/1C -> ARMS, понадобится позже) ---

	public function create(string $resource, array $body): array {
		[$code, $h, $data] = $this->request('POST', $resource, [], $body);
		if ($code < 200 || $code >= 300) throw static::apiError("POST $resource", $code, $data);
		return is_array($data) ? $data : [];
	}

	public function update(string $resource, $id, array $body): array {
		[$code, $h, $data] = $this->request('PUT', "$resource/$id", [], $body);
		if ($code < 200 || $code >= 300) throw static::apiError("PUT $resource/$id", $code, $data);
		return is_array($data) ? $data : [];
	}

	public function delete(string $resource, $id): void {
		[$code, $h, $data] = $this->request('DELETE', "$resource/$id");
		if ($code < 200 || $code >= 300) throw static::apiError("DELETE $resource/$id", $code, $data);
	}
}

<?php

require_once "COrgStructureStorage.php";


class CSQLUserList extends COrgStructureStorage {

/*
<item>
    <Pernr>00000869</Pernr> - Табельный номер
    <Ename>Пупкина Анна Сергеевна</Ename>
    <Nachn>Пупкина</Nachn>
    <Vorna>Анна</Vorna>
    <Midnm>Сергеевна</Midnm>
    <Orgeh>10000032</Orgeh> - Узел в оргструктуре
    <Orgtx>Дирекция по продажам</Orgtx> - Подразделение
    <Uname/>	- Логин
    <Doljnost>Интеграция: штатная должн</Doljnost> - Должность
    <Uvolen>X</Uvolen>	- Признак увольнения (должен стоять X)
    <Persg>1</Persg>	- Тип трудоустройства (1 - В штате, 2 - Совместители внутрен, 3 - Совместители внешние, 4 - ДГПХ, 5 - Инвалиды, 6 – Пенсионеры, 7 – Несовершеннолетние)
    <Werks>1102</Werks> - Завод - численное представление
    <NameF>АО "Табуретка" в г.Москва</NameF> - Завод текстовая рашифровка
</item>
*/

	//ссылка на оргструктуру битрикс, нам там надо будет искать подразделения для пользователей
	private $bxOrg=null;
	private $sapOrg=null;
	private $orgDict=null;
	
	public function attachBxOrg(&$bx) {$this->bxOrg=$bx;}
	public function attachSapOrg(&$sap) {$this->sapOrg=$sap;}
	public function attachOrgDict(&$dict) {$this->orgDict=$dict;}
	
	/*
	 * Грузит данные из SQL
	 * @param string $filename файл для загрузки
	 * @return bool успех загрузки данных
	 */
	public function loadFromSQL($path,$user,$pwd,$db){
		$this->db = new mysqli($path,$user,$pwd,$db);
		$req_sql = 'set names "utf8"';
		$this->db->query($req_sql);

		$res_obj=$this->db->query('SELECT u.*, o.name AS Orgtx FROM users AS u LEFT JOIN org_struct AS o ON u.Orgeh=o.id');
		if (is_object($res_obj))
			while (is_array($res=$res_obj->fetch_assoc())){
				$name=explode(' ',$res['Ename']);
				$res['Nachn']=isset($name[0])?$name[0]:'';
				$res['Vorna']=isset($name[1])?$name[1]:'';
				$res['Midnm']=isset($name[2])?$name[2]:'';
				$res['Pernr']=$res['id'];
				$this->data[$res['Pernr']]=$res;
			}
		//echo count($this->data)." items loaded in SQL Usr storage\n";
		//var_dump($this->data);
		return is_array($this->data);
	}



	
	/*
	 * Генерирует список полей, которые должны быть в битриксе у этого пользователя
	 * @param string $id идентификатор элемента
	 * @return array список полей с заполненными значениями, которые должны быть в битриксе
	 */
	public function bxGenFields($id){
		//получаем полное имя и разбиваем на слова
		$nametokens=explode(' ',$this->getItemField($id,'Ename'));
		$last_name=$nametokens[0];
		unset($nametokens[0]);
		$name=implode(' ',$nametokens);
		$code=$this->getItemField($id,'org_id').'_'.$this->getItemField($id,'Orgeh');
		//echo "code $last_name | $name \n";
		$department=$this->bxOrg->findIdBy('CODE',$code);
		if (!$department) $department=$this->bxOrg->findIdBy('CODE',$this->getItemField($id,'org_id').'_NULL');
		//echo "department $department\n";
		$depname=$this->sapOrg->getItemField($code,'name');
		$bday=($this->getItemField($id,'Bday')=='0000-00-00')?NULL:date('d.m.Y',strtotime($this->getItemField($id,'Bday')));
		$fields=[
		      //"ACTIVE"=>"Y",

			"NAME"=>$name,              //ИМЯ
			"LAST_NAME"=>$last_name,    //ФАМИЛИЯ
			"SECOND_NAME"=>'',          //ОТЧЕСТВО
			"WORK_POSITION"=>$this->getItemField($id,'Doljnost'),
			"WORK_DEPARTMENT"=>$depname,
			"WORK_COMPANY"=>$this->getItemField($id,'Organization'),
		    "PERSONAL_BIRTHDAY"=>$bday,
			"XML_ID" => $id,
		    "UF_DEPARTMENT" => is_null($department)?null:[$department],
			"ACTIVE"=> $this->getItemField($id, 'Uvolen')?'N':'Y',
		];
		//if ($this->getItemField($id, 'Uvolen')) $fields['ACTIVE']='N';//деактивируем уволенных
		//var_dump($this->data[$id]);
		//var_dump($fields);
		//die('gen bx fields');
		return $fields;
	}
	
	/*
	 * Ищет основное трудоустройство пользователя по полю
	 * @param string $filter фильтр типа поле=>значение, по которому ищем совпадения
	 * @return string табельный номер основного трудоустройства
	 * @throws Exception в случае если указанный фильтр нашел более одного трудоустройства типа "основное" 
	 */
	public function getMainEmployment($filter){
		
		//ищем всех пользователей по критерию из фильтра
		$users=$this->findIdsBy($filter);
		//var_dump($filter);
		//var_dump($users);
		if (!count($users)) $users=$this->findIdsBy($filter,true,false,true); //если не нашлось, то ищем без учета буквы Ё
		if (!count($users)) return null;
		
		//составляем из найденных трудоустройств таблицу [табельный номер=>тип]
		$types=[];
		
		foreach ($users as $user) {
			$type=(int)$this->getItemField($user, 'Persg');
			
			if ($type>4) continue; //исключаем трудоустройство по ДГПХ
			
			if (($exst=(array_search($type,$types)))!==false) { //ситуация, когда трудоустройство этого типа уже есть
				
				$user_active=($this->getItemField($user, 'Uvolen')!='1'); //текущий пользователь не уволен?
				$exst_active=($this->getItemField($exst, 'Uvolen')!='1'); //ранее найденный пользователь не уволен?
				
				if ($user_active xor $exst_active) {//ситуация когда один работает а другой нет
					if ($exst_active) continue; //работает ранее найденный? - пропускаем текущего
					unset($types[$exst]); //иначе убираем ранее найденного из нашего списка 
				} elseif (!$user_active and !$exst_active) { //ситуация когда оба нашлись и оба уволены
					unset($types[$exst]); //убираем ранее найденного из нашего списка
				} else { //вот тут у нас начинается неразбериха, либо оба уволенные, либо оба работают. в общем равнозначные
					if ($type===1) {
						//обработка исключительной ситуации если фильтр нашел более одного основного трудоустройства (однофамильцы)
						throw new Exception(EMPLOYMENTSEARCH_MANY_RESULTS_TEXT,EMPLOYMENTSEARCH_MANY_RESULTS);
					}
				}
			}
				
			
			
			$types[$user]=$type; 
		}
		
		//выбираем из таблицы трудоустройство наименьшего типа
		return array_search(min($types),$types);
	}

	/*
	 * Добавляет дополниетльные вычисляемые поля пользователю
	 * @param string $id идентификатор пользователя
	 * @return bool успех операции
	 */
	function buildExFields($id) {
		if (is_null($id)) return false;

		if ($this->data[$id]['Uvolen']) {
			if (strlen($this->data[$id]['resign_date'])) {
				$this->data[$id]['Uvolen']=(strtotime($this->data[$id]['resign_date'])<=time())?1:0;
			}
		}
		//для подстраховки записываем сначла название завода
		$this->data[$id]['Organization']=$this->getItemField($id, 'NameF');
		if (!isset($this->orgDict)) return false; //нет словаря завод=>организация
		if ($this->getItemField($id, 'exFieldsBuilt')===true) return true; //уже все сделано
		if (!strlen($factory=$this->getItemField($id, 'Werks'))) return false; //у пользователя не проставлен завод
		if (!isset($this->orgDict[$factory])) return false;
		//записываем название из словаря
		$this->data[$id]['Organization']=$this->orgDict[$factory];
		$this->data[$id]['exFieldsBuilt']=true;
	}
	
	/*
	 * Добавляет дополниетльные вычисляемые поля всем пользователям
	 */
	function buildExFieldsAll() {
		foreach ($this->getIds() as $id) $this->buildExFields($id);
	}
	
	
	/*
	 * Возвращает пользователя с дополнительно вычисляемыми полями
	 * @param string $id идентификатор пользователя
	 * @return array массив полей пользователя
	 */
	public function getUser($id){
		$this->buildExFields($id);
		return $this->getItem($id);
	}
	
	/*
	 * Ищет пользователя по фильтру
	 */
	/*
	 * Отключено, все обращения заменены на getMainEmployment, поскольку этот поиск находит случайного пользователя
	 * в то время как getMainEmployment ищет наиболее приоритетного пользователя с точки зрения типа трудоустройства
	 * непонятно в каком случае поиск случайного трудоустройства из нескольких возможных может понадобиться.
	public function findUserBy($filter){
		//ищем среди активных сотрудников
		$filter['Uvolen']='';
		try {
			$id=$this->getMainEmployment($filter); //этот запрос может выбросить исключение если найдено более одного пользователя
		} catch (Exception $e) {
			throw $e;
			return null;
		}
		
		if (($id!==null)&&($id!==false)) return $id;
		
		//среди уволенных ищем без изысков, подойдет просто любой
		unset ($filter['Uvolen']); //убираем статус увольнения
		$id=$this->findIdsBy($filter); //ищем еще раз
		if (!count($id)) $id=$this->findIdsBy($filter,true,false,true); //ищем еще раз без букв Ё
		return count($id)?$id[0]:null; //вовращаем или первого найденного или ничего
	}
	*/
}


?>
   
<?php
/*
 * ВНИМАНИЕ файл не поддерживается в актуальном состоянии, т.к. нет на текущий момент источника данных типа SAP
 *
 * Класс хранилище оргструктуры
 * На самом деле абстрактный класс не используется непосредственно
 * вместо этого из него наследуются и кастомизируются 5 хранилищ 
 * - оргструктуры в битриксе и САП
 * - пользователей в битриксе и САП
 * - групп пользователей в битриксе
 * 
 *	В битриксе значит оргструктура устроена таким образом:
 *	Внутри каждого объекта "Информационный блок" имеются элементы и секции
 *	оргстуктура как раз строится из секций внутри информационного блока "подразделения"
 *	элементы там не используются
 *	
 * Элемент пользователя в САП грузится вот из такого итема:

<item>
    <Pernr>00000869</Pernr> - Табельный номер
    <Ename>Пупкина Агрофена Евлампиевна</Ename>
    <Nachn>Пупкина</Nachn>
    <Vorna>Агрофена</Vorna>
    <Midnm>Евлампиевна</Midnm>
    <Orgeh>10000032</Orgeh> - Узел в оргструктуре
    <Orgtx>Дирекция по продажам</Orgtx> - Подразделение
    <Uname/>	- Логин
    <Doljnost>Интеграция: штатная должн</Doljnost> - Должность
    <Uvolen>X</Uvolen>	- Признак увольнения (должен стоять X)
    <Persg>1</Persg>	- Тип трудоустройства (1 - В штате, 2 - Совместители внутрен, 3 - Совместители внешние, 4 - ДГПХ, 5 - Инвалиды, 6 – Пенсионеры, 7 – Несовершеннолетние)
    <Werks>1102</Werks> - Завод - численное представление
    <NameF>АО "Табуретка"</NameF> - Завод текстовая рашифровка
</item>

 Оргструктура из такого:
<item>
     <Seqnr>71</Seqnr> - номер узла в дереве
     <Level>3</Level>
     <Otype>O</Otype>
     <Objid>10000304</Objid> - идентификатор подразделения (использвется для синхронизации со структурой в портале)
     <Orgtx>Бухгалтерия</Orgtx> - имя подразделения
     <Pdown>0</Pdown> - Дочерний узел, хз зачем он, ведь дочерних несколько а тут только одно значение. Я не использую
     <Dflag/>
     <Vcount>0</Vcount>
     <Pnext>72</Pnext>
     <Pup>22</Pup> - Родительский узел. Используется для выстраивания дерева
     <Pprev>70</Pprev>
     <Vrsign>B</Vrsign>
     <Vrelat>002</Vrelat>
     <Vpriox>AM</Vpriox>
     <Vistat>1</Vistat>
     <Vbegda>2016-06-01</Vbegda>
     <Vendda>9999-12-31</Vendda>
     <Vprozt>0.0</Vprozt>
     <Vadata/>
     <Subrc>0</Subrc>
     <Rflag/>
     <Sbegd>2017-05-23</Sbegd>
     <Sendd>2017-05-23</Sendd>
     <Vsign/>
     <Vseqnr>000</Vseqnr>
</item>
 *
 * v1.2 Добавлена корневая процедура сравнения COrgStructureStorage::fieldCompareInt для поиска по
 *		целочисленным значениям. Удобно при поиске по табельному номеру, который сап отдает со 
 *		впередиидущими нулями.
 * v1.1 Исправления по поиску, все поисковые фукнции завязаны на корневую функцию сравнения
 * 		COrgStructureStorage::fieldCompare, практически во все функции поиска и фильтрации добавлены
 * 		параметры case и soft которые отключают проверку регистра или включают равенство букв е и ё
 * v1.0 Initial release
 * 
 */

require_once dirname(__FILE__)."/../Abstract/COrgStructureStorage.php";


class CSapUserList extends COrgStructureStorage {
	//ссылка на оргструктуру битрикс, нам там надо будет искать подразделения для пользователей
	private $bxOrg=null;
	private $sapOrg=null;
	private $orgDict=null;
	
	public function attachBxOrg(&$bx) {$this->bxOrg=$bx;}
	public function attachSapOrg(&$sap) {$this->sapOrg=$sap;}
	public function attachOrgDict(&$dict) {$this->orgDict=$dict;}
	
	/**
	 * Грузит данные из XML файла
	 * @param string $filename файл для загрузки
	 * @param string $child имя дочерней структуры для загрузки (в общей структуре есть и оргструктура и список сотрудников)
	 * @param string $item имя объекта-элемента списка в структуре
	 * @return bool успех загрузки данных
	 */
	public function loadFromXml($filename,$child='OPersonal',$item='Pernr'){
		//грузим данные САП из файла:
		$this->data=COrgStructureStorage::loadFromXml($filename,$child,$item);
		//echo count($this->data)." items loaded in SAP User storage\n";
		//var_dump($this->data);
		$this->buildExFieldsAll();
		return is_array($this->data);
	}


	/**
	 * Грузит данные из JSON файла
	 * @param string $filename файл для загрузки
	 * @param string $child имя дочерней структуры для загрузки (в общей структуре есть и оргструктура и список сотрудников)
	 * @param string $item имя объекта-элемента списка в структуре
	 * @return bool успех загрузки данных
	 */
	public function loadFromJson($filename,$child='OPersonal',$item='Pernr'){
		//грузим данные САП из файла:
		$this->data=COrgStructureStorage::loadFromJson($filename,$child,$item);
		//echo count($this->data)." items loaded in SAP User storage\n";
		$this->buildExFieldsAll();
		return is_array($this->data);
	}


	/**
	 * По СНИЛС получить все трудоустройства пользователя и вернуть его подразделения
	 * @param $uid
	 */
	public function getActiveDepartmentsCodes($uid) {
		$bxDepartments=[];
		$positions=$this->findIdsBy(['uid'=>$uid,'Uvolen'=>'']);
		foreach ($positions as $id) {
			//echo "searchin ".$this->getItemField($id,'Orgeh').': ';
			$bxCode=$this->bxOrg->findIdBy('CODE',$this->getItemField($id,'Orgeh'));
			//echo "$bxCode\n";
			if (!is_null($bxCode))
				$bxDepartments[$bxCode]=$bxCode;
		}
		return array_keys($bxDepartments);
	}
	
	/**
	 * Генерирует список полей, которые должны быть в битриксе у этого пользователя
	 * @param string $id идентификатор элемента
	 * @return array список полей с заполненными значениями, которые должны быть в битриксе
	 */
	public function bxGenFields($id){
		//получаем полное имя и разбиваем на слова
		//$nametokens=explode(' ',$this->getItemField($id,'Ename'));
		//$last_name=$nametokens[0];
		//unset($nametokens[0]);
		//$name=implode(' ',$nametokens);
		//$department=$this->bxOrg->findIdBy('CODE',$this->getItemField($id,'Orgeh'));
		$departments=$this->getActiveDepartmentsCodes($this->getItemField($id,'uid'));
		$bday=($this->getItemField($id,'Gbdat')=='0000-00-00')?NULL:date('d.m.Y',strtotime($this->getItemField($id,'Gbdat')));
		$fields=[
		      'ACTIVE'				=> 'Y',
		      //'WORK_POSITION'		=> $this->getItemField($id,'Doljnost'),
			  //'WORK_DEPARTMENT'		=> is_null($depname)?'':$depname,
			  //'WORK_COMPANY'		=> $this->getItemField($id,'Organization'),
		      'PERSONAL_BIRTHDAY'	=> $bday,
			  //'XML_ID'				=> $id,
		      'UF_DEPARTMENT'		=> $departments,
		];
		//echo 'Uvolen: '.$this->getItemField($id, 'Uvolen')."\n";
		if ($this->getItemField($id, 'Uvolen')==='Уволен' || $this->getItemField($id, 'Uvolen')==='X') $fields['ACTIVE']='N';//деактивируем уволенных
		return $fields;
	}
	
	/**
	 * Ищет основное трудоустройство пользователя по полю
	 * @param array $filter фильтр типа поле=>значение, по которому ищем совпадения
	 * @return string табельный номер основного трудоустройства
	 * @throws Exception в случае если указанный фильтр нашел более одного трудоустройства типа "основное" 
	 */
	public function getMainEmployment($filter){
		
		//ищем всех пользователей по критерию из фильтра
		$users=$this->findIdsBy($filter);
		if (!count($users)) $users=$this->findIdsBy($filter,true,false,true); //если не нашлось, то ищем без учета буквы Ё
		if (!count($users)) return null;
		
		//составляем из найденных трудоустройств таблицу [табельный номер=>тип]
		$types=[];
		
		foreach ($users as $user) {
			$type=(int)$this->getItemField($user, 'Persg');
			
			if ($type==4) continue; //исключаем трудоустройство по ДГПХ
			
			if (($exst=(array_search($type,$types)))!==false) { //ситуация, когда трудоустройство этого типа уже есть
				
				$user_active=($this->getItemField($user, 'Uvolen')!='Уволен'); //текущий пользователь не уволен?
				$exst_active=($this->getItemField($exst, 'Uvolen')!='Уволен'); //ранее найденный пользователь не уволен?
				
				if ($user_active xor $exst_active) {//ситуация когда один работает а другой нет
					if ($exst_active) continue; //работает ранее найденный? - пропускаем текущего
					unset($types[$exst]); //иначе убираем ранее найденного из нашего списка 
				} else { //вот тут у нас начинается неразбериха, либо оба уволенные, либо оба работают. в общем равнозначные
					if ($type===1) {
						if (!$user_active && !$exst_active) {
							continue; //сравниваем 2х уволенных (в сортах уволенных разбираться не будем - считаем равнозначными)
						}
						var_dump($user);
						echo $this->getItemField($user, 'Uvolen');
						var_dump($exst);
						echo $this->getItemField($exst, 'Uvolen');
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

		$uid=preg_replace( '/[^0-9]/', '', $this->data[$id]['Snils'] );
		$this->data[$id]['uid']=strlen($uid)?md5($uid ):'';

		//создаем поля даты приема и увольнения, т.к. они в скриптах исользются не с этими именами
		if (!isset($this->data[$id]['Employ_date']) && isset($this->data[$id]['DatePrin'])) $this->data[$id]['Employ_date'] = $this->data[$id]['DatePrin'];
		if (!isset($this->data[$id]['Resign_date']) && isset($this->data[$id]['DateUvol'])) $this->data[$id]['Resign_date'] = $this->data[$id]['DateUvol'];
		if (strcmp($this->data[$id]['Uvolen'],'Х')==0) $this->data[$id]['Uvolen']='Уволен'; //Русская Ха
		if (strcmp($this->data[$id]['Uvolen'],'X')==0) $this->data[$id]['Uvolen']='Уволен'; //Английский Икс

		//у ДГПХ (Persg=4) должности в SAP не бывает — приходит заглушка
		//«Интеграция: штатная должн», пишем осмысленное значение
		if ((int)$this->getItemField($id, 'Persg') === 4) $this->data[$id]['Doljnost'] = 'ДГПХ';

		if ($this->getItemField($id, 'exFieldsBuilt')===true) return true; //уже все сделано

		if (!isset($this->data[$id]['Organization'])) {
			$this->data[$id]['Organization']=$this->getItemField($id, 'NameF');
			if (!isset($this->orgDict)) return false; //нет словаря завод=>организация
			if (!strlen($factory=$this->getItemField($id, 'Werks'))) return false; //у пользователя не проставлен завод
			if (!isset($this->orgDict[$factory])) return false;
			//записываем название из словаря
			$this->data[$id]['Organization']=$this->orgDict[$factory];
		}

		$this->data[$id]['exFieldsBuilt']=true;
	}
	
	/*
	 * Добавляет дополниетльные вычисляемые поля всем пользователям
	 */
	public function buildExFieldsAll() {
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
   
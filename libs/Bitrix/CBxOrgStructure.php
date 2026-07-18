<?php
/*
 * 2019.09.21 У битрикса есть особенность - один корневой элемент оргструктуры
 * с одной организацией с этим проблем не было, а с несколькими появились
 *
 * 2019.12.17 В С одной не было и Тут нет, потому что с одной изначально корень был "Организации" и потом
 * уже оргструктуры каждого завода
[ID] => 5498
            [~ID] => 5498
            [TIMESTAMP_X] => 01.05.2017 16:17:04
            [~TIMESTAMP_X] => 01.05.2017 16:17:04
            [MODIFIED_BY] => 0
            [~MODIFIED_BY] => 0
            [DATE_CREATE] => 01.05.2017 16:17:04
            [~DATE_CREATE] => 01.05.2017 16:17:04
            [CREATED_BY] => 0
            [~CREATED_BY] => 0
            [IBLOCK_ID] => 5
            [~IBLOCK_ID] => 5
            [IBLOCK_SECTION_ID] => 5497
            [~IBLOCK_SECTION_ID] => 5497
            [ACTIVE] => Y
            [~ACTIVE] => Y
            [GLOBAL_ACTIVE] => Y
            [~GLOBAL_ACTIVE] => Y
            [SORT] => 500
            [~SORT] => 500
            [NAME] => Группа по разработке антенных систем
            [~NAME] => Группа по разработке антенных систем
            [PICTURE] =>
            [~PICTURE] =>
            [LEFT_MARGIN] => 380
            [~LEFT_MARGIN] => 380
            [RIGHT_MARGIN] => 381
            [~RIGHT_MARGIN] => 381
            [DEPTH_LEVEL] => 4
            [~DEPTH_LEVEL] => 4
            [DESCRIPTION] =>
            [~DESCRIPTION] =>
            [DESCRIPTION_TYPE] => text
            [~DESCRIPTION_TYPE] => text
            [SEARCHABLE_CONTENT] => ГРУППА ПО РАЗРАБОТКЕ АНТЕННЫХ СИСТЕМ
            [~SEARCHABLE_CONTENT] => ГРУППА ПО РАЗРАБОТКЕ АНТЕННЫХ СИСТЕМ
            [CODE] => 10000102
            [~CODE] => 10000102
            [XML_ID] =>
            [~XML_ID] =>
            [TMP_ID] =>
            [~TMP_ID] =>
            [DETAIL_PICTURE] =>
            [~DETAIL_PICTURE] =>
            [SOCNET_GROUP_ID] =>
            [~SOCNET_GROUP_ID] =>
            [LIST_PAGE_URL] => /company/structure.php
            [~LIST_PAGE_URL] => /company/structure.php
            [SECTION_PAGE_URL] => /company/structure.php?set_filter_structure=Y&amp;structure_UF_DEPARTMENT=5498
            [~SECTION_PAGE_URL] => /company/structure.php?set_filter_structure=Y&structure_UF_DEPARTMENT=5498
            [IBLOCK_TYPE_ID] => structure
            [~IBLOCK_TYPE_ID] => structure
            [IBLOCK_CODE] => departments
            [~IBLOCK_CODE] => departments
            [IBLOCK_EXTERNAL_ID] => departments
            [~IBLOCK_EXTERNAL_ID] => departments
            [EXTERNAL_ID] =>
            [~EXTERNAL_ID] =>

 */

require_once dirname(__FILE__)."/../Abstract/COrgStructureStorage.php";

class CBxOrgStructure extends COrgStructureStorage {
	/**
	 * Название информационного блока внутри которого хранится оргструктура
	 * Вроде как оно всегда "Подразделения"
	 */
	public static $StructIBlckName='Подразделения';

	/**
	 * Идентификатор информационного блока "Подразделения",
	 * изначально не определен, используется как кэш запроса
	 */
	private $StructIBlck=null;
	
	/**
	 * Возвращает id первого найденного по имени информационного блока
	 * @param string $name искомое имя
	 * @return string id найденного элемента или null
	 */
	public static function getIBlockByName($name){
		//запрашиваем список по фильтру
		$res = CIBlock::GetList([],['NAME'=>$name,'ACTIVE'=>'Y'],true);
		//перебирая список возвращаем первый найденный
		while($ar_res = $res->Fetch()) return $ar_res['ID'];
		//или null
		return null;
	}
	
	/**
	 * @return string id информационного блока в котором хранится оргструктура
	 */
	public function getStructIBlock(){
		if (
				is_null($this->StructIBlck)&&	//пусто в кэше
				is_null($this->StructIBlck=static::getIBlockByName(static::$StructIBlckName)) //и прямой запрос ничего не дает
			)	die("Не удалось обнаружить инфоблок ".static::$StructIBlckName."\n"); //не можем продолжать работать без этих данных
		return $this->StructIBlck;
	}
	
	
	/**
	 * При создании тут же подцепляемся к битриксу и загружаем оргструктуру
	 */
	public function __construct(){
		$arFilter = array('IBLOCK_ID' => static::getStructIBlock());
		$arSelect = [];
		$rsSection = CIBlockSection::GetTreeList($arFilter, $arSelect);
		while($arSection = $rsSection->GetNext())
			$this->initItem($arSection,'ID');

		echo count($this->data)." items loaded in Bitrix Org storage \n";
	}
	
	/**
	 * Загружает один элемент из Bitrix
	 * reload названо потому что для загрузки по ID надо знать этот ID, что как бы намекает
	 * @return bool успех или неудача загрузки
	 */
	public function reloadItem($id){
		$res = CIBlockSection::GetByID($id);
		if($ar_res = $res->GetNext()){
			$this->initItem($ar_res,'ID');
			return true;
		} else
			return false;
	}
	
	/**
	 * Чистит параметры от генеренных полей и от ИД
	 * удобно для передачи как набор для создания или корректировки элемента
	 * @param array $arFields исходный набор
	 * @return array очищенный набор (или тот же самый, если нечего чистить)
	 */
	public static function cleanParams($arFields){
		//если мы передали параметры с генеренными вариантами ~PARAMETER - почистим их
		foreach ($arFields as $key=>$data)
			if (substr($key,0,1)=='~') unset($arFields[$key]);
			//удалим id
			if (isset($arFields['ID'])) unset($arFields['ID']);
			return $arFields;
	}
	
	/**
	 * добавляет элемент в оргструктуру битрикс
	 * не в наше внутреннее хранилище, а в сам Битрикс, а потом его подгружает оттуда
	 * @param array $params параметры нового элемента
	 * @return strind id нового элемента
	 */
	public function addItem($params){
		//почистим
		$params=CbxOrgStructure::cleanParams($params);

		//dry-run: секцию не создаём, только сообщаем о намерении
		if ($this->readOnly) {
			echo "[DRY-RUN] создал бы секцию CODE=".($params['CODE']??'?')." NAME=".($params['NAME']??'')."\n";
			return 'DRYRUN-'.($params['CODE']??'');
		}

		//debug
		echo "Adding ".print_r($params,true)."\n";
		//exit();
		
		//добавляем через АПИ битрикса
		$bs = new CIBlockSection;
		$ID = $bs->Add($params);
		
		//получилось?
		if(!($res = ($ID>0)))
			echo $bs->LAST_ERROR; //нет - скажем почему
			else
				$this->reloadItem($ID); //да - загрузим иформацию по этому элементу
				
				//освобождаем память
				unset($bs);
				
				//возвращаем ИД созданого элемента
				return $res?$ID:null;
	}
	
	/**
	 * обновляет подразделение ID полями arFields
	 */
	public function updItem($id,$arFields){
		//почистим
		$arFields=static::cleanParams($arFields);
		
		//dry-run: не пишем, только сообщаем о намерении
		if ($this->readOnly) {
			echo "[DRY-RUN] секция #$id: обновил бы ".json_encode($arFields, JSON_UNESCAPED_UNICODE)."\n";
			return true;
		}

		//пишем
		$bs = new CIblockSection();
		$res = $bs->Update($id,$arFields);
		
		//проверяем
		if(!$res) echo $bs->LAST_ERROR;

		//чистим память
		unset ($bs);
		
		//возвращаем ответ
		if ($res){ //если все до сих пор шло нормально то
			$this->reloadItem($id); //перезагрузим обновленный элемент
			return $this->ckItem($id, $arFields); //вернем соответствие загруженного элемента требуемым параметрам
		} else return $res;
	}
	
	
	/*
	 * Проверяет, что у итема ID все перечисленные в $params параметры соответствую переданным
	 * если нет, то устанавливает
	 */
	public function correctItem($id,$params){
		//либо все ок потомучто все уже как надо, либо потомучто мы все так пофиксили, либо все плохо)
		return $this->ckItem($id, $params)||$this->updItem($id, $params);
	}

	/**
	 * Генерирует список полей, которые должны быть в битриксе у секции с переданными параметрами
	 * @param string $id идентификатор элемента
	 * @param string $name имя элемента
	 * @param string $parent идентификатор родителя
	 * @return array список полей с заполненными значениями, которые должны быть в битриксе
	 */
	public function bxGenFields($id,$name,$parent){
		return [
			"ACTIVE" => 'Y',
			"IBLOCK_ID" => $this->getStructIBlock(),
			"IBLOCK_SECTION_ID" => $this->findIDBy('CODE',$parent),
			"NAME" => $name,
			"CODE" => $id
		];
	}

}


?>
   
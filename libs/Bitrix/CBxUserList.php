<?php
/*
 * Класс хранилище пользователей битрикс
 * Формат элемента
(
            [ID] => 4251
            [TIMESTAMP_X] => 30.01.2023 13:02:14
            [LOGIN] => pupkin.v
            [PASSWORD] => 3rVLMAZJb889e5f8a2057d679303cf4cfb7c39d0
            [CHECKWORD] => 6du1BTha053f5b86df995ad8c8a3b554a3f1dee7
            [ACTIVE] => Y
            [NAME] => Васиссуалий Фролович
            [LAST_NAME] => Пупкин
            [EMAIL] => pupkin@taburetka.ru
            [LAST_LOGIN] =>
            [DATE_REGISTER] => 23.01.2023 13:17:06
            [LID] =>
            [PERSONAL_PROFESSION] =>
            [PERSONAL_WWW] =>
            [PERSONAL_ICQ] =>
            [PERSONAL_GENDER] =>
            [PERSONAL_BIRTHDATE] =>
            [PERSONAL_PHOTO] =>
            [PERSONAL_PHONE] =>
            [PERSONAL_FAX] =>
            [PERSONAL_MOBILE] =>
            [PERSONAL_PAGER] =>
            [PERSONAL_STREET] =>
            [PERSONAL_MAILBOX] =>
            [PERSONAL_CITY] =>
            [PERSONAL_STATE] =>
            [PERSONAL_ZIP] =>
            [PERSONAL_COUNTRY] =>
            [PERSONAL_NOTES] =>
            [WORK_COMPANY] => Филиал АО &quot;Табуретка&quot; в г.Санкт-Петербург
            [WORK_DEPARTMENT] => Дирекция информационной безопасности
            [WORK_POSITION] => Аналитик информационных систем
            [WORK_WWW] =>
            [WORK_PHONE] =>
            [WORK_FAX] =>
            [WORK_PAGER] =>
            [WORK_STREET] =>
            [WORK_MAILBOX] =>
            [WORK_CITY] =>
            [WORK_STATE] =>
            [WORK_ZIP] =>
            [WORK_COUNTRY] =>
            [WORK_PROFILE] =>
            [WORK_LOGO] =>
            [WORK_NOTES] =>
            [ADMIN_NOTES] =>
            [STORED_HASH] =>
            [XML_ID] =>
            [PERSONAL_BIRTHDAY] => 09.11.1998
            [EXTERNAL_AUTH_ID] => LDAP#9
            [CHECKWORD_TIME] => 2023-01-30 13:02:14
            [SECOND_NAME] =>
            [CONFIRM_CODE] =>
            [LOGIN_ATTEMPTS] =>
            [LAST_ACTIVITY_DATE] =>
            [AUTO_TIME_ZONE] =>
            [TIME_ZONE] =>
            [TIME_ZONE_OFFSET] =>
            [TITLE] =>
            [BX_USER_ID] =>
            [LANGUAGE_ID] =>
            [IS_ONLINE] => N
            [UF_DEPARTMENT] => Array
                (
                    [0] => 6404
                )

            [UF_SAP_PHOTO_SYNC] =>
        )
 */
require_once dirname(__FILE__)."/../Abstract/COrgStructureStorage.php";


class CBxUserList extends COrgStructureStorage {
	
	/**
	 * @var $sap CSapUserList
	 */
	private $sap=null;
	
	public function attachSap(&$sap){$this->sap=$sap;}
	
	/*
	 * При создании загружает список пользователей
	 */
	public function __construct(){
		$by='ID';
		$sort='asc';
		$usrList=CUser::GetList($by,$sort,[],['SELECT'=>['UF_DEPARTMENT','UF_SAP_PHOTO_SYNC','UF_INN']]);
		while($ob = $usrList->GetNext(false,false))
			$this->initItem ($ob,'ID');
			echo count($this->data)." items loaded in Bitrix Usr storage \n";
			//print_r($this->data);
	}
	
	/*
	 * Загружает один элемент из битрикса
	 * релоад названо потомучто для загрузки по ID надо знать этот ID, что как бы намекает
	 * @return bool успех или неудача загрузки
	 */
	public function reloadItem($id){
		$res = CUser::GetByID($id);
		if($ar_res = $res->Fetch()){
			$this->initItem($ar_res,'ID');
			return true;
		} else
			return false;
	}
	
	/*
	 * Возвращает массив идентификаторов групп в которые входит пользователь
	 * @param string $id пользователя
	 * @return array список идентификаторов групп, в которые входит пользователь
	 */
	public static function getUserGroups($id){
		$res = CUser::GetUserGroupList($id);
		$grps = [];
		while ($arGroup = $res->Fetch()) {
			//print_r($arGroup);
			if (!is_array($arGroup)) $grps[]=$arGroup;
			elseif (isset($arGroup['GROUP_ID'])) $grps[]=$arGroup['GROUP_ID'];
		}
		return $grps;
	}

	/*
	 * Проверяет присутствие пользователя в группе
	 * @param string $user идентификатор пользователя
	 * @param array $groups список групп в которых должен быть пользователь. можно передать одну не в виде массива
	 * @return bool признак наличия пользователя во всех переданных группах
	 */
	public static function checkUserGroups($user,$groups){
		//echo "getting $user groups\n";
		//print_r($user_groups);
		if (!is_array($groups)) $groups=[$groups]; //определимся, что мы работаем именно с массивом, хотябы из одного элемента
		if (is_null($user)||!count($groups)) return true; //если список пустой или пользователя нет, то проверять нечего
		
		$user_groups= CBxUserList::getUserGroups($user);
		//echo "go into loop...\n";
		foreach ($groups as $grp) 
			if (!in_array($grp, $user_groups)) return false;
		return true;
	}
	
	/*
	 * Добавление пользователя в группы(у)
	 * @param string $user идентификатор пользователя
	 * @param array $groups список групп в которых должен быть пользователь. можно передать одну не в виде массива
	 */
	public static function addUserGroups($user,$groups){
		// привязка пользователя с кодом 10 дополнительно к группе c кодом 5
		if (!is_array($groups)) $groups=[$groups]; //определимся, что мы работаем именно с массивом, хотябы из одного элемента
		echo "adding $user groups [".implode(',',$groups)."] ";
		$arGroups = CBxUserList::getUserGroups($user);
		$arGroups = array_merge($arGroups,$groups);
		CUser::SetUserGroup($user, $arGroups);
		return CBxUserList::checkUserGroups($user, $groups);
	}
	
	/*
	 * Проверка наличия и добавление если нужно пользователя в группы(у)
	 * @param string $user идентификатор пользователя
	 * @param array $groups список групп в которых должен быть пользователь. можно передать одну не в виде массива
	 */
	public static function fixUserGroups($user,$groups){
		return (CBxUserList::checkUserGroups($user, $groups))||(CBxUserList::addUserGroups($user, $groups));
	}
	
	/*
	 * Ищет дубликаты пользователя
	 * @param string $id пользователя
	 * @return array список идентификаторов пользователей с таким же логином и именами
	 */
	public function findDupes($id){
		$name=$this->getItemField($id,'NAME');
		$lname=$this->getItemField($id,'LAST_NAME');
		$login=$this->getItemField($id,'LOGIN');
		$found=[];
		foreach ($this->getIds() as $cmp)
			if (($cmp!=$id)&&(
					//если совпадает ФИО 
					(($this->getItemField($cmp,'NAME')==$name)&&($this->getItemField($cmp,'LAST_NAME')==$lname))||
					//или логин
					($this->getItemField($cmp,'LOGIN')==$login)
					)) $found[]=$cmp;
					return $found;
	}
	
	
	/**
	 * Ищем объект в сапе по имени
	 */
	public function getSapID($id){
		//если внутри пользователя записан его номер сотрудника и по нему он ищется - то его и вернем
		if (strlen($employeeId=$this->getItemField($id,'XML_ID'))) {
			try {
				if (!is_null($sapID=$this->sap->getMainEmployment(['Pernr'=>$employeeId]))) return $sapID;
			} catch (Exception $e) {
				//если таких двое то прикидываемся как будто ничего и не нашли
				var_dump($e);
				$sapID=null;
			}
		}

		//если внутри пользователя записан его номер сотрудника и по нему он ищется - то его и вернем
		if (strlen($uid=$this->getItemField($id,'UF_INN'))) {
			try {
				if (!is_null($sapID=$this->sap->getMainEmployment(['uid'=>$uid]))) return $sapID;
			} catch (Exception $e) {
				//если таких двое то прикидываемся как будто ничего и не нашли
				var_dump($e);
				$sapID=null;
			}
		}

		//ищем сначала по ФИО
		try {
			$sapID=$this->sap->getMainEmployment(['Ename'=>($this->getItemField($id,'LAST_NAME').' '.$this->getItemField($id,'NAME'))]);
		} catch (Exception $e) {
			//если таких двое то прикидываемся как будто ничего и не нашли
			var_dump($e);
			$sapID=null;
		}

		//потом по ИОФ
		if (!is_null($sapID)) return $sapID;
		try {
			$sapID=$this->sap->getMainEmployment(['Ename'=>($this->getItemField($id,'NAME').' '.$this->getItemField($id,'LAST_NAME'))]);
		} catch (Exception $e) {
			var_dump($e);
			$sapID=null;
		}
		return $sapID;
	}
	
	/*
	 * обновляет подразделение ID полями arFields
	 * @param string $id идентификатор пользователя
	 * @param array $arFields набор полей для записи
	 * @return bool успех операции
	 */
	public function updItem($id,$arFields){
		//почистим
		$arFields=CbxOrgStructure::cleanParams($arFields);
		
		//dry-run: не пишем, только сообщаем о намерении
		if ($this->readOnly) {
			echo "[DRY-RUN] пользователь #$id: обновил бы ".json_encode($arFields, JSON_UNESCAPED_UNICODE)."\n";
			return true;
		}

		//пишем
		$bs = new CUser();
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
	 * Правит пользователя либо переданными параметрами либо если передан null берет параметры из SAP
	 *
	 */
	public function correctItem($id,$fields=null){
		if (is_null($fields)) {
			//echo 'Using sap source ';
			if (!($sapID=$this->getSapID($id))) return false; //такого пользователя вообще нет в САП!
			$fields=$this->sap->bxGenFields($sapID);
		}
		//return true;
		return $this->ckItem($id,$fields)||$this->updItem($id,$fields);
	}
}

?>
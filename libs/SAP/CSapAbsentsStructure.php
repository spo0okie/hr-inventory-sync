
<?php
/*
 * Файл с классами и прочим стафом для работы с графиком отсутствий в портале и в САП
 * ВНИМАНИЕ файл не поддерживается в актуальном состоянии, т.к. нет на текущий момент источника данных типа SAP
 * HINT: 
 * 	в портале график отсутствий находится через интерфейс Контент->Оргструктура->График отсутствий
 * 	Технически из себя представляет инфоблок (номер 3) с кучей элементов (непосредственно событий отсутствия)
 * 	Каждый Элемент имеет такие свойства:
 * 		ID		Название			Тип				Код
 * 		#4		Пользователь		Сотрудник		USER			//собственно кто у нас отсутствует - ссылка на юзера
 * 		#5		Состояние завршения	Строка			FINISH_STATE	//ни малейшего понятия что это значит
 * 		#6		Состояние			Строка			STATE
 * 		#7		Тип отсутствия		Список			ABSENCE_TYPE	//тут надо передать что у наз за причина (список далее)
 * 		#361	SAP_ID				Строка			SAP_ID			//это я для себя добавил, чтобы можно было четко идентифицировать этот отпуск в САП
 * 		
 * 			Элементы списка типов отсутсвия:
 * 			ID		XML_ID				Значение
 * 			8		VACATION			отпуск ежегодный
 * 			9		ASSIGNMENT			командировка
 * 			10		LEAVESICK			больничный
 * 			11		LEAVEMATERINITY		отпуск декретный
 * 			12		LEAVEUNPAYED		отгул за свой счет
 * 			13		UNKNOWN				прогул
 * 			14		OTHER				другое
 * 			293		PERSONAL			персональные календари
 * 
 * HINT:2
 * 	В САПе нет ключей для отпусков, ключ состоит из полей 
 * 		MAN = 555	//мандат
 * 		PERNR		//табельный номер
 * 		SUBTY		//то что я видел совпадало с полем AWART - тип отпуска
 * 		OBJPS		//хз, те что я видел были пустыми
 * 		SPRPS		//хз, те что я видел были пустыми
 * 		ENDDA		//ну понятно что конец
 * 		BEGDA		//и начало периода
 * 		SEQNR		//хз, те что я видел были пустыми
 * 	мне Альберт выгрузил поля 
 * 		PERNR		//табельный номер
 * 		AWART 		//тип отпуска
 * 		ENDDA		//ну понятно что конец
 * 		BEGDA		//и начало периода
 * 		ABSTEXT		//Текст отсутствия
 * 	т.о. из первых четырех собираем ключ и кладем его в поле SAP_ID
 * 
 */


/*
 * Таблица соответствия кодов типов отсутствия в САП и портале
 * HINT:
 *	Элементы списка типов отсутсвия:
 *	ID		XML_ID				Значение
 *	8		VACATION			отпуск ежегодный
 *	9		ASSIGNMENT			командировка
 * 	10		LEAVESICK			больничный
 * 	11		LEAVEMATERINITY		отпуск декретный
 * 	12		LEAVEUNPAYED		отгул за свой счет
 * 	13		UNKNOWN				прогул
 * 	14		OTHER				другое
 * 	293		PERSONAL			персональные календари
 * со стороны САП сортировка предоставлена Перовой Светланой
 * согласовано с Халаковой А.А.
 */



/*
 * Класс грузящий структуру отсутствий из САП
 */
class CSapAbsentsStructure extends COrgStructureStorage {

	static $bxUsersCache=[]; //Кэш пользователей которых нет в битриксе. Чтобы сократить обращения в БД

	static function getDescription($id,$dict){
		foreach ($dict as $word => $ids)
			if (in_array($id,$ids)) return $word;
		return false;
	}

	static $absentsTypes=[
		[
			'keys'=>[100,110,190,250,9110,601,602,9210,'Дополнительный отпуск',],
			'descr'=>'Отпуск',
			'type'=>8,
			],
		[
			'keys'=>['Командировка'],
			'descr'=>'Командировка',
			'type'=>9,
			],
		[
			'keys'=>[200,201,202,203,204,205,206,207,208,210,211,212,227,385,386,387,388,9200],
			'descr'=>'Болезнь',
			'type'=>10,
			],
		[
			'keys'=>[660,650,9660],
			'descr'=>'Другое',
			'type'=>14,
		],
		[
			'keys'=>[500,501,503,9501],
			'descr'=>'Отпуск по уходу за ребенком',
			'type'=>11,
		],
		[
			'keys'=>['Прогул'],
			'descr'=>'Прогул',
			'type'=>13,
		],
		[
			'keys'=>['Отпуск неоплачиваемый по разрешению работодателя'],
			'descr'=>'Отгул',
			'type'=>12,
		],
	];


	/*
	 * Грузит данные из XML файла
	 * @param string $filename файл для загрузки
	 * @return bool успех загрузки данных
	 */
	public function loadFromXml($filename){
		//грузим данные САП из файла:
		$this->data=COrgStructureStorage::loadFromXml($filename,'OAbsents',['Pernr','Awart','Begda','Endda']);
		echo count($this->data)." items loaded in SAP Absents storage\n";
		//var_dump($this->data);
		return is_array($this->data);
	}
	
	
	/*
	 * Грузит данные из JSON файла
	 * @param string $filename файл для загрузки
	 * @return bool успех загрузки данных
	 */
	public function loadFromJson($filename){
		//грузим данные САП из файла:
		$this->data=COrgStructureStorage::loadFromJson($filename,'OAbsents',['Pernr','Awart','Begda','Endda']);
		echo count($this->data)." items loaded in SAP Absentsstorage\n";
		//var_dump($this->data);
		return is_array($this->data);
	}
	
	/*
	 * Конвертирует тип отсутствия с САП кодов в Битрикс коды:
	 * @param string $sapType код отсутствия в сапе
 	 * @return int код отсутствия в Битриксе
 	 */
	public static function absTypeFromSap($sapType){
		foreach (static::$absentsTypes as $data)
			if (in_array($sapType,$data['keys'])) return $data['type'];
		return false;
	}
	
	/*
	 * Конвертирует тип отсутствия с САП кодов в описание
	 * (менее подробное чем в САПе, т.н. public-access safe)
	 * @param string $sapType код отсутствия в сапе
	 * @return string описание отсутсвия 
	 */
	public static function absDescrFromSap($sapType){
		foreach (static::$absentsTypes as $data)
			if (in_array($sapType,$data['keys'])) return $data['descr'];
		return false;
	}


	public function getBxUser($sapID) {
		if (isset(static::$bxUsersCache[$sapID])) return static::$bxUsersCache[$sapID];

		$users=CUser::GetList(
			$by='id',
			$order='desc',
			[
				'ACTIVE'=>Y,
				'XML_ID'=>$sapID,
			]
		);

		//выбираем первого из списка
		if (!($user=$users->GetNext()))
			return (static::$bxUsersCache[$sapID]=false);

		return (static::$bxUsersCache[$sapID]=$user);
	}

	/*
	 * Генерирует список полей, которые должны быть в битриксе у этого отсутствия
	 * @param string $id идентификатор элемента
	 * @return array список полей с заполненными значениями, которые должны быть в битриксе
	 */
	public function bxGenFields($id){
		//ищем пользователя битрикс с табельным номером Pernr
		//получаем список всех с этим табельным номером (на самом деле 1 или 0)

		//табельник сотрудника
		$sapID=$this->getItemField($id,'Pernr');
		//тип отстуствия
		$sapType=$this->getItemField($id,'Awart');

		//пользователь битрикс
		if (!($user=$this->getBxUser($sapID)))
			return "Unknown user: $sapID";

		//Наименование отсутствия
		if (!($bxAbsenceName=static::absDescrFromSap($sapType)))
			return "Unknown absence descr: $sapType";

		//Тип отсутствия (категория битрикса)
		if (!($bxAbsenceType=static::absTypeFromSap($sapType)))
			return "Unknown absence type: $sapType";

		$fields=[
			'IBLOCK_SECTION_ID'=>false,			//кладем в корень инфоблока, без каких-л секций
			'IBLOCK_ID'=>3,						//вообще то он третий, но может стоит его выдергивать по явным признакам блока оргструктуры
			'ACTIVE'=>'Y',						//ищем только среди активных
			'NAME'=>$bxAbsenceName,				//Тут описание из САП
			'ACTIVE_FROM'=>date('d.m.Y 07:00:00',strtotime($this->getItemField($id,'Begda'))),		//границы понятное дело
			'ACTIVE_TO'=>date('d.m.Y  22:00:00',strtotime($this->getItemField($id,'Endda'))),
			'PROPERTY_VALUES'=>[
				'USER'=>$user['ID'],			//ссылка на пользователя чей отпуск
				'ABSENCE_TYPE'=>$bxAbsenceType,	//конвертируем тип отсутствия из САПа в Битрикс
				'SAP_ID'=>$id,
			]
		];
		return $fields;
	}
}

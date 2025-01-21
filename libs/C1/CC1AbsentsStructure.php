<?php
/*
 * Файл с классами и прочим стафом для работы с графиком отсутствий в портале и в САП
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
class CC1AbsentsStructure extends COrgStructureStorage {

	public $org_id=1;

	private static $csvFields=[
		'Objid',        //id
		'Pernr',        //Табельный
		'Begda',        //Начало
		'Endda',        //Окончание
		'Awart',        //Описание
	];


	static $absentsTypes=[
		[
			'keys'=>['Дополнительный отпуск','Отпуск основной'],
			'descr'=>'Отпуск',
			'type'=>8,
		],
		[
			'keys'=>['Командировка'],
			'descr'=>'Командировка',
			'type'=>9,
		],
		[
			'keys'=>['Болезнь',],
			'descr'=>'Болезнь',
			'type'=>10,
		],
		[
			'keys'=>['Отсутствие по невыясненным причинам'],
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
	 * Грузит данные из CSV файла
	 * @param string $filename файл для загрузки
	 * @return bool успех загрузки данных
	 */
	public function loadFromCsv($filename){
		//грузим данные САП из файла:
		$this->data=COrgStructureStorage::loadFromCsv(
			$filename,
			static::$csvFields,
			static::$csvFields[0]
		);
		echo count($this->data)." items loaded in С1 Org storage\n";
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
		//echo ".";
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
	
	/*
	 * Генерирует список полей, которые должны быть в битриксе у этого отсутствия
	 * @param string $id идентификатор элемента
	 * @return array список полей с заполненными значениями, которые должны быть в битриксе
	 */
	public function bxGenFields($id){
		//ищем пользователя битрикс с табельным номером Pernr

		//тут у нас изменения. с того момента, как мы поддерживаем несколько организаций
		//у нас в XML_ID не пишется табельник, а пишется ИД польлзователя в инвентаризации
		//потому нам еще надо выяснить а какой XML-ID искать. Для этого ищем юзера по org_id и pernr

		$user_num=$this->getItemField($id,'Pernr'); //Табельный
		global $inventory_API_url;

		//делаем запрос в инвентаризацию по юзеру с таким табельным в такой организации
		$search_url="{$inventory_API_url}/users/view?num={$user_num}&org={$this->org_id}";
		$api_response=file_get_contents($search_url);
		//если ответ невалидный json, значит неудача
		if (is_null($json=json_decode($api_response,true))) return false;
		//нету ИД - непонятно что искать в битриксе
		if (!isset($json['id'])) return false;
		$xml_id=$json['id'];
		//echo "$xml_id\n";
		//получаем список всех с этим табельным номером (на самом деле 1 или 0)
		$users=CUser::GetList(
			$by='id',
			$order='desc',
			[
				'ACTIVE'=>'Y',
				'XML_ID'=>$xml_id
			]
		);
		//выбираем первого из списка
		if (!($user=$users->GetNext())) {
			//echo "-\n";
			return false;
		};// else echo "+\n";
		//echo $this->getItemField($id,'Awart')."\n";
		//echo static::absDescrFromSap($this->getItemField($id,'Awart'))."\n";

		$fields=[
			'IBLOCK_SECTION_ID'=>false,			//кладем в корень инфоблока, без каких-л секций
			'IBLOCK_ID'=>3,						//вообще то он третий, но может стоит его выдергивать по явным признакам блока оргструктуры
			'ACTIVE'=>'Y',						//ищем только среди активных
			'NAME'=>static::absDescrFromSap($this->getItemField($id,'Awart')),//Тут описание из САП
			'ACTIVE_FROM'=>date('d.m.Y',strtotime($this->getItemField($id,'Begda'))),		//границы понятное дело
			'ACTIVE_TO'=>date('d.m.Y',strtotime($this->getItemField($id,'Endda'))),
			'CODE'=>$id,
			'PROPERTY_VALUES'=>[
				'USER'=>$user['ID'],		//ссылка на пользователя чей отпуск 
				'ABSENCE_TYPE'=>static::absTypeFromSap($this->getItemField($id,'Awart')),		//конвертируем тип отсутствия из САПа в Битрикс
			]
		];
		return $fields;
	}
	

}

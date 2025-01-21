<?php
/*
 *
 * Оргструктура из такого:
<item>
     <Seqnr>71</Seqnr> - номер узла в дереве
     <Objid>10000304</Objid> - идентификатор подразделения (использвется для синхронизации со структурой в портале)
     <Orgtx>Бухгалтерия</Orgtx> - имя подразделения
     <Pdown>0</Pdown> - Дочерний узел, хз зачем он, ведь дочерних несколько а тут только одно значение. Я не использую
     <Pup>22</Pup> - Родительский узел. Используется для выстраивания дерева
</item>
 *
 */

require_once dirname(__FILE__)."/../Abstract/COrgStructureStorage.php";

/*
 * Класс для взаимодействия структурой из САП
 * в контексте этого скритпа - данные САП опорные, и только для чтения
 */
class CC1OrgStructure extends COrgStructureStorage{

	private static $csvFields=[
		'Objid',        //КодПодразделения;
		'Orgtx',        //НазваниеПодразделения;
		'Pup'           //КодРодителя
	];

	/*
	 * Ссылка на внешний объект CbxOrgStructure
	 * нужен для проверки соответствия
	 */
	private $bx=null;

	/*
	 * Ссылка на внешний объект CC1UserList
    * нужен для проверки соответствия
    */
	private $users=null;

	/*
	 * прицепляет объет битрикс структуры для взаимодейстивия с ним
	 * @param CbxOrgStructure $bx объект структуры подразделений в битриксе
	 */
	public function attachBx(CBxOrgStructure &$bx){$this->bx=$bx;}


	/*
	 * Грузит данные из CSV файла
	 * @param string $filename файл для загрузки
	 * @return bool успех загрузки данных
	 */
	public function loadFromCsv($filename){
		//грузим данные САП из файла:
		$this->data=COrgStructureStorage::loadFromCsv($filename,static::$csvFields,'Objid');
		echo count($this->data)." items loaded in С1 Org storage\n";
		//var_dump($this->data);
		return is_array($this->data);
	}


	/*
	 * Ищет родительский элемент для $id
	 * @param string $id, чей родитель нас интересует
	 * @return string идентификатор родтельского для $id элемента или null, если родителя нет
	 */
	public function getParent($id){
		return $this->getItemField($id,'Pup',null);
	}
	
	/*
	 * проверяет наличие указанного подразделения в битриксе
	 * при отсутствии автоматически пытается такое подразделение добавить
	 * @param string $id идентификатор элемента, чье соответствие надо найти в битриксе
	 * @return string id подразделения в структуре битрикса или
	 */
	public function ckBxItemExist($id){
		//если битрикс не подключен - работать не можем
		if (is_null($this->bx)) die('No bx object attached!');
		
		//если уже проверен - значит все ок
		if (!is_null($res=$this->getItemField($id,'bx_item_id'))) return $res;
		
		//если есть предок - проверяем сначала его, ибо проверять можно только от корня
		if (!is_null($parent=$this->getParent($id)))
			//если предок установлен в 'NULL' то мы его не проверяем, т.к. это указание на корень
			if (
				(strcmp($parent,'NULL')!==0)
				&&
				!$this->ckBxItemExist($parent)
			) return null;
			
		//если в структуре битрикса такого подразделения нет - косяк
		//собственно ради этого момента и вся проверка)
		var_dump($this->bxGenFields($id));
		if (
			is_null($bxID=$this->bx->findIDBy('CODE',$id))&&	//не нашли
			is_null($bxID=$this->bx->addItem($this->bxGenFields($id))) //и создать не получилось
		)  //return null;

		//die('adding item');
		return $this->data[$id]['bx_item_id']=$bxID;
	}
	
	/*
	 * проверяет наличие у этого элемента соответствия в битриксе с правильно проставленными полями
	 * если подразделения нет - создает (через метод выше), если оно некорректное - правит
	 * @param string $id идентификатор элемента, чье соответствие надо найти в битриксе
	 * @return bool
	 */
	public function ckBxItemFields($id){
		echo('checking '.$id."\n");
		//если битрикс не подключен - работать не можем
		if (is_null($this->bx)) die('No bx object attached!');
		
		//если уже проверен - значит все ок
		if ($this->getItemField($id,'checked_bxfields_ok')) return true;
		
		//если есть предок - проверяем сначала его, ибо проверять можно только от корня
		if (!is_null($parent=$this->getParent($id))) {
			//если предок установлен в 'NULL' то мы его не проверяем, т.к. это указание на корень
			if (
				(strcmp($parent,'NULL')!==0)
				&&
				!$this->ckBxItemFields($parent)
			) return false;
		}

		//находим идентификатор в битриксе
		if (is_null($bxID = $this->ckBxItemExist($id))) return false;

		var_dump($this->bxGenFields($id));
		die('correcting fields');
		//возвращаем и кэшируем проверку (с учетом попытки исправления) полей в битриксе
		return $this->data[$id]['checked_bxfields_ok']=$this->bx->correctItem($bxID, $this->bxGenFields($id));
	}
	
	/*
	 * Генерирует список полей, которые должны быть в битриксе у секции соответствующей этому итему в САП
	 * @param string $id идентификатор элемента
	 * @return array список полей с заполненными значениями, которые должны быть в битриксе
	 */
	public function bxGenFields($id){
		//die('generating fields for '.$id);
		return $this->bx->bxGenFields(
			$id,
			$this->getItemField($id,'Orgtx'),
			$this->getParent($id)
		) ;
	}
	
	/*
	 * Возвращает полный путь до корня дерева
	 * не тестировалось. использовать с осторожностью
	 * @param string $id - конечный узел дерева от которого движемся к корню
	 */
	public function getItemFullPath($id){
		$cnt=1; //счетчик узлов пути
		$current=$id; //текущий узел
		$straight[$cnt-1]=$this->getItem($current);
		
		while($current=$this->getParent($current)){
			$cnt++;
			$straight[$cnt-1]=$this->getItem($current);
		}
		
		for ($i=0;$i<$cnt;$i++){
			$reverse[$i]=$straight[$cnt-$i-1];
		}
		
		return $reverse;
	}
}



?>
   
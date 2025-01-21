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

require_once 'COrgStructureStorage.php';

/*
 * Класс для взаимодействия структурой из САП
 * в контексте этого скритпа - данные САП опорные, и только для чтения
 */
class CSQLOrgStructure extends COrgStructureStorage {

	private $db=null;

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
	 * Грузит данные из SQL
	 * @param string $filename файл для загрузки
	 * @return bool успех загрузки данных
	 */
	public function loadFromSQL($path,$user,$pwd,$db){
		$this->db = new mysqli($path,$user,$pwd,$db);
		/* проверка соединения */
		if ($this->db->connect_errno) {
			printf("Не удалось подключиться: %s\n", $this->db->connect_error);
			exit();
		}

		$req_sql = 'set names "utf8"';
		$this->db->query($req_sql);

		$res_obj=$this->db->query('select * from org_struct');
		if (is_object($res_obj)) while (is_array($res=$res_obj->fetch_assoc())){
			//считаем количество пользователей в подразделении
			$wcount_obj=$this->db
				->query(
					"select count(id) as wcount from users ".
					"where (Uvolen=0) ".
					"and (not TRIM(Login)='') ".
					"and (Orgeh='${res['id']}') ".
					"and (org_id='${res['org_id']}') "
				);
			$res['wcount']=null;
			if (is_object($wcount_obj)) {
				$wcount_arr=$wcount_obj->fetch_assoc();
				if (is_array($wcount_arr)&&isset($wcount_arr['wcount'])) $res['wcount']=$wcount_arr['wcount'];
			}
			$this->data[$res['org_id'].'_'.$res['id']]=$res;
		} else {
			//printf("Ошибка: %s\n", );
			die($this->db->error);
		}
		echo count($this->data)." items loaded in SQL Org storage\n";
		//var_dump($this->data);
		return is_array($this->data);
	}


	/*
	 * Ищет родительский элемент для $id
	 * @param string $id, чей родитель нас интересует
	 * @return string идентификатор родтельского для $id элемента или null, если родителя нет
	 */
	public function getParent($id){
		$pup=$this->getItemField($id,'pup',null);
		if (is_null($pup)) return null;
		return $this->getItemField($id,'org_id','NULL').'_'.$pup;
	}

	/*
	 * Ищет родительский элемент для $id
	 * @param string $id, чей родитель нас интересует
	 * @return string идентификатор родтельского для $id элемента или null, если родителя нет
	 */
	public function getChilds($id){
		$childs=[];
		foreach ($this->getIds() as $itemid)
			if ($this->getItemField($itemid,'pup',null)==$id) $childs[]=$itemid;
		return $childs;
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

		$org_id=$this->getItemField($id,'bx_item_id',1);

		//если есть предок - проверяем сначала его, ибо проверять можно только от корня
		if (!is_null($parent=$this->getParent($id)))
			//если предок установлен в 'NULL' то мы его не проверяем, т.к. это указание на корень
			//тут вы вероятно спросите, а что за NULL, откуда взялся. А берется он прямо из SQL таблицы
			//а в нее кладется при загрузке из САП/1С, т.к. там отдается ровно в таком виде
			//поскольку оргуструктуры организаций не могут быть связаны то проверяем соответствие с той же
			//что и у потомка

			if (
				$parent!="{$org_id}_NULL"
				&&
				!$this->ckBxItemExist($parent)
			)  {
				//die('not exist parent( ['.$parent.']');
				return false;
			}
		//если в структуре битрикса такого подразделения нет - косяк
		//собственно ради этого момента и вся проверка)
		//var_dump($this->bxGenFields($id));
		//die('adding item');
		if (
			is_null($bxID=$this->bx->findIDBy('CODE',$id))&&	//не нашли
			is_null($bxID=$this->bx->addItem($this->bxGenFields($id))) //и создать не получилось
		)  return null;

		return $this->data[$id]['bx_item_id']=$bxID;
	}
	
	/*
	 * проверяет наличие у этого элемента соответствия в битриксе с правильно проставленными полями
	 * если подразделения нет - создает (через метод выше), если оно некорректное - правит
	 * @param string $id идентификатор элемента, чье соответствие надо найти в битриксе
	 * @return bool
	 */
	public function ckBxItemFields($id){
		//echo('checking '.$id."\n");
		//если битрикс не подключен - работать не можем
		if (is_null($this->bx)) die('No bx object attached!');
		
		//если уже проверен - значит все ок
		if ($this->getItemField($id,'checked_bxfields_ok')) return true;

		$org_id=$this->getItemField($id,'org_id',1);

		//если есть предок - проверяем сначала его, ибо проверять можно только от корня
		if (!is_null($parent=$this->getParent($id))) {
			//если предок установлен в 'NULL' то мы его не проверяем, т.к. это указание на корень
			if (
				strcmp($parent,"{$org_id}_NULL")
				&&
				!$this->ckBxItemFields($parent)
			) {
				//die('parent( ['.$parent.']');
				return false;
			}
		}

		//находим идентификатор в битриксе
		if (is_null($bxID = $this->ckBxItemExist($id))) return false;

		//var_dump($this->bxGenFields($id));
		//var_dump($this);
		//die('correcting fields');
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
			$this->getItemField($id,'name'),
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

	public function rwcount($id) {
		$rwcount=$this->getItemField($id,'wcount',0);;
		foreach ($this->getChilds($id) as $child) {
			$rwcount+=$this->getItemField($child,'wcount',0);
		}
		return $rwcount;
	}
}



?>
   
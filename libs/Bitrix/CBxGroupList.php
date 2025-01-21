<?php
/*
Класс хранилище групп Битрикса. не помню зачем
 */

class CBxGroupList extends COrgStructureStorage {
	public function __construct(){
		$by='ID';
		$sort='asc';
		$list=CGroup::GetList($by,$sort,[],['SELECT'=>['UF_DEPARTMENT']]);
		while($ob = $list->GetNext(false,false))
			$this->initItem ($ob,'ID');
			echo count($this->data)." items loaded in Bitrix Grp storage \n";
			//print_r($this->data);
	}
}
	

?>
   
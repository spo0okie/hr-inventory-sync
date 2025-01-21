#!/usr/bin/php
<?php
/*
 * Скрипт синхронизации базы пользователей SAP -> Инвенторизация
 * выполняется из оболочки, а не через веб
 *
 * v1.1 - инициализация вынесена в библиотеку
 * v1.0 - Initial
 */

//включаем вывод всех ошибок
error_reporting(E_WARNING||E_ALL);


echo date('c')." Script started\n";

//загружаем конфигурацию
require_once "config.php";

//подключаем библиотеку с объектами
require_once "libs/OrgStruct.php";

$db = new mysqli(
	$inventory['ip'],
	$inventory['user'],
	$inventory['passwd'],
	$inventory['db']
);

$req_sql = 'set names "utf8"';
$req_obj = $db->query($req_sql);

foreach ($inventory_import['sources'] as $dataSrc) {
	echo "_iterating source\n\n";
	//выбираем из конфигурации источник данных
	//$dataSrc=$dataSrc_1c_gmn;
	$sapOrg=initOrgStructure($dataSrc);
	echo count($sapOrg->getIds())." objects loaded\n";
	$sapUsers=initUserList($dataSrc);
	echo count($sapUsers->getIds())." objects loaded\n";
	$org_id=$dataSrc['org_id'];

	$sync_errors=0;

	echo "syncing org ...\n";
	//грузим оргструткуру
	foreach ($sapOrg->getIds() as $sapID) {
		$id=$sapOrg->getItemField($sapID,'Objid');
		$name=$sapOrg->getItemField($sapID,'Orgtx');
		$pup=$sapOrg->getItemField($sapID,'Pup');
		$req_sql="INSERT INTO ".
    	    "org_struct(org_id,id,pup,name) ".
			"values(".
				"$org_id,".
    			"'$id',".
				"'$pup',".
				"'$name'".
			") on duplicate key update ".
				"pup='$pup',".
				"name='$name';";
		if (!$inventory_import['readonly_mode']) {
			if ($db->query($req_sql) === false) {
				echo "Error:REQ: $req_sql\n";
				$sync_errors++;
			}
		} else {
			echo "RO mode: $req_sql\n";
		}
	}
	echo "org done ...\n";



	echo "syncing users ...\n";
	foreach ($sapUsers->getIds() as $id) {
		$item=$sapUsers->getItem($id);

		//это специфика выгрузки из 1С
		$dismissed=(strlen($item['Uvolen'])&&$item['Uvolen']=='Уволен')?1:0;
		//ищем пользователя с этим табельным в этой организации
		$id=null;
		//считаем что синхронизиоваться по умолчанию он должен

		$id_obj=$db->query("select id,nosync from users where employee_id='${item['Pernr']}' and org_id=$org_id;");

		$nosync=false;
		if (is_object($id_obj)) {
			$res = $id_obj->fetch_assoc();
			if (is_array($res)){
				if (isset($res['id'])) $id=$res['id'];
				if (isset($res['nosync'])) $nosync=$res['nosync'];
			}
		}

		//формируем список полей
		$fields=[
			'Orgeh'		=>	"'${item['Orgeh']}'",
			'Doljnost'	=>	"'${item['Doljnost']}'",
			'Ename'		=>	"'${item['Ename']}'",
			'Bday'		=>	"'${item['Bday']}'",
			'Mobile'	=>	"'${item['Mobile']}'",
			'Persg'		=>	$item['Persg'],
			'employ_date'=>	"'${item['Employ_date']}'",
			'resign_date'=> "'${item['Resign_date']}'",
			'Uvolen'	=>	$dismissed,
		];

		foreach ($fields as $field=>$value) if (array_search($field,$inventory_import['fields'])===false) unset ($fields[$field]);

		//список полей для инсерта

		$insert_fields=implode(',',array_keys($fields));
		//список значений
		$insert_values=implode(',',$fields);

		//код для апдейта полей
		$update_fields=[];
		foreach ($fields as $field=>$value) $update_fields[]="$field=$value";
		$update_code=implode(',',$update_fields);

		if (is_null($id))
			$req_sql="insert into users (org_id,employee_id,$insert_fields) values (${org_id},'${item['Pernr']}',$insert_values)";
		else
		    $req_sql="update users set $update_code where id='$id'";

		if (!$inventory_import['readonly_mode']) {
			if (!$nosync) {
				if ($db->query($req_sql)===false){
					echo "Error:REQ: $req_sql\n";
					$sync_errors++;
				}
			} else echo "Skip $id ${item['Pernr']}\n";
		} else echo "RO mode: $req_sql\n";
	}
	echo "users done\n";

	unset ($sapOrg);
	unset ($saUsers);
}

if (isset($syncErrorsFile)){
    file_put_contents($syncErrorsFile,$sync_errors);
} else echo $sync_errors;

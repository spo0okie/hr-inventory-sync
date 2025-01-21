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

echo "Caching SAP data...\n";
system($sapCache);
echo "complete\n";
ob_flush;


//#запись данных о пользователе в САП
function pushUserData($pernr,$type,$value) {
	global $sapPushUserData;
	system("$sapPushUserData $pernr $type \"$value\"");
}




$db = new mysqli(
	$inventory['ip'],
	$inventory['user'],
	$inventory['passwd'],
	$inventory['db']
);

if ($db->connect_errno) {
	die ("Не удалось подключиться к MySQL: (" . $db->connect_errno . ") " . $db->connect_error);
}

$req_sql = 'set names "utf8"';
$req_obj = $db->query($req_sql);

foreach ($inventory_import['sources'] as $dataSrc) {
	echo "_iterating source\n\n";
	ob_flush();
	//выбираем из конфигурации источник данных
	//$dataSrc=$dataSrc_1c_gmn;
	$sapOrg=initOrgStructure($dataSrc);
	echo count($sapOrg->getIds())." objects loaded\n";
	ob_flush();
	$sapUsers=initUserList($dataSrc);
	echo count($sapUsers->getIds())." objects loaded\n";
	ob_flush();

	$sync_errors=0;

	echo "syncing org ...\n";
	ob_flush();
	//грузим оргструткуру

	foreach ($sapOrg->getIds() as $sapID) {
		$id=$sapOrg->getItemField($sapID,'Objid');
		$name=$sapOrg->getItemField($sapID,'Orgtx');
		$parentID=$sapOrg->getParent($sapID);
		$pup=$parentID?
			'\''.$sapOrg->getItemField($parentID,'Objid').'\'':
			'null';

		$org_id=null;
		foreach ($inventoryOrgs as $sapId=>$invId) {
		    if ($sapOrg->hasParent($id,$sapId)) $org_id=$invId;
        }

		if (!$org_id) continue;

		//echo "select * from org_struct where id='$id'\n";
		$id_obj=$db->query("select * from org_struct where id='$id'");
		if (is_object($id_obj) && is_array($res = $id_obj->fetch_assoc())) {
			$req_sql="UPDATE org_struct SET ".
				"org_id=$org_id, ".
				"hr_id=$pup, ".
				"name='$name' ".
				"where id='$id';";
		} else {
			//var_dump($id_obj);
			//var_dump($res);
			$req_sql="INSERT INTO ".
				"org_struct(org_id,id,hr_id,name) ".
				"values(".
				"$org_id,".
				"'$id',".
				"$pup,".
				"'$name'".
				");";
		}

        if (!$inventory_import['readonly_mode']) {
            if ($db->query($req_sql) === false) {
                echo "Error:REQ: $req_sql\n";
                $sync_errors++;
            }
            echo "REQ: $req_sql\n";
        } else {
            echo "RO mode: $req_sql\n";
        }
		ob_flush();

	}
	echo "org done ...\n";

//exit;

	echo "syncing users ...\n";
	foreach ($sapUsers->getIds() as $id) {
		$item=$sapUsers->getItem($id);
//		var_dump($item);
//		exit();
		//это специфика выгрузки из 1С
		$dismissed=(strlen($item['Uvolen'])&&$item['Uvolen']=='Уволен')?1:0;

		$org_id=null;
		foreach ($inventoryOrgs as $sapId=>$invId) {
			if ($sapOrg->hasParent($item['Orgeh'],$sapId)) $org_id=$invId;
		}

		if (!$org_id) continue;

		//ищем пользователя с этим табельным в этой организации
        //(орг-ю из условия убрали, т.к. табельник уникальный между всеми орг-ми)
        //echo "select id,nosync,Email,Phone,Mobile from users where employee_id='${item['Pernr']}'\n";
		$id_obj=$db->query("select id,nosync,Email,Phone,Mobile from users where employee_id='${item['Pernr']}'");

		$id=null;
		$Phone=null;
		$Email=null;
		$Mobile=null;
		//считаем что синхронизиоваться по умолчанию он должен
		$nosync=false;

		if (is_object($id_obj)) {
			$res = $id_obj->fetch_assoc();
			if (is_array($res)){
			    //echo "GOT ID!".$res['id']."!\n";
				if (isset($res['id']))      $id=$res['id'];
				if (isset($res['nosync']))	$nosync=$res['nosync'];
				if (isset($res['Email']))	$Email=	$res['Email'];
				if (isset($res['Phone']))	$Phone=	$res['Phone'];
				if (isset($res['Mobile']))	$Mobile=$res['Mobile'];
			}
		}

		//reverse push
		if (!$dismissed&&!is_null($id)) {
			if (trim($Email)&&(strtoupper(trim($Email))!=strtoupper(trim($item['Email'])))) {
				echo "got SAP email [${item['Email']}] instead of [$Email]\n";
				pushUserData($item['Pernr'],'0010',$Email);
			}

			if (trim($Phone)!=trim($item['ArbPhone'])) {
				echo "got SAP phone [${item['ArbPhone']}] instead of [$Phone]\n";
				pushUserData($item['Pernr'],'0020',$Phone);
			}

			if (trim($Mobile)!=trim($item['MobPhone'])) {
				echo "got SAP Mobile [${item['MobPhone']}] instead of [$Mobile]\n";
				pushUserData($item['Pernr'],'CELL',$Mobile);
			}
		}
		
		//оставляем в СНИЛС только цифры
		$uid=preg_replace( '/[^0-9]/', '', $item['Snils'] );
		$uid=strlen($uid)?md5($uid ):'';
		//echo $uid."\n";
		//формируем список полей
		$fields=[
			'org_id'	=>	$org_id,
			'Orgeh'		=>	"'${item['Orgeh']}'",
			'Doljnost'	=>	"'${item['Doljnost']}'",
			'Ename'		=>	"'${item['Ename']}'",
			'Bday'		=>	"'${item['Bday']}'",
			'Mobile'	=>	"'${item['Mobile']}'",
			'Persg'		=>	$item['Persg'],
			'employ_date'=>	"'${item['Employ_date']}'",
			'resign_date'=> "'${item['Resign_date']}'",
			'Uvolen'	=>	$dismissed,
			'uid'		=>	"'$uid'",
		];

		foreach ($fields as $field=>$value) {
			if (array_search($field, $inventory_import['fields']) === false) unset ($fields[$field]);
		}

		//список полей для инсерта
		$insert_fields=implode(',',array_keys($fields));
		//список значений
		$insert_values=implode(',',$fields);


		//код для апдейта полей
		$update_fields=[];
		foreach ($fields as $field=>$value) $update_fields[]="$field=$value";
		$update_code=implode(',',$update_fields);


		if (is_null($id))
			$req_sql="insert into users (employee_id,$insert_fields) values ('${item['Pernr']}',$insert_values)";
		else
		    $req_sql="update users set $update_code where id='$id'";

		if (!$inventory_import['readonly_mode']) {
			if (!$nosync) {
				if ($db->query($req_sql)===false){
					echo "Error:REQ: $req_sql\n";
					$sync_errors++;
				} echo "OK:REQ: $req_sql\n";
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

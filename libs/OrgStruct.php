<?php
/**
 * Bootstrap файл для загрузки оргструктуры на основании конфига
 * User: aareviakin
 * Date: 07.10.2019
 * Time: 23:36
 */


/**
 * Проверяет что все поля источника данных указаны
 * @param $src
 */
function validateDataSource(&$src,$descr) {
	echo "Validating $descr DataSource structure\n";
	if (!isset($src)) die( "Data structure $descr not set!");
	if (!isset($src['src'])) die( "Data source $descr not set!");
	if (!isset($src['ftype'])) die( "Data source $descr type not set!");
	if (!isset($src['path'])) die( "Data source $descr data path not set!");
}

function loadDataSource($src, &$sapOrg) {
	echo "Loading DataSource structure\n";
//грузим
	switch ($src['ftype']) {
		case 'csv':
			$sapOrg->loadFromCsv($src['path']);
			break;
		case 'json':
			$sapOrg->loadFromJson($src['path']);
			break;
		case 'xml':
			$sapOrg->loadFromXml($src['path']);
			break;
		case 'rest':
			//источник ARMS: path — массив ['url','user','pass','orgs']
			$cfg = $src['path'];
			$client = new CArmsRestClient(
				$cfg['url'],
				$cfg['user'] ?? '',
				$cfg['pass'] ?? ''
			);
			$sapOrg->loadFromRest($client, $cfg['orgs'] ?? []);
			break;
		default:
			die('Unknown org file type');
	}

}

/**
 * @param $dataSrc
 * @return COrgStructureStorage
 */
function initOrgStructure($dataSrc) {
//создаем объект оргструктуры
	echo "Initializing Org DataSource structure\n";
	validateDataSource($dataSrc['org'],'[org]');

	switch ($dataSrc['org']['src']) {
		case 'c1':
			echo "C1 mode\n";
			require_once "C1/CC1OrgStructure.php";
			$sapOrg = new CC1OrgStructure();
			break;
		case 'sap':
			echo "SAP mode\n";
			require_once "SAP/CSapOrgStructure.php";
			$sapOrg = new CSapOrgStructure();
			break;
		case 'arms':
			echo "ARMS mode\n";
			require_once "Arms/CArmsOrgStructure.php";
			$sapOrg = new CArmsOrgStructure();
			break;
		default:
			die('Unknown org data source');
	}
	loadDataSource($dataSrc['org'],$sapOrg);
	return $sapOrg;
}

/**
 * @param $dataSrc
 * @return COrgStructureStorage
 */
function initUserList($dataSrc) {
//создаем объект оргструктуры
	global $sapOrgDict;
	echo "Initializing User DataSource structure\n";
	validateDataSource($dataSrc['org'],'[usr]');


	switch ($dataSrc['usr']['src']) {
		case 'c1':
			echo "C1 mode\n";
			require_once "C1/CC1UserList.php";
			$sapOrg = new CC1UserList();
			break;
		case 'sap':
			echo "SAP mode\n";
			require_once "SAP/CSapUserList.php";
			$sapOrg = new CSapUserList();
			if (isset($sapOrgDict)) {
				echo "Dictionary attached\n";
				$sapOrg->attachOrgDict($sapOrgDict);
			}
			break;
		case 'arms':
			echo "ARMS mode\n";
			require_once "Arms/CArmsUserList.php";
			$sapOrg = new CArmsUserList();
			break;
		default:
			die('Unknown org data source');
	}

	loadDataSource($dataSrc['usr'],$sapOrg);
	return $sapOrg;
}
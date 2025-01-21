# SAP-Inventory-sync
Скрипты синхронзации Инвентаризации с внешними БД

## Синхронизируемые данные
| Поле             	| В БД инв.    	| 1С                	| SAP     	| Bitrix               	| AD                    	|
|------------------	|--------------	|-------------------	|---------	|----------------------	|-----------------------	|
| Полное имя       	| Ename        	| ФИО               	| Ename   	|                      	| cn, name, displayName 	|
| Фамилия          	|              	|                   	| Nachn   	| LAST_NAME            	| sn                    	|
| Имя              	|              	|                   	| Vorna   	| NAME                 	| givenName             	|
| Отчество         	|              	|                   	| Midnm   	| SECOND_NAME (не исп) 	|                       	|
| Табельный номер  	| employee_id  	| Табельный         	| Pernr   	| XML_ID (2nd token)   	| EmplyeeNumber         	|
| ИД Организации   	| org_id       	|                   	|         	| XML_ID (1st token)   	| EmployeeID            	|
| Название орг     	| через org_id 	|                   	|         	| WORK_COMPANY         	| company               	|
| Подразделение    	| через Orgeh  	|                   	| Orgtx   	| WORK_DEPARTMENT      	| department            	|
| id Подразделения 	| Orgeh        	| Код подразделения 	| Orgeh   	| UF_DEPARTMENT        	|                       	|
| Должность        	| Dojnost      	|                   	| Dojnost 	| WORK_POSITION        	| title                 	|
| Тип трудоустр.   	| Persg        	| ВидЗанятости      	| Persg   	|                      	|                       	|
| Дата приема      	| employ_date  	| ДатаПриема        	|         	|                      	|                       	|
| Дата увольнения  	| resign_date  	| ДатаУвольнения    	|         	|                      	|                       	|
| Уволен           	| Uvolen       	| Статус            	| Uvolen  	| ACTIVE               	|                       	|
| Login            	| Login        	|                   	| Uname   	| хз. импорт из AD     	| sAMAccountname        	|
| Email            	| Email        	|                   	|         	| хз. импорт из AD     	| mail                  	|
| Внутренний тел   	| Phone        	|                   	|         	| хз. импорт из AD     	| pager                 	|
| Мобильный        	| Mobile       	| Телефон           	|         	| хз. импорт из AD     	| mobile                	|
| Городской рабоч  	| work_phone   	|                   	|         	| хз. импорт из AD     	| telephoneNumber       	|
| День. рожд       	| Bday         	| Дата рождения     	| Gbdat   	| PERSONAL_BIRTHDAY    	|                       	|

Содержит срипты для следующих направлени синхронизации:
## Внешняя БД -> инвентаризация  
В качестве внешней БД использовались 1С и SAP.  

В этом направлении из внешней БД загружаются данные через библиотеки SAP/1C наследуемы от общего COrgStructureStorage  

В инвентаризацию данные пишутся прямо в SQL не используя АПИ

## Инвентаризация -> Bitrix

## Конфигурация
Файл конфигурации:  

Пример описания источника данных под одной организации:  
```
$dataSrc_1c_org1=[  //описываем организацию 1
	'org_id'=>1,      //индекс организации в БД инвентаризации
	'org'=>[          //оргструктура организации
		'src'=>'c1',    //тип - выгрузка из 1C 
		'ftype'=>'csv', //формат - CSV файл
		'path'=>'/tmp/sync_inventory/out_str2.csv',
	],
	'usr'=>[          //список сотрудников организации
		'src'=>'c1',    //тип - выгрузка из 1С
		'ftype'=>'csv', //формат - CSV файл
		'path'=>'/tmp/sync_inventory/out2.csv',
	],
];
```

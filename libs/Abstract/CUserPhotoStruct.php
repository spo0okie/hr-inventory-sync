<?php
/**
 * Created by PhpStorm.
 * User: spookie
 * Date: 25.06.2018
 * Time: 21:28
 */

class CUserPhotoStruct {
    private $timestamp=null;
    private $imgbase64=null;
    private $imgblob=null;
    private $bxFileID=null;
    private $bxFileName=null;
    private $bxUser=null;

    /**
     * Грузим данные из JSON файла
     * @param string $filename файл данных
     * @return bool успех
     */
    public function loadFromJson($filename) {
        if (($rawdata=file_get_contents($filename))===false) return false;
        $jsondata=json_decode($rawdata);
        $this->timestamp=$jsondata->ArDate;
        $this->imgbase64=$jsondata->OBuffer;
        return true;
    }

    public function getTimeStamp() {
        //если дата пуста, то чтото пошло не так
        if (!strlen($this->timestamp)) return 0;
        //если стоит нулевая дата, значит в САП никогда не заливали картинку
        if ($this->timestamp=='0000-00-00') return 0;

        return (int)strtotime($this->timestamp);
    }

    public function getImage() {
        //если стоит нулевая дата, значит в САП никогда не заливали картинку
        if (!$this->getTimeStamp()) return null;
        //если буфер пуст, то картинку нам не получить
        if (!strlen($this->imgbase64)) return null;
        //если уже есть расшифрованные данные, то используем их
        if ($this->imgblob!==null) return $this->imgblob;

        $this->imgblob=base64_decode($this->imgbase64);
	    return base64_decode($this->imgbase64);
    }

    public function getSapFilename($id) {
    	return "UserPhotoN$id-".$this->getTimeStamp().".jpg";
    }

    public function getBxFilename() {
    	return $this->bxFileName;
    }

	public function loadBxData($id) {
		$res = CUser::GetByID($id);
		if($ar_res = $res->Fetch()){
			$this->bxUser=$ar_res;
			$this->bxFileID=$ar_res['PERSONAL_PHOTO'];
			$rsFile=CFile::GetByID($this->bxFileID);
			if ($arFile=$rsFile->Fetch()){
				$this->bxFileName=$arFile['ORIGINAL_NAME'];
				return true;
			}
		}
		return false;
	}

	public function updateBxData($sapID,$bxID,$bxUsers=null){
		$fullName=$_SERVER['DOCUMENT_ROOT'] . '/upload/tmp/' . $this->getSapFilename($sapID);
		file_put_contents($fullName,$this->getImage());

		$arFile = CFile::MakeFileArray($fullName);
		$arFile['name']=$this->getSapFilename($sapID);
		$arFile['description'] = "User photo for user $bxID (SAP $sapID)";
		$arFile['MODULE_ID'] = 'main';
		if (strlen($this->bxUser['PERSONAL_PHOTO'])) {
			$arFile['del']='Y';
			$arFile['old_file']=$this->bxUser['PERSONAL_PHOTO'];
		}

		//$this->bxUser['PERSONAL_PHOTO']=$arFile;
		$fields=[
			'PERSONAL_PHOTO'    =>  $arFile,
			'UF_SAP_PHOTO_SYNC'	=>	$this->timestamp,
		];

		if (is_object($bxUsers)) {
			$bxUsers->updItem($bxID,$fields);
		} else {
			$oUser = new CUser;
			$oUser->Update($bxID, $fields); //$iUserID (int) ID of USER
		}
	}

}
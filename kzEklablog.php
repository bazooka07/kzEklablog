<?php
if(!defined('PLX_ROOT')) {
	exit;
}

class kzEklablog extends plxPlugin {
	const DATES_DICT = array(
		'date_creation'	=> 'created_at',
		'date_update'	=> 'modified_at',
		'date_publication'	=> 'modified_at',
	);

	public function __construct($default_lang) {
		parent::__construct($default_lang);
		$this->setAdminProfil(PROFIL_ADMIN);
		$this->setAdminMenu('Eklablog', false, 'Importation des données du blog');
	}

}

<?php
if(!defined('PLX_ROOT')) {
	exit;
}

class kzEklablog extends plxPlugin {
	const DATES_DICT = array(
		'date_creation'	=> 'created_at',
		'date_update'	=> 'modified_at',
		'date_publication'	=> 'published_at',
	);
	const CLEANUP_HTML = array(
		# Suppression lignes vides
		'#^\s*[\r\n]+#m' => '',
		# suppression des attributs style
		'#\s*style="[^"]*"#' => '',
		# Reformatage <div> de plusieurs lignes
		'#^\s*<div[\s\r\n]+(\w[^>]+)[\s\r\n]*>#mi'	=> '<div \1>',
		# Suppression image quote au format svg
		'#\s*<svg\s+class="ob-quote-\w+"[^>]*>.*?</svg>#si' => '',
		# suppression espaces début ligne
		'#^\s+#m'	=> '',
		# double <div> sur une ligne
		'#</div>\s+<div#'	=> "</div>\n<div",
	);

	public function __construct($default_lang) {
		parent::__construct($default_lang);
		$this->setAdminProfil(PROFIL_ADMIN);
		$this->setAdminMenu('Eklablog', false, 'Importation des données du blog');
	}

}

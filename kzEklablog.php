<?php
if(!defined('PLX_ROOT')) {
	exit;
}

class kzEklablog extends plxPlugin {
	const TEMPLATE_PATTERN = '#^article(?:-[\w-]+)?\.php$#';
	const FILTER_OPTIONS_INT = array(
		'default' => 0,
		'min_range' => 0,
		'max_range' => 1
	);
	const CHECKBOXES = array('post', 'page', 'comments', 'images');
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
		# Suppression du javascript
		'#\s*<script\b.*?</script>#si' => '',
		# Reformatage <div> de plusieurs lignes
		'#^\s*<div[\s\r\n]+(\w[^>]+)[\s\r\n]*>#mi'	=> '<div \1>',
		# Suppression image quote au format svg
		'#\s*<svg\s+class="ob-quote-\w+"[^>]*>.*?</svg>#si' => '',
		# suppression espaces début ligne
		'#^\s+#m'	=> '',
		# double <div> ou <p> sur une ligne
		'#</(div|p)>\s*<\1#'	=> '</$1>' . PHP_EOL . '<$1',
		#
		'#[\r\n]+\s*<#m'			=> '<',
	);
	const PATTERN_MEDIA = '#"https?://[\w\.-]+/[^"]*image([^"]+)"#';

	public function __construct($default_lang) {
		parent::__construct($default_lang);
		$this->setAdminProfil(PROFIL_ADMIN);
		$this->setAdminMenu('Eklablog', false, 'Importation des données du blog');

		if(!defined('PLX_ADMIN')) {
			$hook = 'ThemeEndBody';
			$this->addHook($hook, $hook);
		}
	}

	public function getTemplates($dir, $pattern) {
		# On récupère les templates des articles
		$files = glob($dir . 'article*.php');
		if (empty($files)) {
			return array('' => L_NONE1);
		}

		$aTemplates = array('' => '...');
		foreach($files as $v) {
			$aTemplates[basename($v)] = basename($v, '.php');
		}
		asort($aTemplates);
		return $aTemplates;
	}

	public function printCheckbox($name) {
		$fieldname = 'import-' . $name;
		$checked = !empty($this->getParam($fieldname)) ? 'checked' : '';
?>
				<label>
					<input type="checkbox" name="<?= $fieldname ?>" value="1" <?= $checked ?>>
					<span><?= $this->getLang(strtoupper($fieldname)) ?></span>
				</label>
<?php
	}

/*
<comments>
	<comment>
		<published_at>2025-01-19T01:04:07+01:00</published_at>
		<status>1</status>
		<author_name>Toto</author_name>
		<author_email/>
		<author_url/>
		<author_ip/>
		<content>Le beau Danuble bleu</content>
		<replies>
			<comment>
				<published_at>2025-01-23T00:39:56+01:00</published_at>
				<status>1</status>
				<author_name/>
				<author_email>ptc1@free.fr</author_email>
				<author_url/>
				<author_ip>0</author_ip>
				<content>Oui ce fleuve est magnifique</content>
			</comment>
			<comment>
				<published_at>2025-01-23T10:13:32+01:00</published_at>
				<status>0</status>
				<author_name>Emile</author_name>
				<author_email/>
				<author_url>https://www.hell.com</author_url>
				<author_ip/>
				<content>
				On peut faire de très belles balades en péniche sur ce fleuve.
				</content>
			</comment>
		</replies>
	</comment>
</comments>
* */
	public function addComment($aComments, $parent='') {
		global $plxAdmin, $artId;

		foreach($aComments as $xmlComment) {
			$content = trim($xmlComment->content);
			if(!empty($content)) {
				$idx = $plxAdmin->nextIdArtComment($artId);
				$dateComment = trim($xmlComment->published_at);
				$dt = new DateTime($dateComment);
				$mod = (intval($xmlComment->status) == 1) ? '' : '_';
				$filename = $mod . $artId . '.' . $dt->getTimestamp() . '-' . $idx . '.xml';
				$author = htmlspecialchars(trim($xmlComment->author_name));
				if(empty($author)) {
					$author = $plxAdmin->aUsers['001']['name'] . ' (' . L_PROFIL_ADMIN . ')'; // login or name
				}
				$com = array(
					'type'		=> 'normal',
					'author'	=> $author,
					'content'	=> htmlspecialchars($content),
					'parent'	=> $parent,
					'filename'	=> $filename,
				);

				foreach(array(
					'mail'	=> array('author_email', FILTER_VALIDATE_EMAIL, ''),
					'site'	=> array('author_url', FILTER_VALIDATE_URL, ''),
					'ip'	=> array('author_ip', FILTER_VALIDATE_IP, '127.0.0.1'),
				) as $k=>$options) {
					list($entry, $filter, $default) = $options;
					$v = filter_var(trim($xmlComment->$entry, $filter));
					$com[$k] = is_string($v) ? $v : $default;
				}

				if($plxAdmin->addCommentaire($com)); {
					$this->addComment($xmlComment->xpath('replies/comment'), $idx);
				}
			}
		}

	}

	/* ========= hooks ========== */

	public function ThemeEndBody() {
?>
<script src="<?= PLX_PLUGINS . __CLASS__ . '/' . __CLASS__ ?>.js"></script>
<?php
	}
}

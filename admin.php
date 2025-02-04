<?php
/*
 * https://overblog.uservoice.com/knowledgebase/articles/1852513-r%C3%A9diger-une-
 *
 * status = 1 : brouillon
 * status = 2 : publié
 * status = 3 : modéré ?
 * status = 4 : publication différée ( programmée )
 * status = 5 : ????
 * status = 6 : ????
 * status = 7 : article protégé par mot de passe
 * */

# Control du token du formulaire
plxToken::validateFormToken($_POST);

# Control de l'accès à la page en fonction du profil de l'utilisateur connecté
$plxAdmin->checkProfil(PROFIL_ADMIN);

$root = PLX_ROOT . $plxAdmin->aConf['racine_themes'] . $plxAdmin->aConf['style'] . '/' ;
$aArticleTemplates = $plxPlugin->getTemplates($root, $plxPlugin::TEMPLATE_PATTERN);

if(!empty($_FILES['archive'])) {
	$filename = $_FILES['archive']['tmp_name'];
	if(empty($filename)) {
		# Laragon bug
		# See https://stackoverflow.com/questions/73145407/unisharp-laravel-filemanager-cant-upload-on-laragon-server
		# See https://www.php.net/manual/fr/ini.core.php#ini.upload-tmp-dir
		$filename = sys_get_temp_dir() . '/' . $_FILES['archive']['full_path'];
		header('Content-Type: text/plain;charset=utf-8');
		var_dump($_FILES);
	}

	switch(mime_content_type($filename)) {
		case 'application/x-zip-compressed' : # Xampp
		case 'application/zip':
			$zip = new ZipArchive();
			if($zip->open($filename)) {
				$eklablog = simplexml_load_string($zip->getFromIndex(0));
				$zip->close();
			}
			break;
		case 'text/xml':
			$eklablog = simplexml_load_file($filename);
			break;
		default:
			plxMsg::Error('Format de fichier inconnu : ' . mime_content_type($filename) . ' (' . $filename . ')');
			header('Location: plugin.php?p=' . $plugin);
			exit;
	}

	if(!empty($eklablog)) {
		$hostname = filter_input(INPUT_POST, 'hostname', FILTER_SANITIZE_URL);
		if(is_string($hostname)) {
			$plxPlugin->setParam('hostname', $hostname, 'string');
		}

		if(isset($_POST['table']) and in_array($_POST['table'], array('post', 'page'))) {
			include 'inc/table.php';
		} else {
			include 'inc/import.php';
		}
	} else {
		plxMsg::Error('Erreur inconnue');
	}
} else {
?>
<div class="action-bar">
	<h2>Importation sauvegarde Eklablog</h2>
</div>
<?php
	if(!class_exists('ZipArchive')) {
		plxMsg::Error('Librairie Zip manquante');
	} elseif(!function_exists('simplexml_load_file')) {
		plxMsg::Error('Librairie XML manquante');
	} else {
		$maxFileSize = ini_get('upload_max_filesize');
		if(preg_match('#^(\d+)M$#', $maxFileSize, $matches)) {
			$maxFileSize = intval($matches[1]) * 1024 * 1024;
		} elseif(preg_match('#^(\d+)K$#', $maxFileSize, $matches)) {
			$maxFileSize = intval($matches[1]) * 1024;
		}
?>
<div class="in-action-bar">
	<a href="https://lalutiniere.eklablog.com/" target="_blank">La Lutinière</a>
</div>

<div class="<?= $plugin ?>-container">
	<div class="<?= $plugin ?>-infos">
		<p><span title="POST_MAX_SIZE"><?= $plxPlugin->getLang('POST_MAX_SIZE') ?></span><span><?= preg_replace('#^(\d+)(M|G|K)#', '\1 \2o', ini_get('post_max_size')) ?></span></p>
		<p><span title="UPLOAD_MAX_FILESIZE"><?= $plxPlugin->getLang('UPLOAD_MAX_FILESIZE') ?></span><span><?= preg_replace('#^(\d+)(M|G|K)#', '\1 \2o', ini_get('upload_max_filesize')) ?></span></p>
		<p><span title="UPLOAD_FOLDER"><?= $plxPlugin->getLang('UPLOAD_FOLDER') ?></span><span><?= sys_get_temp_dir() ?></span></p>
	</div>
	<form id="<?= $plugin ?>-frm" method="post" enctype="multipart/form-data">
		<?= plxToken::getTokenPostMethod() ?>
		<fieldset>
			<legend>Importer :</legend>
<?php
		foreach(kzEklablog::CHECKBOXES as $k=>$f) {
?>
			<p>
<?php
		$plxPlugin->printCheckbox($f);

			if($k < 2) {
?>
				<label>
					<span>Template</span>
					<?php plxUtils::printSelect('template-' . $f, $aArticleTemplates, $plxPlugin->getParam('template-' . $f)); ?>
				</label>
<?php
			}
?>
			</p>
<?php
		}
?>
			<p>
				<label>
					<span><?= $plxPlugin->getLang('HOSTNAME') ?></span>
					<input type="url" name="hostname" value="<?= $plxPlugin->getParam('hostname') ?>">
				</label>
			</p>
		</fieldset>
		<fieldset>
			<input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxFileSize ?>" />
			<input name="archive" type="file" accept="application/zip, text/xml" placeholder="Sélectionner la sauvegarde Eklablog" required>
			<input type="submit">
			<span>&nbsp;</span>
			<input type="submit" name="table" value="post" title="<?= $plxPlugin->getLang('VIEW_IN_TABLE') ?>">
			<span>&nbsp;</span>
			<input type="submit" name="table" value="page" title="<?= $plxPlugin->getLang('VIEW_IN_TABLE') ?>">
		</fieldset>
	</form>
<?php
		$plugins = array_filter(
			$plxAdmin->plxPlugins->aPlugins,
			function($name) use($plugin) {
				return $name != $plugin;
			},
			ARRAY_FILTER_USE_KEY
		);
		if(!empty($plugins)) {
?>
	<h3><?php $plxPlugin->lang('ACTIVE_PLUGINS'); ?> :</h3>
	<ul>
<?php
			foreach($plugins as $name=>$plxPlugin) {
?>
		<li><?= $name ?> - version: <?= $plxPlugin->getInfo('version') ?> ( <em><?= $plxPlugin->getInfo('date') ?></em> )</li>
<?php
			}
?>
	</ul>
<?php
		}
?>
</div>

<span id="spin-loader" class="loader"></span>
<?php
	}
}

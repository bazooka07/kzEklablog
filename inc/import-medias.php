#!/usr/bin/php
<?php

const KZ_EKLABLOG_SLEEP_TIME = 5;

if(!isset($_SERVER['HTTP_HOST'])) {
	# Exécution en ligne de commande
	define('IS_CLI', true);

	echo 'Welcome !' . PHP_EOL;

	define('IS_NOT_WINDOWS', (!array_key_exists('OS', $_SERVER) or $_SERVER['OS'] != 'Windows_NT'));

	$patterns = array(
		'PLX_ROOT' => IS_NOT_WINDOWS ? '#/plugins(/\w+(?:/inc)?)?$#' : '#\\\\plugins(\\\\\w+(?:/inc)?)?$#',
		'plugin' => IS_NOT_WINDOWS ? '#.*/plugins/(\w+)/.*$#' : '#.*\\\\plugins\\\\(\w+)\\\\.*$#'
	);

	# getcwd() résoud les liens symboliqueq sous Linux. $_SERVER['PWD'] n'existe pas sous Windows_NT
	$pwd = isset($_SERVER['PWD']) ? $_SERVER['PWD'] : getcwd();
	define('PLX_ROOT', preg_replace($patterns['PLX_ROOT'], '', $pwd) . DIRECTORY_SEPARATOR);
	define('PLX_CORE', PLX_ROOT . 'core' .  DIRECTORY_SEPARATOR);
	echo 'PLX_ROOT : ' . PLX_ROOT . PHP_EOL;

	include PLX_ROOT . 'config.php';
	include PLX_CORE . 'lib' . DIRECTORY_SEPARATOR . 'config.php';
	echo 'PLX_CONFIG_PATH : ' . PLX_CONFIG_PATH . PHP_EOL;

	# On verifie que PluXml est installé
	$parameters = path('XMLFILE_PARAMETERS');
	echo 'XMLFILE_PARAMETERS : ' . $parameters . PHP_EOL;
	if(!file_exists($parameters)) {
		header('Location: ' . PLX_ROOT . 'install.php');
		exit;
	} else {
		echo 'File ' . $parameters . ' found !' . PHP_EOL;
 	}

	$confXML = simplexml_load_file($parameters);
	$default_lang = trim($confXML->xpath('parametre[@name="default_lang"]')[0]);
	$mediasRoot = PLX_ROOT . trim($confXML->xpath('parametre[@name="medias"]')[0]);
	$plugin = preg_replace($patterns['plugin'], '$1', __FILE__);
	echo 'Plugin : ' . $plugin . PHP_EOL;

	$configPlugin = PLX_ROOT . PLX_CONFIG_PATH . 'plugins' . DIRECTORY_SEPARATOR . $plugin;
	$filename = $configPlugin . '-img.lst';
	$log_file = $configPlugin . '-img.log';
	echo 'Filename for listing : ' . $filename . PHP_EOL;
	if(!file_exists($filename)) {
		echo $filename . ' file not found' . PHP_EOL;
		exit;
	}

	$images = array();
	foreach(file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line){
		list($target, $url) = explode("\t", $line);
		$images[$target] = $url;
	}

	echo count($images) . ' images' . PHP_EOL;
	if(file_exists($log_file)) {
		unlink($log_file);
	}
	$i = 1;
} else {
	$mediasRoot = PLX_ROOT . $plxAdmin->aConf['medias'];
}

/* ====== Common for cli and http server ========= */

if(!empty($images)) {
	echo 'Beginning...' . PHP_EOL;
	foreach($images as $target=>$url) {
		if(!IS_NOT_WINDOWS) {
			# Here is a gift from Billou
			$target = str_replace('/', DIRECTORY_SEPARATOR, $target);
		}
		$filename = PLX_ROOT . $target;
		if(file_exists($filename)) {
			# L'image existe déjà
			if(IS_CLI) {
				printf('%4d %s SKIPPED' . PHP_EOL, $i, $url);
				$i++;
			}
			continue;
		}

		$path =  PLX_ROOT . pathinfo($target, PATHINFO_DIRNAME);
		if(!is_dir($path)) {
			mkdir($path, 0775, true);
		}

		# https://www.php.net/manual/fr/curl.examples-basic.php
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true, # CURLOPT_FILE
			CURLOPT_FOLLOWLOCATION => true,
		));
		$img = curl_exec($ch);
		$msg = '';
		if($img === false or file_put_contents($filename, $img) === false) {
			if(class_exists('plxMsg')) {
				$msg = $plxPlugin->getLang('DENIED_IMAGE_STORAGE');
				plxMsg::Error($msg);
			} else {
				$msg = ' ERROR';
			};
		}
		curl_close($ch);

		error_log($target . ' : ' . $url . $msg . PHP_EOL, 3, $log_file);
		if(IS_CLI) {
			printf('%4d %s%s' . PHP_EOL, $i, $url, ($img === false) ? ' ❌' : '');
			$i++;

			if(sleep(KZ_EKLABLOG_SLEEP_TIME) > 0) {
				# Arrêt au clavier
				exit;
			}
		}
		unset($img);
	}

	if(IS_CLI) {
		echo 'Done !!!' . PHP_EOL;
	}

}

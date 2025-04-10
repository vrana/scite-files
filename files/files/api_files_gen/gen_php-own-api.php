<?
/**
 * phpapi.php
 * A script for creating api file out of your own php source code.
 * Requires Exuberant CTAGS (freely available at http://ctags.sourceforge.net)
 *
 * What can it do:
 * - create a scite.api file in the root folder of your php source code
 * - create/update SciTE.properties files in every source code sub/folder
 *   containing an api.$(file.patterns.php)=folder\scite.api thus enabling
 *   you to access your own functions from any php file anywhere in the
 *   structure.
 * - combine the SciTE.properties file creation/update with another (optional)
 *   api files, thus ensuring your local scite.api gets appended to the global
 *   list of api files.
 *
 * My regular usage (under cygwin):
 * php -f c:\\bin\\phpapi.php --verbose --create --api='$(SciteDefaultHome)/api/php.api' c:\\myproject
 *
 *
 * @author  Philip Mateescu (philipx@users.sourceforge.net)
 * @date    2004-03-13
 * @version 1.0
 */

/**
 * @return boolean  Trued if haystack ends with needle
 */
function ends_with($haystack, $needle) {
	return (strpos(strrev($haystack), strrev($needle)) === 0 ? true : false);
}


/**
 * @return boolean  Trued if haystack starts with needle
 */
function starts_with($haystack, $needle) {
	return (strstr($haystack, $needle) === $haystack);
}


function usage() {
	global $_VER;
	return <<<USAGE
phpapi.php v$_VER - Creates api files for scite out of PHP files in a given folder.
Requires ctags <http://ctags.sourceforge.net>

Usage: php.exe -f phpapi.php [options] FOLDER

Options:
	--create       - creates SciTE.properties files in all subfolders of FOLDER,
				   if they do not exist. if they do exist, then replaces
				   the api.$(file.patterns.php)=... line in those files.
	--api=apifile  - add apifile to the api.$(file.patterns.php)= line.
	-v             - verbose
Sample usage:
php -f phpapi.php --create --api='$(SciteDefaultHome)/api/php.api' ~/myproject/

USAGE;
}


/**
 * Returns all the dirs below the $folder.
 */
function all_dirs(&$dirlist, $current) {
	global $_PATH_SEP;
	if($dir = opendir($current)) {
		$dirlist[] = $current;
		while(($file = readdir($dir)) !== false) {

			if($file != '..' && $file != '.' && is_dir($current.$file)) {
				//~ echo "$current$file IS dice\n";
				all_dirs($dirlist, $current.$file.$_PATH_SEP);
			//~ } else {
				//~ echo "$current$file NO dice\n";
			}
		}
		closedir($dir);
	}
}

$_VER = '1.0';
$_PATH_SEP = (starts_with(php_uname(), 'Windows') ? '\\' : '');
$_CTAGS_LANGS = '--langmap=php:+.inc --languages=php,javascript ';


$folder = null;
$existing_api=null;
$create=false;
$verbose=false;

foreach($argv as $k=>$v) {
	if($k == 0) continue;
	if(starts_with($v, '--api')) {
		list(,$existing_api) = explode('=', $v);
	} elseif(is_dir($v)) {
		$folder = $v;
		if(!ends_with($_PATH_SEP, $folder)) {
			$folder .= $_PATH_SEP;
		}
	} elseif(preg_match('/[-]{1,2}h/i', $v)) {
		die(usage());
	} elseif($v == '--create') {
		$create = true;
	} elseif(preg_match('/(-v|--verbose)/', $v)) {
		$verbose = true;
	}
}

if(!$folder) die(usage());

# STEP 1: create the tag file
if($verbose) { echo "Creating the TAGS file..."; ob_flush(); }
exec("ctags $_CTAGS_LANGS --recurse --append=no --tag-relative=yes -e -f {$folder}TAGS $folder");
if(!is_file("{$folder}TAGS")) {
	die("ctags did not generate {$folder}TAGS\n");
}
if($verbose) { echo " done\n";  ob_flush(); }


# STEP 2: process the tag file and extract functions
if($verbose) { echo "Processing the TAGS file...";  ob_flush(); }

$lines = file_get_contents("{$folder}TAGS");
$lines = explode("\n", $lines);
$last_file = '';
$tags = array();
foreach($lines as $line) {
	if(starts_with($line, $folder)) {
		#it's the file identification line.
		list($last_file) = explode(',', $line);
		$last_file = substr($last_file, strlen($folder)+1);
	} elseif(strpos($line, "\x7F")) {
		# it's a function definition
		# because of the -e parameter it'll look like:
		# function &blah(q_id, elem_id, display_wait) {\x7Fblah\x1107,2461
		list($def, $tmp) = explode("\x7F", $line);
		list($fname,$tmp) = explode("\x1", $tmp);
		list($line,) = explode(',', $tmp);
		$start=strpos($def,$fname);
		$end=strrpos($def, ')');
		$tags[] = array('f' => substr($def,$start,$end-$start+1),
			'd'=> "$fname was last seen in $last_file:$line");
	}
}

if($verbose) { echo " done\n";  ob_flush(); }


# STEP 3: build the api file
if($verbose) { echo "Building api file... ";  ob_flush(); }
$api_file = "{$folder}scite.api";
$fp = fopen($api_file, 'w');
foreach($tags as $t) {
	fwrite($fp, "{$t['f']} {$t['d']}\n");
}
fclose($fp);
unlink("{$folder}TAGS");
if($verbose) { echo " done\n";  ob_flush(); }
if($verbose) { echo "API file is: $api_file (". filesize("{$folder}scite.api"). " bytes)\n";  ob_flush(); }


# STEP X: begin the SciTE.properties creation odyssey
if($create) {
	$dirlist = array();
	all_dirs($dirlist, $folder);
	//~ print_r($dirlist);
	$p_u = 0; $p_c = 0; # properties updated, properties created
	foreach($dirlist as $dir) {
		$curfile = "{$dir}{$_PATH_SEP}SciTE.properties";
		$api_line = 'api.$(file.patterns.php)='.  ($existing_api ? "$existing_api;" : '') . $api_file;
		if(is_file($curfile)) {
			#file exists. read it and see what can we do with it.
			$lines = file_get_contents($curfile);
			$lines = explode("\n", $lines);
			$found = false;
			foreach($lines as $k=>$line) {
				if(preg_match('/api.\\$\\(file.patterns.php\\)/', $line)) {
					$lines[$k] = $api_line;
					$found = true;
					break;
				}
			}
			if(!$found) {
				$lines[] = $api_line;
			}
			$fp = fopen($curfile, 'w');
			fwrite($fp, implode("\n", $lines));
			fclose($fp);
			$p_u++;
		} else {
			#no such file. create it
			$fp = fopen($curfile, 'w');
			fwrite($fp, $api_line . "\n");
			fclose($fp);
			$p_c++;
		}
	}
	if($verbose) { echo "SciTE.properties: Created $p_c files and updated $p_u files.\n"; ob_flush(); }
}
?>
#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$root=dirname(__DIR__);$config=Config::load($root);$errors=0;
function checkLine(string $label,bool $ok,string $detail=''):void{global $errors;echo($ok?'[OK]   ':'[FAIL] ').str_pad($label,22).($detail!==''?' '.$detail:'')."\n";if(!$ok)$errors++;}
function infoLine(string $label,string $detail=''):void{echo '[INFO] '.str_pad($label,22).($detail!==''?' '.$detail:'')."\n";}
checkLine('PHP >= 8.2',PHP_VERSION_ID>=80200,PHP_VERSION);checkLine('json extension',extension_loaded('json'));checkLine('PDO extension',extension_loaded('pdo'));checkLine('pdo_sqlite extension',extension_loaded('pdo_sqlite'));checkLine('Ashita root',is_dir($config['ashita_root']),$config['ashita_root']);
$gf=null;try{$gf=GearFinderLocator::discover($config);checkLine('GearFinder cache',true,$gf['dir']);checkLine('gear.json',is_readable($gf['gear']),$gf['gear']);checkLine('res.json',is_readable($gf['res']),$gf['res']);echo '       Character: '.$gf['character'].' | Server ID: '.($gf['server_id']??'unknown')."\n";}catch(Throwable $e){checkLine('GearFinder cache',false,$e->getMessage());}
try{$lac=LacLocator::discover($config,$gf['server_id']??null);checkLine('LAC profile dir',true,$lac['profile_dir']);checkLine('NIN.lua',isset($lac['profiles']['NIN']),$lac['profiles']['NIN']??'not found');checkLine('THF.lua',isset($lac['profiles']['THF']),$lac['profiles']['THF']??'not found');checkLine('gcinclude.lua',$lac['gcinclude']!==null,$lac['gcinclude']??'not found');checkLine('gcdisplay.lua',$lac['gcdisplay']!==null,$lac['gcdisplay']??'not found');$bh=$lac['common_dir'].'/gearcenter_bridge.lua';$bs=$lac['profile_dir'].'/gearcenter_augments.json';infoLine('augment bridge helper',is_file($bh)?$bh:'not installed yet');infoLine('augment snapshot',is_file($bs)?$bs:'not generated yet');}catch(Throwable $e){checkLine('LAC profile dir',false,$e->getMessage());}
echo "\nDatabase: {$config['db_path']}\n";exit($errors===0?0:1);

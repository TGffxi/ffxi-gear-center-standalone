#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';$root=dirname(__DIR__);$config=Config::load($root);$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');$s=$pdo->prepare('SELECT id FROM characters WHERE lower(name)=lower(:n) ORDER BY id LIMIT 1');$s->execute([':n'=>$config['character']]);$cid=(int)$s->fetchColumn();if(!$cid){fwrite(STDERR,"Character not imported yet.\n");exit(2);}foreach((new SourceHealth($pdo,$config,$root,$cid))->report() as $r){printf("%-24s %-8s %s%s\n",$r['source'],$r['status'],$r['detail'],$r['age_hours']!==null?' · age '.$r['age_hours'].'h':'');}

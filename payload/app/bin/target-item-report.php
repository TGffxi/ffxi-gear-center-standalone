#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);$config=Config::load($root);$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');
$name=trim(implode(' ',array_slice($argv,1)));if($name==='')throw new InvalidArgumentException('Usage: php bin/target-item-report.php "Item Name"');
$repo=new TargetRepository($pdo);$usage=$repo->usages($name);echo "===== TARGET ITEM REPORT: $name =====\n";if(!$usage){echo "No current global target usage found.\n";exit(0);}foreach($usage as $r){echo "{$r['job']} · {$r['set_name']} / {$r['variant_name']} · source={$r['source_item_name']}\n";echo "  identity={$r['identity_status']} ({$r['identity_match_kind']}) · anomaly={$r['source_anomaly_status']} · route={$r['route_status']} augment={$r['augment_status']} freshness={$r['freshness_status']} chain={$r['chain_status']} strict={$r['strict_status']}\n";echo "  link=".($r['source_link_title']?:'—')." · canonical=".($r['canonical_item_name']?:'—')." · page=".($r['canonical_page_title']?:'—')."\n";echo "  systems={$r['system_keys_json']}\n";if($r['review_reason'])echo "  review={$r['review_reason']}\n";}
$wiki=new BgWikiRepository($pdo);$page=$wiki->byItem($name);if($page){$chain=(new ProgressionChainService($wiki))->build($name,6);echo "\nBG-Wiki: {$page['page_title']} · {$page['canonical_url']}\n";echo 'Upstream paths: '.count($chain['upstream_paths']??[]).' · downstream paths: '.count($chain['downstream_paths']??[]).' · actions: '.count($chain['actions']??[])."\n";}

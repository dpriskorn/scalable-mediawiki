<?php

$wgSitename   = "TestWiki";
$wgScriptPath = "/w";
$wgServer     = "http://localhost:8080";

/* Database */
$wgDBtype   = "mysql";
$wgDBserver = "vitess";
$wgDBport   = 15309;
$wgDBname   = "page";
$wgDBmysql5 = true;

// $wgJobTypeConf = [
//    'default' => [
//       'class' => 'MediaWiki\\JobQueue\\JobQueueRedis',
//       'daemonized' => false,
//       'maxjobs' => 100,
//       'orderID' => 'timestamp',
//       'redisServer' => 'valkey:6379',
//       'redisConfig' => [
//         'password' => 'valkeypass',
//         'persistent' => true,
//       ],
//    ],
// ];
// $wgJobTypeConfiguration = [
//    'default' => [
//       'class' => 'MediaWiki\\JobQueue\\JobQueueRedis',
//       'daemonized' => false,
//       'maxjobs' => 100,
//       'orderID' => 'timestamp',
//       'redisServer' => 'valkey:6379',
//       'redisConfig' => [
//         'password' => 'valkeypass',
//         'persistent' => true,
//       ],
//    ],
// ];

# Watchlist customizations
$wgHooks["RevisionFromEditComplete"][] =
  "MediaWiki\\Watchlist\\WatchlistDirtyMarker::onRevisionFromEditComplete";

$wgSpecialPages["Watchlist"] =
  "MediaWiki\\Specials\\SimpleSpecialWatchlist";

# ExternalStore
$wgExternalStores = [ 'S3' ];
$wgDefaultExternalStore = 'S3://testbucket';
$wgExternalStoreS3Config = [
  'endpoint' => 'http://seaweedfs:8333',
  'accessKey' => 'fakekey',
  'secretKey' => 'fakesecret',
];


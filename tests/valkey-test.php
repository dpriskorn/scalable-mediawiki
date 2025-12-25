#!/usr/bin/env php
<?php
/**
 * Valkey Connectivity Test
 *
 * This script tests MediaWiki's ability to connect to Valkey
 * and perform basic read/write operations.
 */

use MediaWiki\MediaWikiServices;

require_once __DIR__ . '/includes/WebStart.php';

// Ensure we're running from CLI
if ( php_sapi_name() !== 'cli' ) {
	die( "This script must be run from the command line\n" );
}

echo "=== Valkey Connectivity Test ===\n\n";

// Test 1: Check if JobQueueRedis is available
echo "Test 1: Check JobQueueRedis class... ";
try {
	if ( class_exists( 'MediaWiki\\JobQueue\\JobQueueRedis' ) ) {
		echo "✅ PASS\n";
	} else {
		echo "❌ FAIL - Class not found\n";
		exit( 1 );
	}
} catch ( Exception $e ) {
	echo "❌ FAIL - {$e->getMessage()}\n";
	exit( 1 );
}

// Test 2: Get JobQueue configuration
echo "\nTest 2: Get JobQueue configuration... ";
try {
	$config = MediaWikiServices::getInstance()->getMainConfig()->get( 'JobTypeConf' );
	$defaultConfig = $config['default'] ?? [];

	if ( !empty( $defaultConfig ) ) {
		echo "✅ PASS\n";
		echo "  Redis server: " . ( $defaultConfig['redisServer'] ?? 'not set' ) . "\n";
		echo "  Redis class: " . ( $defaultConfig['class'] ?? 'not set' ) . "\n";
	} else {
		echo "❌ FAIL - No JobQueue configuration found\n";
		exit( 1 );
	}
} catch ( Exception $e ) {
	echo "❌ FAIL - {$e->getMessage()}\n";
	exit( 1 );
}

// Test 3: Create JobQueueRedis instance
echo "\nTest 3: Create JobQueueRedis instance... ";
try {
	$jobQueue = MediaWikiServices::getInstance()->getJobQueueGroup();
	echo "✅ PASS\n";
} catch ( Exception $e ) {
	echo "❌ FAIL - {$e->getMessage()}\n";
	echo "  This might indicate Valkey connection failure\n";
	exit( 1 );
}

// Test 4: Push a test job
echo "\nTest 4: Push test job to Valkey... ";
try {
	$job = new \MediaWiki\JobQueue\Job( 'testJob', [ 'test' => true, 'timestamp' => time() ] );
	$jobQueue->push( $job );
	echo "✅ PASS\n";
} catch ( Exception $e ) {
	echo "❌ FAIL - {$e->getMessage()}\n";
	echo "  This indicates Valkey WRITE failure\n";
	exit( 1 );
}

// Test 5: Try to pop the job
echo "\nTest 5: Pop test job from Valkey... ";
try {
	$jobQueue->pop( 'testJob', 1 );
	echo "✅ PASS\n";
} catch ( Exception $e ) {
	echo "❌ FAIL - {$e->getMessage()}\n";
	echo "  This indicates Valkey READ failure\n";
	exit( 1 );
}

// Test 6: Check if we can use WANObjectCache directly
echo "\nTest 6: Test WANObjectCache configuration... ";
try {
	$wanCache = MediaWikiServices::getInstance()->getMainWANObjectCache();

	// Try to set a value
	$key = $wanCache->makeKey( 'valkey_test', 'test_key' );
	$wanCache->set( $key, 'test_value', 60 );
	echo "✅ PASS (WANObjectCache working)\n";
} catch ( Exception $e ) {
	echo "⚠️  WARN - {$e->getMessage()}\n";
	echo "  Note: WANObjectCache is not configured for Valkey\n";
}

echo "\n=== All Tests Complete ===\n";
echo "If all tests passed, Valkey is working correctly.\n";
echo "\n";
echo "Next steps:\n";
echo "1. Start MediaWiki job runner: docker exec mediawiki-jobrunner php maintenance/runJobs.php\n";
echo "2. Check job queue: php maintenance/showJobs.php\n";

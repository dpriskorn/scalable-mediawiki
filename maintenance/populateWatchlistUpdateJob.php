<?php

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Watchlist\WatchlistUpdateJob;

require_once __DIR__ . '/Maintenance.php';

/**
 * One-time script to enqueue the initial WatchlistUpdateJob.
 *
 * This script should be run once after initial deployment to start the
 * 15-minute watchlist update cycle. The WatchlistUpdateJob will
 * automatically requeue itself after each run.
 *
 * @ingroup Maintenance
 */
class PopulateWatchlistUpdateJob extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Enqueue the initial WatchlistUpdateJob to start the 15-minute update cycle' );
	}

	public function execute() {
		$job = new WatchlistUpdateJob();
		MediaWiki\MediaWikiServices::getInstance()->getJobQueueGroup()->lazyPush( $job );

		$this->output( "WatchlistUpdateJob enqueued. It will run every 15 minutes.\n" );
	}
}

$maintClass = PopulateWatchlistUpdateJob::class;
require_once RUN_MAINTENANCE_IF_MAIN;

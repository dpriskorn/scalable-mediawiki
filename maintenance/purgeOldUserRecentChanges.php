<?php

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

require_once __DIR__ . '/Maintenance.php';

/**
 * Maintenance script to purge old entries from user_recentchanges table.
 *
 * This script should be run periodically (e.g., daily via cron) to remove
 * entries older than 30 days, keeping the table size manageable.
 *
 * @ingroup Maintenance
 */
class PurgeOldUserRecentChanges extends Maintenance {

	private const DEFAULT_DAYS = 30;
	private const BATCH_SIZE = 1000;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Purge old entries from user_recentchanges table' );
		$this->addOption( 'days', 'Age in days (default: 30)', false, true );
		$this->addOption( 'dry-run', 'Show what would be deleted without actually deleting', false, false );
		$this->setBatchSize( self::BATCH_SIZE );
	}

	public function execute() {
		$days = (int)$this->getOption( 'days', self::DEFAULT_DAYS );
		$dryRun = $this->hasOption( 'dry-run' );

		if ( $days < 1 ) {
			$this->fatalError( "Days must be at least 1" );
		}

		$services = MediaWikiServices::getInstance();
		$database = $services->getConnectionProvider()->getPrimaryDatabase();

		$cutoff = $database->timestamp( time() - ( $days * 86400 ) );

		$this->output( "Purging user_recentchanges entries older than $days days (before $cutoff)\n" );

		if ( $dryRun ) {
			$count = $this->countOldEntries( $database, $cutoff );
			$this->output( "Would delete approximately $count entries (dry-run mode)\n" );
			return;
		}

		$deleted = 0;
		$ticket = $services->getConnectionProvider()->getEmptyTransactionTicket( __METHOD__ );
		while ( $this->purgeBatch( $database, $cutoff, $deleted ) ) {
			$services->getConnectionProvider()->commitAndWaitForReplication( __METHOD__, $ticket );
			$this->output( "Deleted $deleted entries so far...\n" );
		}

		$this->output( "Purge complete. Total deleted: $deleted entries\n" );
	}

	private function countOldEntries( IReadableDatabase $db, string $cutoff ): int {
		$res = $db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'recentchanges' )
			->join( 'user_recentchanges', 'urc', 'rc_id = urc.rc_id' )
			->where( $db->expr( 'rc_timestamp', '<', $cutoff ) )
			->caller( __METHOD__ )
			->fetchRow();

		return (int)$res->{'COUNT(*)'};
	}

	private function purgeBatch( IDatabase $db, string $cutoff, int &$deleted ): bool {
		$rcIds = $db->newSelectQueryBuilder()
			->select( 'rc_id' )
			->from( 'recentchanges' )
			->join( 'user_recentchanges', 'urc', 'rc_id = urc.rc_id' )
			->where( $db->expr( 'rc_timestamp', '<', $cutoff ) )
			->limit( self::BATCH_SIZE )
			->caller( __METHOD__ )
			->fetchFieldValues();

		if ( empty( $rcIds ) ) {
			return false;
		}

		$db->newDeleteQueryBuilder()
			->deleteFrom( 'user_recentchanges' )
			->where( [ 'rc_id' => $rcIds ] )
			->caller( __METHOD__ )
			->execute();

		$deleted += count( $rcIds );

		return count( $rcIds ) >= self::BATCH_SIZE;
	}
}

$maintClass = PurgeOldUserRecentChanges::class;
require_once RUN_MAINTENANCE_IF_MAIN;

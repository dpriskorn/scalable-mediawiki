<?php

namespace MediaWiki\Watchlist;

use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Logger\LoggerFactory;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Batch job to update user watchlists with recent changes
 *
 * This job runs every 15 minutes and processes dirty pages in batches of 250.
 * For each dirty page:
 *   1. Get watchers from page_watchers table
 *   2. Get recentchanges since last_processed
 *   3. Insert into user_recentchanges for each watcher
 *   4. Update watchlist_last_processed
 *   5. Delete dirty page marker from Valkey
 */
class WatchlistUpdateJob extends Job {

	private const BATCH_SIZE = 250;
	private const RUN_INTERVAL = 900;
	private const MAX_RETRIES = 5;
	private const DIRTY_PAGE_PREFIX = 'dirty_page:';

	private $logger;

	public function __construct( array $params = [] ) {
		parent::__construct( 'watchlistUpdate', $params );
		$this->removeDuplicates = true;
		$this->logger = LoggerFactory::getInstance( 'WatchlistUpdateJob' );
	}

	public function run() {
		try {
			$processedCount = $this->processBatch();

			if ( $processedCount > 0 ) {
				$this->logger->info( "Processed $processedCount watchlist updates" );
			}

			$this->scheduleNextRun();

			return true;

		} catch ( DBQueryError $e ) {
			$this->error = $e->getMessage();
			$this->logger->error( "Database error: " . $e->getMessage() );
			return false;

		} catch ( \Exception $e ) {
			$this->error = $e->getMessage();
			$this->logger->error( "Error: " . $e->getMessage() );
			return false;
		}
	}

	private function processBatch(): int {
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();
		$dbw = $services->getConnectionProvider()->getPrimaryDatabase();

		$lockKey = $dbw->getDomainID() . ':watchlist-update';
		if ( !$dbw->lock( $lockKey, __METHOD__, 0 ) ) {
			$this->logger->debug( "Lock already held, skipping" );
			return 0;
		}

		try {
			$dirtyPages = $this->getDirtyPages( $cache, self::BATCH_SIZE );

			if ( empty( $dirtyPages ) ) {
				$this->logger->debug( "No dirty pages to process" );
				return 0;
			}

			$processedCount = 0;
			$ticket = $services->getConnectionProvider()->getEmptyTransactionTicket( __METHOD__ );

			foreach ( $dirtyPages as $pageId ) {
				$processedCount += $this->processPage( $pageId, $dbw, $cache );

				if ( $processedCount % 50 === 0 ) {
					$services->getConnectionProvider()->commitAndWaitForReplication( __METHOD__, $ticket, [
						'timeout' => 3
					] );
				}
			}

			$services->getConnectionProvider()->commitAndWaitForReplication( __METHOD__, $ticket, [
				'timeout' => 3
			] );

			return $processedCount;

		} finally {
			$dbw->unlock( $lockKey, __METHOD__ );
		}
	}
	
	private function getDirtyPages( $cache, $limit ): array {
		$pageIds = [];
		
		for ( $i = 0; $i < $limit; $i++ ) {
			$scanResult = $cache->scan( self::DIRTY_PAGE_PREFIX . '*', $i );
			
			if ( !$scanResult ) {
				break;
			}
			
			foreach ( $scanResult as $key ) {
				$pageId = (int)substr( $key, strlen( self::DIRTY_PAGE_PREFIX ) );
				$pageIds[] = $pageId;
				
				if ( count( $pageIds ) >= $limit ) {
					break 2;
				}
			}
		}
		
		return $pageIds;
	}
	
	private function processPage( int $pageId, IDatabase $dbw, $cache ): int {
		$lastProcessed = $this->getLastProcessedTimestamp( $pageId, $dbw );
		$lastRcId = $this->getLastProcessedRcId( $pageId, $dbw );
		
		$watchers = $this->getWatchersForPage( $pageId, $dbw );
		
		if ( empty( $watchers ) ) {
			$this->clearDirtyPage( $pageId, $cache );
			return 0;
		}
		
		$recentChanges = $this->getRecentChangesSince( $pageId, $lastProcessed, $lastRcId, $dbw );
		
		if ( empty( $recentChanges ) ) {
			$this->clearDirtyPage( $pageId, $cache );
			return 0;
		}
		
		$insertCount = 0;
		foreach ( $recentChanges as $rcId ) {
			foreach ( $watchers as $userId ) {
				$userDB = $this->getUserDB( $userId );
				
				$userDB->newInsertQueryBuilder()
					->insertInto( 'user_recentchanges' )
					->row( [
						'user_id' => $userId,
						'rc_id' => $rcId,
					] )
					->onDuplicateKeyUpdate()
					->ignore()
					->caller( __METHOD__ )
					->execute();
				
				$insertCount++;
			}
		}
		
		$this->updateLastProcessed( $pageId, $recentChanges, $dbw );
		$this->clearDirtyPage( $pageId, $cache );
		
		return $insertCount;
	}
	
	private function getWatchersForPage( int $pageId, IDatabase $dbw ): array {
		return $dbw->newSelectQueryBuilder()
			->select( 'user_id' )
			->from( 'page_watchers' )
			->where( [ 'page_id' => $pageId ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
	}
	
	private function getRecentChangesSince( int $pageId, ?int $lastProcessed, ?int $lastRcId, IDatabase $dbw ): array {
		$conditions = [ 'rc_cur_id' => $pageId ];
		
		if ( $lastProcessed !== null ) {
			$conditions[] = $dbw->expr( 'rc_timestamp', '>', $dbw->timestamp( $lastProcessed ) );
		}
		
		if ( $lastRcId !== null ) {
			$conditions[] = $dbw->expr( 'rc_id', '>', $lastRcId );
		}
		
		return $dbw->newSelectQueryBuilder()
			->select( 'rc_id' )
			->from( 'recentchanges' )
			->where( $conditions )
			->orderBy( 'rc_timestamp', SORT_ASC )
			->caller( __METHOD__ )
			->fetchFieldValues();
	}
	
	private function getLastProcessedTimestamp( int $pageId, IDatabase $dbw ): ?int {
		$row = $dbw->newSelectQueryBuilder()
			->select( 'last_processed' )
			->from( 'watchlist_last_processed' )
			->where( [ 'page_id' => $pageId ] )
			->caller( __METHOD__ )
			->fetchRow();
		
		return $row ? (int)$row->last_processed : null;
	}
	
	private function getLastProcessedRcId( int $pageId, IDatabase $dbw ): ?int {
		$row = $dbw->newSelectQueryBuilder()
			->select( 'last_rc_id' )
			->from( 'watchlist_last_processed' )
			->where( [ 'page_id' => $pageId ] )
			->caller( __METHOD__ )
			->fetchRow();
		
		return $row ? (int)$row->last_rc_id : null;
	}
	
	private function updateLastProcessed( int $pageId, array $recentChanges, IDatabase $dbw ): void {
		$now = ConvertibleTimestamp::time();
		$lastRcId = max( $recentChanges );
		
		$dbw->newReplaceQueryBuilder()
			->replaceInto( 'watchlist_last_processed' )
			->row( [
				'page_id' => $pageId,
				'last_processed' => $now,
				'last_rc_id' => $lastRcId,
			] )
			->uniqueIndexFields( [ 'page_id' ] )
			->caller( __METHOD__ )
			->execute();
	}
	
	private function clearDirtyPage( int $pageId, $cache ): void {
		$cache->delete( $cache->makeGlobalKey( self::DIRTY_PAGE_PREFIX . $pageId ) );
	}
	
	private function getUserDB( int $userId ): IDatabase {
		$shard = $userId % 4;
		$shardName = "user_keyspace/$shard";
		
		return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase( $shardName );
	}
	
	private function scheduleNextRun(): void {
		$now = ConvertibleTimestamp::time();
		$nextRun = $now + self::RUN_INTERVAL;
		
		$job = new self( [
			'jobReleaseTimestamp' => wfTimestamp( TS_MW, $nextRun ),
		] );
		
		MediaWikiServices::getInstance()->getJobQueueGroup()->lazyPush( $job );

		$this->logger->info( "Next run scheduled for " . wfTimestamp( TS_MW, $nextRun ) );
	}
	
	/**
	 * @inheritDoc
	 */
	public function allowRetries() {
		$retries = $this->params['retries'] ?? 0;
		return $retries < self::MAX_RETRIES;
	}
	
	/**
	 * @inheritDoc
	 */
	public function getDeduplicationInfo() {
		$info = parent::getDeduplicationInfo();
		unset( $info['params']['jobReleaseTimestamp'] );
		unset( $info['params']['retries'] );
		return $info;
	}
	
	/**
	 * @inheritDoc
	 */
	public function workItemCount() {
		return 1;
	}
}

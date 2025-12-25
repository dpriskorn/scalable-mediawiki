<?php

namespace MediaWiki\Watchlist;

use Wikimedia\Rdbms\ReadOnlyMode;
use Wikimedia\Rdbms\LBFactory;
use Wikimedia\Rdbms\IDatabase;
use MediaWiki\Page\PageIdentity;
use MediaWiki\User\UserIdentity;

/**
 * Minimal KISS implementation for watchlist using page_watchers and user_recentchanges tables
 */
class MinimalWatchedItemStore implements WatchedItemStoreInterface {

	private ReadOnlyMode $readOnlyMode;
	private LBFactory $lbFactory;

	public function __construct(
		ReadOnlyMode $readOnlyMode,
		LBFactory $lbFactory
	) {
		$this->readOnlyMode = $readOnlyMode;
		$this->lbFactory = $lbFactory;
	}

	// ========== Critical Methods ==========

	public function addWatch( UserIdentity $user, $target, ?string $expiry = null ): bool {
		if ( !$user->isRegistered() || $this->readOnlyMode->isReadOnly() ) {
			return false;
		}

		$pageId = $this->getPageId( $target );
		if ( !$pageId ) {
			return false;
		}

		$dbw = $this->lbFactory->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'page_watchers' )
			->ignore()
			->row( [
				'page_id' => $pageId,
				'user_id' => $user->getId()
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows() > 0;
	}

	public function removeWatch( UserIdentity $user, $target ): bool {
		if ( !$user->isRegistered() ) {
			return false;
		}

		$pageId = $this->getPageId( $target );
		if ( !$pageId ) {
			return false;
		}

		$dbw = $this->lbFactory->getPrimaryDatabase();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'page_watchers' )
			->where( [
				'page_id' => $pageId,
				'user_id' => $user->getId()
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows() > 0;
	}

	public function isWatched( UserIdentity $user, $target ): bool {
		if ( !$user->isRegistered() ) {
			return false;
		}

		$pageId = $this->getPageId( $target );
		if ( !$pageId ) {
			return false;
		}

		$dbr = $this->lbFactory->getReplicaDatabase();
		return (bool)$dbr->newSelectQueryBuilder()
			->select( '1' )
			->from( 'page_watchers' )
			->where( [
				'page_id' => $pageId,
				'user_id' => $user->getId()
			] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Get all watchers for a page - used by WatchlistUpdateJob
	 */
	public function getWatchersForPage( int $pageId ): array {
		$dbr = $this->lbFactory->getReplicaDatabase();
		$result = $dbr->newSelectQueryBuilder()
			->select( 'user_id' )
			->from( 'page_watchers' )
			->where( [ 'page_id' => $pageId ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$userIds = [];
		foreach ( $result as $row ) {
			$userIds[] = (int)$row->user_id;
		}
		return $userIds;
	}

	/**
	 * Get primary database connection
	 * Used by MinimalWatchlistManager for bulk operations
	 * @return IDatabase
	 */
	public function getPrimaryDB(): IDatabase {
		return $this->lbFactory->getPrimaryDatabase();
	}

	// ========== Stub Implementations ==========

	public function countWatchedItems( UserIdentity $user ): int {
		return 0;
	}

	public function countWatchers( $target ): int {
		return 0;
	}

	public function countVisitingWatchers( $target, $threshold ): int {
		return 0;
	}

	public function countWatchersMultiple( array $targets, array $options = [] ): array {
		$result = [];
		foreach ( $targets as $target ) {
			$result[$target->getNamespace()][$target->getDBkey()] = 0;
		}
		return $result;
	}

	public function countVisitingWatchersMultiple(
		array $targetsWithVisitThresholds,
		$minimumWatchers = null
	): array {
		$result = [];
		foreach ( $targetsWithVisitThresholds as $target ) {
			$result[$target->getNamespace()][$target->getDBkey()] = 0;
		}
		return $result;
	}

	public function getWatchedItem( UserIdentity $user, $target ) {
		return false;
	}

	public function loadWatchedItem( UserIdentity $user, $target ) {
		return false;
	}

	public function loadWatchedItemsBatch( UserIdentity $user, array $targets ): array {
		return [];
	}

	public function getWatchedItemsForUser( UserIdentity $user, array $options = [] ): array {
		return [];
	}

	public function getNotificationTimestampsBatch( UserIdentity $user, array $targets ): array {
		$result = [];
		foreach ( $targets as $target ) {
			$result[$target->getNamespace()][$target->getDBkey()] = null;
		}
		return $result;
	}

	public function setNotificationTimestampsForUser(
		UserIdentity $user,
		$timestamp,
		array $targets = []
	): bool {
		return true;
	}

	public function resetAllNotificationTimestampsForUser( UserIdentity $user, $timestamp = null ): void {
	}

	public function updateNotificationTimestamp(
		UserIdentity $editor, $target, $timestamp ): array {
		return [];
	}

	public function resetNotificationTimestamp( UserIdentity $user, $title, $force = '', $oldid = 0 ): bool {
		return true;
	}

	public function countUnreadNotifications( UserIdentity $user, $unreadLimit = null ) {
		return 0;
	}

	public function clearUserWatchedItems( UserIdentity $user ): bool {
		return true;
	}

	public function mustClearWatchedItemsUsingJobQueue( UserIdentity $user ): bool {
		return false;
	}

	public function clearUserWatchedItemsUsingJobQueue( UserIdentity $user ): void {
	}

	public function maybeEnqueueWatchlistExpiryJob(): void {
	}

	public function addWatchBatchForUser( UserIdentity $user, array $targets, ?string $expiry = null ): bool {
		return false;
	}

	public function duplicateAllAssociatedEntries( $oldTarget, $newTarget ): void {
	}

	public function duplicateEntry( $oldTarget, $newTarget ): void {
	}

	public function removeWatchBatchForUser( UserIdentity $user, array $targets ): bool {
		return false;
	}

	public function getLatestNotificationTimestamp( $timestamp, UserIdentity $user, $target ) {
		return null;
	}

	public function isTempWatched( UserIdentity $user, $target ): bool {
		return false;
	}

	public function countExpired(): int {
		return 0;
	}

	public function removeExpired( int $limit, bool $deleteOrphans = false ): void {
	}

	// ========== Helper Methods ==========

	private function getPageId( $target ): ?int {
		if ( $target instanceof PageIdentity && $target->getId() > 0 ) {
			return $target->getId();
		}

		$dbr = $this->lbFactory->getReplicaDatabase();
		return $dbr->newSelectQueryBuilder()
			->select( 'page_id' )
			->from( 'page' )
			->where( [
				'page_namespace' => $target->getNamespace(),
				'page_title' => $target->getDBkey()
			] )
			->caller( __METHOD__ )
			->fetchField();
	}
}

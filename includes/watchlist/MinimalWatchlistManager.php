<?php

namespace MediaWiki\Watchlist;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;

/**
 * Simple watchlist manager for new architecture
 * Uses page_watchers table with batch job updates
 */
class MinimalWatchlistManager {

	private MinimalWatchedItemStore $store;
	private LoggerInterface $logger;

	public function __construct(
		MinimalWatchedItemStore $store
	) {
		$this->store = $store;
		$this->logger = LoggerFactory::getInstance( 'WatchlistManager' );
	}

	/**
	 * Add page to user's watchlist
	 * @param UserIdentity $user
	 * @param PageIdentity $target
	 * @return bool
	 */
	public function addWatch( UserIdentity $user, PageIdentity $target ): bool {
		if ( !$user->isRegistered() ) {
			$this->logger->debug( "User {$user->getName()} is not registered, cannot add to watchlist" );
			return false;
		}

		return $this->store->addWatch( $user, $target );
	}

	/**
	 * Remove page from user's watchlist
	 * @param UserIdentity $user
	 * @param PageIdentity $target
	 * @return bool
	 */
	public function removeWatch( UserIdentity $user, PageIdentity $target ): bool {
		if ( !$user->isRegistered() ) {
			$this->logger->debug( "User {$user->getName()} is not registered, cannot remove from watchlist" );
			return false;
		}

		return $this->store->removeWatch( $user, $target );
	}

	/**
	 * Check if user is watching a page
	 * @param UserIdentity $user
	 * @param PageIdentity $target
	 * @return bool
	 */
	public function isWatched( UserIdentity $user, PageIdentity $target ): bool {
		if ( !$user->isRegistered() ) {
			return false;
		}

		return $this->store->isWatched( $user, $target );
	}

	/**
	 * Clear all watches for a user
	 * @param UserIdentity $user
	 * @return int Number of items cleared
	 */
	public function clearUserWatchlist( UserIdentity $user ): int {
		if ( !$user->isRegistered() ) {
			return 0;
		}

		$dbw = $this->store->getPrimaryDB();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'page_watchers' )
			->where( [
				'user_id' => $user->getId()
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows();
	}

	/**
	 * Get all pages watched by a user
	 * @param UserIdentity $user
	 * @param int|null $limit
	 * @return int[] Array of page IDs
	 */
	public function getWatchedItemsForUser(
		UserIdentity $user,
		?int $limit = null
	): array {
		if ( !$user->isRegistered() ) {
			return [];
		}

		return $this->store->getWatchedItemsForUser( $user, $limit );
	}

	/**
	 * Get count of pages watched by a user
	 * @param UserIdentity $user
	 * @return int
	 */
	public function getWatchedItemCountForUser( UserIdentity $user ): int {
		if ( !$user->isRegistered() ) {
			return 0;
		}

		return count( $this->store->getWatchedItemsForUser( $user ) );
	}

	/**
	 * Check if user can watch this page
	 * @param Authority $performer
	 * @return PermissionStatus
	 */
	public function checkWatchPermission( Authority $performer ): PermissionStatus {
		$status = PermissionStatus::newEmpty();
		if ( !$performer->isRegistered() ) {
			$status->fatal( 'watchlistanontext' );
		}

		return $status;
	}
}

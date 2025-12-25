<?php

namespace MediaWiki\Watchlist;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Hook\RevisionFromEditCompleteHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use MediaWiki\Page\Hook\RevisionFromEditCompleteHook as CoreHook;

/**
 * Hook handler to mark pages as dirty for watchlist batch updates
 */
class WatchlistDirtyMarker implements CoreHook {

	/** @var int */
	private const DIRTY_TTL = 900; // 15 minutes

	/**
	 * @param WikiPage $wikiPage
	 * @param RevisionRecord $newRevisionRecord
	 * @param int|bool $originalRevId
	 * @param UserIdentity $user
	 * @param string|array &$tags
	 * @return bool|void
	 */
	public function onRevisionFromEditComplete(
		$wikiPage,
		$newRevisionRecord,
		$originalRevId,
		$user,
		&$tags
	) {
		// Mark page as dirty for watchlist batch updates
		DeferredUpdates::addCallableUpdate( function () use ( $wikiPage ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
			$pageId = $wikiPage->getId();

			// Mark page as dirty for 15 minutes
			$cache->set(
				$cache->makeKey( 'dirty_page', $pageId ),
				1,
				self::DIRTY_TTL
			);
		} );

		return true;
	}
}

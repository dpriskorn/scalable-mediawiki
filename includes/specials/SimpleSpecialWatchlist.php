<?php

namespace MediaWiki\Specials;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IReadableDatabase;

use MediaWiki\MediaWikiServices as MW;

/**
 * Simple watchlist special page using user_recentchanges table
 * Provides instant results from materialized watchlist data
 */
class SimpleSpecialWatchlist extends SpecialPage {

	public function __construct() {
		parent::__construct( 'Watchlist', 'viewmywatchlist' );
	}

	/**
	 * Execute the special page
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();
		$output = $this->getOutput();

		$user = $this->getUser();

		if ( !$user->isRegistered() ) {
			$output->addWikiMsg( 'watchlistanontext' );
			return;
		}

		// Get limit and offset from request
		$request = $this->getRequest();
		$limit = $request->getInt( 'limit', 50 );
		$offset = $request->getInt( 'offset', 0 );

		// Validate limit
		if ( $limit < 1 ) {
			$limit = 50;
		} elseif ( $limit > 1000 ) {
			$limit = 1000;
		}

		// Fetch watchlist entries
		$dbr = $this->getDB();
		$entries = $this->getWatchlistEntries( $dbr, $user->getId(), $limit, $offset );

		if ( empty( $entries ) ) {
			$output->addWikiMsg( 'nowatchlist' );
			return;
		}

		// Build output
		$this->buildWatchlistOutput( $output, $entries, $limit, $offset, $user->getId() );
	}

	/**
	 * Get watchlist entries from user_recentchanges table
	 * @param IReadableDatabase $dbr
	 * @param int $userId
	 * @param int $limit
	 * @param int $offset
	 * @return array[]
	 */
	private function getWatchlistEntries( IReadableDatabase $dbr, int $userId, int $limit, int $offset ): array {
		$query = $dbr->newSelectQueryBuilder()
			->select( [
				'rc_id',
				'rc_timestamp',
				'rc_namespace',
				'rc_title',
				'rc_cur_id',
				'rc_comment',
				'rc_user',
				'rc_user_text',
				'rc_minor',
				'rc_type',
				'rc_deleted'
			] )
			->from( 'user_recentchanges' )
			->join( 'recentchanges', 'rc', 'urc.rc_id = rc.rc_id' )
			->where( [ 'urc.user_id' => $userId ] )
			->orderBy( 'rc.rc_timestamp', 'DESC' )
			->limit( $limit )
			->offset( $offset )
			->caller( __METHOD__ );

		$result = $query->fetchResultSet();
		$entries = [];
		foreach ( $result as $row ) {
			$entries[] = (array)$row;
		}
		return $entries;
	}

	/**
	 * Build watchlist output HTML
	 * @param \MediaWiki\Output\OutputPage $output
	 * @param array $entries
	 * @param int $limit
	 * @param int $offset
	 */
	private function buildWatchlistOutput( $output, array $entries, int $limit, int $offset ): void {
		$output->addModuleStyles( 'mediawiki.special' );
		$output->setPageTitle( $output->msg( 'watchlist' )->text() );

		$html = '<ul class="special">';

		foreach ( $entries as $entry ) {
			$title = Title::makeTitle( $entry['rc_namespace'], $entry['rc_title'] );
			if ( !$title ) {
				continue;
			}

			$link = $title->getFullURL();
			$timestamp = wfTimestamp( TS_ISO_8601, $entry['rc_timestamp'] );
			$comment = $entry['rc_comment'] ?? '';

			$html .= '<li>';
			$html .= '<a href="' . htmlspecialchars( $link ) . '">' . htmlspecialchars( $title->getPrefixedText() ) . '</a>';
			$html .= ' - ';
			$html .= htmlspecialchars( $timestamp );
			if ( $comment ) {
				$html .= ' <em>' . htmlspecialchars( $comment ) . '</em>';
			}
			$html .= '</li>';
		}

		$html .= '</ul>';

		$output->addHTML( $html );
	}

	/**
	 * Build pagination controls
	 * @param \MediaWiki\Output\OutputPage $output
	 * @param int $limit
	 * @param int $offset
	 */
	private function buildPagination( $output, int $limit, int $offset ): void {
		if ( $offset >= $limit ) {
			$prevUrl = $this->getPageTitle()->getFullURL( [ 'offset' => $offset - $limit ] );
			$output->addHTML( '<p>' . $output->msg( 'prevn', $limit )->text() . '</p>' );
		}
		$output->addHTML( '<p>' . $output->msg( 'shownav', $limit )->text() . '</p>' );
	}

	/**
	 * Get database connection
	 * @return IReadableDatabase
	 */
	private function getDB(): IReadableDatabase {
		return MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->getReplicaDatabase();
	}
}

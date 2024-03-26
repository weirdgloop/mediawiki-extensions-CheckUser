<?php

namespace MediaWiki\CheckUser\CheckUser\Pagers;

use Html;
use IContextSource;
use Linker;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\CheckUser\CheckUser\SpecialCheckUserLog;
use MediaWiki\CheckUser\CheckUserLogCommentStore;
use MediaWiki\CheckUser\Services\CheckUserLogService;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentityValue;
use RangeChronologicalPager;
use SpecialPage;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\IResultWrapper;

class CheckUserLogPager extends RangeChronologicalPager {

	/** @var array The options provided to the CheckUserLog form. May be empty. */
	private array $opts;

	private LinkBatchFactory $linkBatchFactory;
	private CheckUserLogCommentStore $checkUserLogCommentStore;
	private CommentFormatter $commentFormatter;
	private CheckUserLogService $checkUserLogService;
	private UserFactory $userFactory;
	private ActorStore $actorStore;

	/**
	 * @param IContextSource $context
	 * @param array $opts A array of keys that can include 'target', 'initiator', 'start', 'end'
	 * 		'year' and 'month'. Target should be a user, IP address or IP range. Initiator should be a user.
	 * 		Start and end should be timestamps. Year and month are converted to end but ignored if end is
	 * 		provided.
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param CheckUserLogCommentStore $checkUserLogCommentStore
	 * @param CommentFormatter $commentFormatter
	 * @param CheckUserLogService $checkUserLogService
	 * @param UserFactory $userFactory
	 * @param ActorStore $actorStore
	 */
	public function __construct(
		IContextSource $context,
		array $opts,
		LinkBatchFactory $linkBatchFactory,
		CheckUserLogCommentStore $checkUserLogCommentStore,
		CommentFormatter $commentFormatter,
		CheckUserLogService $checkUserLogService,
		UserFactory $userFactory,
		ActorStore $actorStore
	) {
		parent::__construct( $context );
		$this->linkBatchFactory = $linkBatchFactory;
		$this->checkUserLogCommentStore = $checkUserLogCommentStore;
		$this->commentFormatter = $commentFormatter;
		$this->checkUserLogService = $checkUserLogService;
		$this->userFactory = $userFactory;
		$this->actorStore = $actorStore;
		$this->opts = $opts;

		// Date filtering: use timestamp if available - From SpecialContributions.php
		$startTimestamp = '';
		$endTimestamp = '';
		if ( isset( $opts['start'] ) && $opts['start'] ) {
			$startTimestamp = $opts['start'] . ' 00:00:00';
		}
		if ( isset( $opts['end'] ) && $opts['end'] ) {
			$endTimestamp = $opts['end'] . ' 23:59:59';
		}
		$this->getDateRangeCond( $startTimestamp, $endTimestamp );
	}

	/**
	 * If appropriate, generate a link that wraps around the provided date, time, or
	 * date and time. The date and time is escaped by this function.
	 *
	 * @param string $dateAndTime The string representation of the date, time or date and time.
	 * @param array|\stdClass $row The current row being formatted in formatRow().
	 * @return string|null The date and time wrapped in a link if appropriate.
	 */
	protected function generateTimestampLink( string $dateAndTime, $row ) {
		$highlight = $this->getRequest()->getVal( 'highlight' );
		// Add appropriate classes to the date and time.
		$dateAndTimeClasses = [];
		if (
			$highlight === strval( $row->cul_timestamp )
		) {
			$dateAndTimeClasses[] = 'mw-checkuser-log-highlight-entry';
		}
		// If the CU log search has a specified target or initiator then
		// provide a link to this log entry without the current filtering
		// for these values.
		if (
			$this->opts['target'] ||
			$this->opts['initiator']
		) {
			return $this->getLinkRenderer()->makeLink(
				SpecialPage::getTitleFor( 'CheckUserLog' ),
				$dateAndTime,
				[
					'class' => $dateAndTimeClasses,
				],
				[
					'offset' => $row->cul_timestamp + 3600,
					'highlight' => $row->cul_timestamp,
				]
			);
		} elseif ( $dateAndTimeClasses ) {
			return Html::element(
				'span',
				[ 'class' => $dateAndTimeClasses ],
				$dateAndTime
			);
		} else {
			return htmlspecialchars( $dateAndTime );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function formatRow( $row ) {
		if ( $row->actor_user ) {
			$performerHidden = $this->userFactory->newFromUserIdentity(
				UserIdentityValue::newRegistered( $row->actor_user, $row->actor_name )
			)->isHidden();
		} else {
			$performerHidden = $this->userFactory->newFromActorId( $row->actor_id )->isHidden();
		}
		if ( $performerHidden && !$this->getAuthority()->isAllowed( 'hideuser' ) ) {
			// Performer of the check is hidden and the logged in user does not have
			//  right to see hidden users.
			$user = Html::element(
				'span',
				[ 'class' => 'history-deleted' ],
				$this->msg( 'rev-deleted-user' )->text()
			);
		} else {
			$user = Linker::userLink( $row->actor_user, $row->actor_name );
			if ( $performerHidden ) {
				// Performer is hidden, but current user has rights to see it.
				// Mark the username has hidden by wrapping it in a history-deleted span.
				$user = Html::rawElement(
					'span',
					[ 'class' => 'history-deleted' ],
					$user
				);
			}
			$user .= $this->msg( 'word-separator' )->escaped()
				. Html::rawElement( 'span', [ 'classes' => 'mw-usertoollinks' ],
					$this->msg( 'parentheses' )->rawParams( $this->getLinkRenderer()->makeLink(
						SpecialPage::getTitleFor( 'CheckUserLog' ),
						$this->msg( 'checkuser-log-checks-by' )->text(),
						[],
						[
							'cuInitiator' => $row->actor_name,
						]
					) )->escaped()
				);
		}

		$targetHidden = $this->userFactory->newFromUserIdentity(
			new UserIdentityValue( $row->cul_target_id, $row->cul_target_text )
		)->isHidden();
		if ( $targetHidden && !$this->getAuthority()->isAllowed( 'hideuser' ) ) {
			// Target of the check is hidden and the logged in user does not have
			//  right to see hidden users.
			$target = Html::element(
				'span',
				[ 'class' => 'history-deleted' ],
				$this->msg( 'rev-deleted-user' )->text()
			);
		} else {
			$target = Linker::userLink( $row->cul_target_id, $row->cul_target_text );
			if ( $targetHidden ) {
				// Target is hidden, but current user has rights to see it.
				// Mark the username has hidden by wrapping it in a history-deleted span.
				$target = Html::rawElement(
					'span',
					[ 'class' => 'history-deleted' ],
					$target
				);
			}
			$target .= Linker::userToolLinks( $row->cul_target_id, trim( $row->cul_target_text ) );
		}

		$lang = $this->getLanguage();
		$contextUser = $this->getUser();
		// Give grep a chance to find the usages:
		// checkuser-log-entry-userips, checkuser-log-entry-ipedits,
		// checkuser-log-entry-ipusers, checkuser-log-entry-ipedits-xff
		// checkuser-log-entry-ipusers-xff, checkuser-log-entry-useredits
		$rowContent = $this->msg( 'checkuser-log-entry-' . $row->cul_type )
			->rawParams(
				$user,
				$target,
				$this->generateTimestampLink(
					$lang->userTimeAndDate(
						wfTimestamp( TS_MW, $row->cul_timestamp ), $contextUser
					),
					$row
				),
				$this->generateTimestampLink(
					$lang->userDate( wfTimestamp( TS_MW, $row->cul_timestamp ), $contextUser ),
					$row
				),
				$this->generateTimestampLink(
					$lang->userTime( wfTimestamp( TS_MW, $row->cul_timestamp ), $contextUser ),
					$row
				)
			)->parse();
		$rowContent .= $this->commentFormatter->formatBlock(
			CheckUserLogCommentStore::getStore()->getComment( 'cul_reason', $row )->text
		);

		$attribs = [
			'data-mw-culogid' => $row->cul_id,
		];
		return Html::rawElement( 'li', $attribs, $rowContent ) . "\n";
	}

	/**
	 * @return string
	 */
	public function getStartBody() {
		if ( $this->getNumRows() ) {
			return '<ul>';
		}

		return '';
	}

	/**
	 * @return string
	 */
	public function getEndBody() {
		if ( $this->getNumRows() ) {
			return '</ul>';
		}

		return '';
	}

	/**
	 * @return string
	 */
	public function getEmptyBody() {
		return '<p>' . $this->msg( 'checkuser-empty' )->escaped() . '</p>';
	}

	/** @inheritDoc */
	public function getQueryInfo() {
		$queryInfo = [
			'tables' => [ 'cu_log', 'cu_log_actor' => 'actor' ],
			'fields' => $this->selectFields(),
			'conds' => [],
			'join_conds' => [ 'cu_log_actor' => [ 'JOIN', [ 'actor_id = cul_actor' ] ] ],
			'options' => [],
		];

		$reasonCommentQuery = $this->checkUserLogCommentStore->getJoin( 'cul_reason' );
		$queryInfo['tables'] += $reasonCommentQuery['tables'];
		$queryInfo['fields'] += $reasonCommentQuery['fields'];
		$queryInfo['join_conds'] += $reasonCommentQuery['joins'];

		if ( $this->opts['target'] !== '' ) {
			$queryInfo['conds'] = array_merge(
				$queryInfo['conds'],
				$this->getTargetSearchConds( $this->opts['target'] ) ?? []
			);
			if ( IPUtils::isIPAddress( $this->opts['target'] ) ) {
				// Use the cul_target_hex index on the query if the target is an IP
				// otherwise the query could take a long time (T342639)
				$queryInfo['options']['USE INDEX'] = [ 'cu_log' => 'cul_target_hex' ];
			}
		}

		if ( $this->opts['initiator'] !== '' ) {
			$queryInfo['conds'] = array_merge(
				$queryInfo['conds'],
				$this->getPerformerSearchConds( $this->opts['initiator'] ) ?? []
			);
		}

		if ( $this->opts['reason'] !== '' ) {
			$reasonSearchQuery = $this->getQueryInfoForReasonSearch( $this->opts['reason'] );
			$queryInfo['tables'] += $reasonSearchQuery['tables'];
			$queryInfo['fields'] += $reasonSearchQuery['fields'];
			$queryInfo['conds'] = array_merge( $queryInfo['conds'], $reasonSearchQuery['conds'] );
			$queryInfo['join_conds'] += $reasonSearchQuery['join_conds'];
		}

		return $queryInfo;
	}

	/**
	 * @inheritDoc
	 */
	public function getIndexField() {
		return 'cul_timestamp';
	}

	/**
	 * Gets the fields for a select on the cu_log table.
	 *
	 * @return string[]
	 */
	public function selectFields(): array {
		return [
			'cul_id', 'cul_timestamp', 'cul_type', 'cul_target_id',
			'cul_target_text', 'actor_name', 'actor_user', 'actor_id'
		];
	}

	/**
	 * Do a batch query for links' existence and add it to LinkCache
	 *
	 * @param IResultWrapper $result
	 */
	protected function preprocessResults( $result ) {
		if ( $this->getNumRows() === 0 ) {
			return;
		}

		$lb = $this->linkBatchFactory->newLinkBatch();
		$lb->setCaller( __METHOD__ );
		foreach ( $result as $row ) {
			// Performer
			$lb->add( NS_USER, $row->actor_name );

			if ( $row->cul_type == 'userips' || $row->cul_type == 'useredits' ) {
				$lb->add( NS_USER, $row->cul_target_text );
				$lb->add( NS_USER_TALK, $row->cul_target_text );
			}
		}
		$lb->execute();
		$result->seek( 0 );
	}

	/**
	 * Get DB search conditions for the initiator
	 *
	 * @param string $initiator the username of the initiator.
	 * @return array|null array if valid target, null if invalid
	 */
	private function getPerformerSearchConds( string $initiator ): ?array {
		$initiatorId = $this->actorStore->findActorIdByName( $initiator, $this->mDb ) ?? false;
		if ( $initiatorId !== false ) {
			return [ 'cul_actor' => $initiatorId ];
		}
		return null;
	}

	/**
	 * Get DB search conditions according to the CU target given.
	 *
	 * @param string $target the username, IP address or range of the target.
	 * @return array|null array if valid target, null if invalid target given
	 */
	public static function getTargetSearchConds( string $target ): ?array {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->getReplicaDatabase();
		$result = SpecialCheckUserLog::verifyTarget( $target );
		if ( is_array( $result ) ) {
			switch ( count( $result ) ) {
				case 1:
					return [
						'cul_target_hex = ' . $dbr->addQuotes( $result[0] ) . ' OR ' .
						'(cul_range_end >= ' . $dbr->addQuotes( $result[0] ) . ' AND ' .
						'cul_range_start <= ' . $dbr->addQuotes( $result[0] ) . ')'
					];
				case 2:
					return [
						'(cul_target_hex >= ' . $dbr->addQuotes( $result[0] ) . ' AND ' .
						'cul_target_hex <= ' . $dbr->addQuotes( $result[1] ) . ') OR ' .
						'(cul_range_end >= ' . $dbr->addQuotes( $result[0] ) . ' AND ' .
						'cul_range_start <= ' . $dbr->addQuotes( $result[1] ) . ')'
					];
			}
		} elseif ( is_int( $result ) ) {
			return [
				'cul_type' => [ 'userips', 'useredits', 'investigate' ],
				'cul_target_id' => $result,
			];
		}
		return null;
	}

	/**
	 * Get the query info for a reason search
	 *
	 * @param string $reason The reason to search for
	 * @return string[][] With three keys to arrays for tables, fields and joins.
	 */
	public function getQueryInfoForReasonSearch( string $reason ): array {
		$queryInfo = [ 'tables' => [], 'fields' => [], 'join_conds' => [] ];
		$plaintextReason = $this->checkUserLogService->getPlaintextReason( $reason );

		if ( $plaintextReason == '' ) {
			return $queryInfo;
		}

		$plaintextReasonCommentQuery = $this->checkUserLogCommentStore->getJoin( 'cul_reason_plaintext' );
		$queryInfo['tables'] += $plaintextReasonCommentQuery['tables'];
		$queryInfo['fields'] += $plaintextReasonCommentQuery['fields'];
		$queryInfo['join_conds'] += $plaintextReasonCommentQuery['joins'];

		$queryInfo['conds'] = [ 'comment_cul_reason_plaintext.comment_text' => $plaintextReason ];

		return $queryInfo;
	}
}

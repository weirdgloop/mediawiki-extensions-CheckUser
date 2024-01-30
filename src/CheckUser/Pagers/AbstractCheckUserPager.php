<?php

namespace MediaWiki\CheckUser\CheckUser\Pagers;

use ExtensionRegistry;
use HtmlArmor;
use IContextSource;
use LogicException;
use MediaWiki\Block\AbstractBlock;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\CheckUser\CheckUser\CheckUserPagerNavigationBuilder;
use MediaWiki\CheckUser\CheckUser\Widgets\HTMLFieldsetCheckUser;
use MediaWiki\CheckUser\CheckUserQueryInterface;
use MediaWiki\CheckUser\Services\CheckUserLogService;
use MediaWiki\CheckUser\Services\TokenQueryManager;
use MediaWiki\Extension\GlobalBlocking\GlobalBlocking;
use MediaWiki\Extension\TorBlock\TorExitNodes;
use MediaWiki\Html\FormOptions;
use MediaWiki\Html\Html;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Navigation\PagerNavigationBuilder;
use MediaWiki\Pager\RangeChronologicalPager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\ActorMigration;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserGroupMembership;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\Utils\MWTimestamp;
use RequestContext;
use stdClass;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\Database\DbQuoter;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\SelectQueryBuilder;

abstract class AbstractCheckUserPager extends RangeChronologicalPager implements CheckUserQueryInterface {

	/**
	 * Form fields that when paging should be set and managed
	 * by the token. Used so the client cannot generate results
	 * that do not match the original request which generated
	 * the associated CheckUserLog entry.
	 */
	public const TOKEN_MANAGED_FIELDS = [
		'reason',
		'checktype',
		'period',
		'dir',
		'limit',
		'offset',
	];

	/**
	 * Null if $target is a user.
	 * Boolean is $target is a IP / range.
	 *  - False if XFF is not appended
	 *  - True if XFF is appended
	 *
	 * @var null|bool
	 */
	protected ?bool $xfor = null;

	/** @var string The type of CheckUserLog entry this check should generate. */
	private string $logType;

	/** @var FormOptions The submitted form data in a helper class */
	protected FormOptions $opts;

	protected UserIdentity $target;

	/** @var bool Should Special:CheckUser read from the new event tables. */
	protected bool $eventTableReadNew;

	/** @var bool Should Special:CheckUser display Client Hints data. */
	protected bool $displayClientHints;

	/**
	 * @var string one of the SpecialCheckUser::SUBTYPE_... constants used by this abstract pager
	 *  to know what the current checktype is.
	 */
	protected string $checkType;

	protected UserGroupManager $userGroupManager;
	protected CentralIdLookup $centralIdLookup;
	private TokenQueryManager $tokenQueryManager;
	private SpecialPageFactory $specialPageFactory;
	private UserIdentityLookup $userIdentityLookup;
	private ActorMigration $actorMigration;
	private CheckUserLogService $checkUserLogService;
	protected TemplateParser $templateParser;
	protected UserFactory $userFactory;

	/**
	 * @param FormOptions $opts
	 * @param UserIdentity $target
	 * @param string $logType
	 * @param TokenQueryManager $tokenQueryManager
	 * @param UserGroupManager $userGroupManager
	 * @param CentralIdLookup $centralIdLookup
	 * @param IConnectionProvider $dbProvider
	 * @param SpecialPageFactory $specialPageFactory
	 * @param UserIdentityLookup $userIdentityLookup
	 * @param ActorMigration $actorMigration
	 * @param CheckUserLogService $checkUserLogService
	 * @param UserFactory $userFactory
	 * @param IContextSource|null $context
	 * @param LinkRenderer|null $linkRenderer
	 * @param ?int $limit
	 */
	public function __construct(
		FormOptions $opts,
		UserIdentity $target,
		string $logType,
		TokenQueryManager $tokenQueryManager,
		UserGroupManager $userGroupManager,
		CentralIdLookup $centralIdLookup,
		IConnectionProvider $dbProvider,
		SpecialPageFactory $specialPageFactory,
		UserIdentityLookup $userIdentityLookup,
		ActorMigration $actorMigration,
		CheckUserLogService $checkUserLogService,
		UserFactory $userFactory,
		IContextSource $context = null,
		LinkRenderer $linkRenderer = null,
		?int $limit = null
	) {
		$this->opts = $opts;
		$this->target = $target;
		$this->logType = $logType;

		$this->mDb = $dbProvider->getReplicaDatabase();

		parent::__construct( $context, $linkRenderer );

		$maximumRowCount = $this->getConfig()->get( 'CheckUserMaximumRowCount' );
		$this->mDefaultLimit = $limit ?? $maximumRowCount;
		if ( $this->opts->getValue( 'limit' ) ) {
			$this->mLimit = min(
				$this->opts->getValue( 'limit' ),
				$this->getConfig()->get( 'CheckUserMaximumRowCount' )
			);
		} else {
			$this->mLimit = $maximumRowCount;
		}

		$this->mLimitsShown = [
			$maximumRowCount / 25,
			$maximumRowCount / 10,
			$maximumRowCount / 5,
			$maximumRowCount / 2,
			$maximumRowCount,
		];

		$this->mLimitsShown = array_map( 'ceil', $this->mLimitsShown );
		$this->mLimitsShown = array_unique( $this->mLimitsShown );
		$this->eventTableReadNew = boolval(
			$this->getConfig()->get( 'CheckUserEventTablesMigrationStage' ) & SCHEMA_COMPAT_READ_NEW
		);
		$this->displayClientHints = $this->getConfig()->get( 'CheckUserDisplayClientHints' );

		$this->userGroupManager = $userGroupManager;
		$this->centralIdLookup = $centralIdLookup;
		$this->tokenQueryManager = $tokenQueryManager;
		$this->specialPageFactory = $specialPageFactory;
		$this->userIdentityLookup = $userIdentityLookup;
		$this->actorMigration = $actorMigration;
		$this->checkUserLogService = $checkUserLogService;
		$this->userFactory = $userFactory;

		$this->templateParser = new TemplateParser( __DIR__ . '/../../../templates' );

		// Get any set token data. Used for paging without adding extra logs
		$tokenData = $this->tokenQueryManager->getDataFromRequest( $this->getRequest() );
		if ( !$tokenData ) {
			// Log if the token data is not set. A token will only be generated by
			//  the server for CheckUser for paging links after running a check.
			//  It will also only be valid if not tampered with as it's encrypted.
			//  Paging through the entries won't need an extra log entry.
			$this->checkUserLogService->addLogEntry(
				$this->getUser(),
				$this->logType,
				$target->getId() ? 'user' : 'ip',
				$target->getName(),
				$this->opts->getValue( 'reason' ),
				$target->getId()
			);
		}

		$this->setPeriodCondition();
	}

	/**
	 * Generate the cutoff timestamp condition for the query
	 */
	protected function setPeriodCondition(): void {
		$period = $this->opts->getValue( 'period' );
		if ( $period ) {
			$cutoffTime = MWTimestamp::getInstance();
			$cutoffTime->timestamp->setTime( 0, 0 )->modify( "-$period day" );
			// Call to RangeChronologicalPager::getDateRangeCond sets
			// $this->startOffset to the $cutoffTime. The call does
			// not set $this->endOffset as the empty string is provided.
			$this->getDateRangeCond( $cutoffTime->getTimestamp(), '' );
		}
	}

	/**
	 * Get formatted timestamp(s) to show the time of first and last change.
	 * If both timestamps are the same, it will be shown only once.
	 *
	 * @param string $first Timestamp of the first change
	 * @param string $last Timestamp of the last change
	 * @return string
	 */
	protected function getTimeRangeString( string $first, string $last ): string {
		if ( $first === $last ) {
			return $this->getFormattedTimestamp( $first );
		} else {
			return $this->msg( 'checkuser-time-range' )
				->dateTimeParams( $first, $last )
				->escaped();
		}
	}

	/**
	 * Get a link to block information about the passed block for displaying to the user.
	 *
	 * @param DatabaseBlock $block
	 * @return string
	 */
	protected function getBlockFlag( DatabaseBlock $block ): string {
		if ( $block->getType() === DatabaseBlock::TYPE_AUTO ) {
			$ret = $this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'BlockList' ),
				$this->msg( 'checkuser-blocked' )->text(),
				[],
				[ 'wpTarget' => "#{$block->getId()}" ]
			);
		} else {
			$userPage = Title::makeTitle( NS_USER, $block->getTargetName() );
			$ret = $this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'Log' ),
				$this->msg( 'checkuser-blocked' )->text(),
				[],
				[
					'type' => 'block',
					'page' => $userPage->getPrefixedText()
				]
			);

			// Add the blocked range if the block is on a range
			if ( $block->getType() === DatabaseBlock::TYPE_RANGE ) {
				$ret .= ' - ' . htmlspecialchars( $block->getTargetName() );
			}
		}

		return Html::rawElement(
			'strong',
			[ 'class' => 'mw-changeslist-links' ],
			$ret
		);
	}

	/**
	 * Get an HTML link (<a> element) to Special:CheckUser
	 *
	 * @param string $text content to use within <a> tag
	 * @param array $params query parameters to use in the URL
	 * @return string
	 */
	protected function getSelfLink( string $text, array $params ): string {
		$title = $this->getTitleValue();
		return $this->getLinkRenderer()->makeKnownLink(
			$title,
			new HtmlArmor( '<bdi>' . htmlspecialchars( $text ) . '</bdi>' ),
			[],
			$params
		);
	}

	/**
	 * @param string $page the string title get the TitleValue for.
	 * @return TitleValue the associated TitleValue object
	 */
	protected function getTitleValue( string $page = 'CheckUser' ): TitleValue {
		return new TitleValue(
			NS_SPECIAL,
			$this->specialPageFactory->getLocalNameFor( $page )
		);
	}

	/**
	 * @param string $page the string title get the Title for.
	 * @return Title the associated Title object
	 */
	protected function getPageTitle( string $page = 'CheckUser' ): Title {
		return Title::newFromLinkTarget(
			$this->getTitleValue( $page )
		);
	}

	/**
	 * Get a formatted timestamp string in the current language
	 * for displaying to the user.
	 *
	 * @param string $timestamp
	 * @return string
	 */
	protected function getFormattedTimestamp( string $timestamp ): string {
		return $this->getLanguage()->userTimeAndDate(
			wfTimestamp( TS_MW, $timestamp ), $this->getUser()
		);
	}

	/**
	 * Generates the "no matches for X" message.
	 * Unless the target was an xff also try
	 *  to display the time of the last edit.
	 *
	 * @inheritDoc
	 */
	protected function getEmptyBody(): string {
		if ( !$this->xfor ) {
			// Only attempt to find the last edit or logged action timestamp if the
			// target is a user or an IP. If the target is a XFF then skip this.
			$user = $this->userIdentityLookup->getUserIdentityByName( $this->target->getName() );

			$lastEdit = false;

			$revWhere = $this->actorMigration->getWhere( $this->mDb, 'rev_user', $user );
			foreach ( $revWhere['orconds'] as $cond ) {
				$lastEdit = max( $lastEdit, $this->mDb->newSelectQueryBuilder()
					->tables( [ 'revision' ] + $revWhere['tables'] )
					->field( 'rev_timestamp' )
					->conds( $cond )
					->orderBy( 'rev_timestamp', SelectQueryBuilder::SORT_DESC )
					->joinConds( $revWhere['joins'] )
					->caller( __METHOD__ )
					->fetchField()
				);
			}
			$lastEdit = max( $lastEdit, $this->mDb->newSelectQueryBuilder()
				->table( 'logging' )
				->field( 'log_timestamp' )
				->orderBy( 'log_timestamp', SelectQueryBuilder::SORT_DESC )
				->join( 'actor', null, 'actor_id=log_actor' )
				->where( [ 'actor_name' => $this->target->getName() ] )
				->caller( __METHOD__ )
				->fetchField()
			);

			if ( $lastEdit ) {
				$lastEditTime = wfTimestamp( TS_MW, $lastEdit );
				$lang = $this->getLanguage();
				$contextUser = $this->getUser();
				// FIXME: don't pass around parsed messages
				return $this->msg( 'checkuser-nomatch-edits',
					$lang->userDate( $lastEditTime, $contextUser ),
					$lang->userTime( $lastEditTime, $contextUser )
				)->parseAsBlock() . "\n";
			}
		}
		return $this->msg( 'checkuser-nomatch' )->parseAsBlock() . "\n";
	}

	/**
	 * @param string $ip
	 * @param UserIdentity $user
	 * @return string[]
	 */
	protected function userBlockFlags( string $ip, UserIdentity $user ): array {
		$flags = [];
		// Needed because User::isBlockedGlobally doesn't seem to have a non User:: method.
		$userObj = $this->userFactory->newFromUserIdentity( $user );

		$block = DatabaseBlock::newFromTarget( $user, $ip );
		if ( $block instanceof DatabaseBlock ) {
			// Locally blocked
			$flags[] = $this->getBlockFlag( $block );
		} elseif (
			$ip == $user->getName() &&
			ExtensionRegistry::getInstance()->isLoaded( 'GlobalBlocking' ) &&
			GlobalBlocking::getUserBlock(
				$this->userFactory->newFromUserIdentity( $user ),
				$ip
			) instanceof AbstractBlock
		) {
			// Globally blocked IP
			$flags[] = '<strong>(' . $this->msg( 'checkuser-gblocked' )->escaped() . ')</strong>';
		} elseif (
			$ip == $user->getName() &&
			ExtensionRegistry::getInstance()->isLoaded( 'TorBlock' ) &&
			TorExitNodes::isExitNode( $ip )
		) {
			// Tor exit node
			$flags[] = Html::rawElement( 'strong', [], '(' . $this->msg( 'checkuser-torexitnode' )->escaped() . ')' );
		} elseif ( $this->userWasBlocked( $user->getName() ) ) {
			// Previously blocked
			$blocklog = $this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'Log' ),
				$this->msg( 'checkuser-wasblocked' )->text(),
				[],
				[
					'type' => 'block',
					// @todo Use TitleFormatter and PageReference to avoid the global state
					'page' => Title::makeTitle( NS_USER, $user->getName() )->getPrefixedText()
				]
			);
			$flags[] = Html::rawElement( 'strong', [ 'class' => 'mw-changeslist-links' ], $blocklog );
		}

		// Show if account is local only
		if ( $user->getId() &&
			$this->centralIdLookup
				->centralIdFromLocalUser( $user, CentralIdLookup::AUDIENCE_RAW ) === 0
		) {
			$flags[] = Html::rawElement(
				'strong',
				[ 'class' => 'mw-changeslist-links' ],
				$this->msg( 'checkuser-localonly' )->escaped()
			);
		}
		// Check for extra user rights...
		if ( $user->getId() ) {
			if ( $userObj->isLocked() ) {
				$flags[] = Html::rawElement(
					'strong',
					[ 'class' => 'mw-changeslist-links' ],
					$this->msg( 'checkuser-locked' )->escaped()
				);
			}
			$list = [];
			foreach ( $this->userGroupManager->getUserGroups( $user ) as $group ) {
				$list[] = self::buildGroupLink( $group );
			}
			$groups = $this->getLanguage()->commaList( $list );
			if ( $groups ) {
				$flags[] = Html::rawElement( 'i', [ 'class' => 'mw-changeslist-links' ], $groups );
			}
		}

		return $flags;
	}

	/**
	 * Format a link to a group description page
	 *
	 * @param string $group
	 * @return string
	 */
	protected static function buildGroupLink( string $group ): string {
		static $cache = [];
		if ( !isset( $cache[$group] ) ) {
			$cache[$group] = UserGroupMembership::getLinkHTML( $group, RequestContext::getMain() );
		}
		return $cache[$group];
	}

	/**
	 * Get whether the user has ever been blocked.
	 *
	 * @param string $name the username
	 * @return bool whether the user with that username has ever been blocked
	 */
	protected function userWasBlocked( string $name ): bool {
		$userpage = Title::makeTitle( NS_USER, $name );

		return (bool)$this->mDb->newSelectQueryBuilder()
			->table( 'logging' )
			->field( '1' )
			->conds( [
				'log_type' => [ 'block', 'suppress' ],
				'log_action' => 'block',
				'log_namespace' => $userpage->getNamespace(),
				'log_title' => $userpage->getDBkey()
			] )
			->useIndex( 'log_page_time' )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * @param string $target an IP address or CIDR range
	 * @return bool
	 */
	public static function isValidRange( string $target ): bool {
		$CIDRLimit = RequestContext::getMain()->getConfig()->get( 'CheckUserCIDRLimit' );
		if ( IPUtils::isValidRange( $target ) ) {
			[ $ip, $range ] = explode( '/', $target, 2 );
			return !(
				( IPUtils::isIPv4( $ip ) && $range < $CIDRLimit['IPv4'] ) ||
				( IPUtils::isIPv6( $ip ) && $range < $CIDRLimit['IPv6'] )
			);
		}

		return IPUtils::isValid( $target );
	}

	/**
	 * Get the WHERE conditions for an IP address / range, optionally as a XFF.
	 *
	 * @param DbQuoter $quoter A DB quoter, which can be a IReadableDatabase instance if convenient.
	 * @param string $target an IP address or CIDR range
	 * @param bool $xfor True if searching on XFF IPs by IP address / range
	 * @param string $table The table which will be used in the query these WHERE conditions
	 * are used (array of valid options in self::RESULT_TABLES).
	 * @return array|false array for valid conditions, false if invalid
	 */
	public static function getIpConds(
		DbQuoter $quoter, string $target, bool $xfor = false, string $table = self::CHANGES_TABLE
	) {
		$columnName = self::getIpHexColumn( $xfor, $table );

		if ( !self::isValidRange( $target ) ) {
			return false;
		}

		if ( IPUtils::isValidRange( $target ) ) {
			[ $start, $end ] = IPUtils::parseRange( $target );
			return [ $columnName . ' BETWEEN ' . $quoter->addQuotes( $start ) .
				' AND ' . $quoter->addQuotes( $end ) ];
		} elseif ( IPUtils::isValid( $target ) ) {
			return [ $columnName => IPUtils::toHex( $target ) ];
		}
		// invalid IP
		return false;
	}

	/**
	 * Gets the column name for the IP hex column based
	 * on the value for $xfor and a given $table.
	 *
	 * @param bool $xfor Whether the IPs being searched through are XFF IPs.
	 * @param string $table The table selecting results from (array of valid
	 * options in self::RESULT_TABLES).
	 * @return string
	 */
	private static function getIpHexColumn( bool $xfor, string $table ): string {
		$type = $xfor ? 'xff' : 'ip';
		return self::RESULT_TABLE_TO_PREFIX[$table] . $type . '_hex';
	}

	/**
	 * Gets the name for the index for a given table.
	 *
	 * note: When SCHEMA_COMPAT_READ_NEW is set, the query will not use an index
	 * on the values of `cuc_only_for_read_old`.
	 * That shouldn't result in a significant performance drop, and this is a
	 * temporary situation until the temporary column is removed after the
	 * migration is complete.
	 *
	 * @param string $table The table this index should apply to (list of valid options
	 *   in self::RESULT_TABLES).
	 * @return string
	 */
	protected function getIndexName( string $table ): string {
		// So that a code search can find existing usages:
		// cuc_actor_ip_time, cule_actor_ip_time, cupe_actor_ip_time, cuc_xff_hex_time, cuc_ip_hex_time,
		// cule_xff_hex_time, cule_ip_hex_time, cupe_xff_hex_time, cupe_ip_hex_time
		if ( $this->xfor === null ) {
			return self::RESULT_TABLE_TO_PREFIX[$table] . 'actor_ip_time';
		} else {
			$type = $this->xfor ? 'xff' : 'ip';
			return self::RESULT_TABLE_TO_PREFIX[$table] . $type . '_hex_time';
		}
	}

	/** @inheritDoc */
	public function getIndexField(): string {
		return 'timestamp';
	}

	/**
	 * @inheritDoc
	 *
	 * If the timestamp field is null, then the caller is either from outside
	 * CheckUser code or is not aware of what table a row comes from when
	 * reading results. In both cases returning "timestamp" is best as the
	 * queries alias the timestamp field to this name.
	 *
	 * If a caller uses the result of this method when $table is null as
	 * a column name in the WHERE conditions, the query will fail.
	 *
	 * @param string|null $table The table name. If null, returns the string "timestamp".
	 * @return string The timestamp field.
	 */
	public function getTimestampField( ?string $table = null ): string {
		if ( $table === null ) {
			return 'timestamp';
		}
		return self::RESULT_TABLE_TO_PREFIX[$table] . 'timestamp';
	}

	/** @inheritDoc */
	protected function getStartBody(): string {
		return $this->getNavigationBar() . '<div id="checkuserresults">';
	}

	/** @inheritDoc */
	protected function getEndBody(): string {
		return '</div>' . $this->getNavigationBar();
	}

	/** @inheritDoc */
	public function getNavigationBuilder(): PagerNavigationBuilder {
		$pagingQueries = $this->getPagingQueries();
		$baseQuery = array_merge( $this->getDefaultQuery(), [
			// These query parameters are all defined here, even though some are null,
			// to ensure consistent order of parameters when they're used.
			'dir' => null,
			'offset' => $this->getOffsetQuery(),
			'limit' => null,
		] );

		$navBuilder = new CheckUserPagerNavigationBuilder(
			$this->getContext(),
			$this->tokenQueryManager,
			$this->getCsrfTokenSet(),
			$this->getRequest(),
			$this->opts,
			$this->target
		);
		$navBuilder
			->setPage( $this->getTitle() )
			->setLinkQuery( $baseQuery )
			->setLimits( $this->mLimitsShown )
			->setLimitLinkQueryParam( 'limit' )
			->setCurrentLimit( $this->mLimit )
			->setPrevLinkQuery( $pagingQueries['prev'] ?: null )
			->setNextLinkQuery( $pagingQueries['next'] ?: null )
			->setFirstLinkQuery( $pagingQueries['first'] ?: null )
			->setLastLinkQuery( $pagingQueries['last'] ?: null );

		return $navBuilder;
	}

	/**
	 * Used by getCheckUserHelperFieldsetHTML to get the fieldset.
	 *  Seperated to allow testing of this method.
	 *
	 * @return ?HTMLFieldsetCheckUser
	 */
	private function getCheckUserHelperFieldset() {
		if ( !$this->mResult->numRows() ) {
			return null;
		}
		$collapseByDefault = $this->getConfig()->get( 'CheckUserCollapseCheckUserHelperByDefault' );
		if ( is_int( $collapseByDefault ) ) {
			$collapseByDefault = $this->mResult->numRows() > $collapseByDefault;
		}
		$fieldset = new HTMLFieldsetCheckUser( [], $this->getContext() );
		$fieldset->outerClass = 'mw-checkuser-helper-fieldset';
		$fieldset
			->setCollapsibleOptions( $collapseByDefault )
			->setWrapperLegendMsg( 'checkuser-helper-label' )
			->prepareForm()
			->suppressDefaultSubmit( true );
		return $fieldset;
	}

	/**
	 *
	 * Returns a empty HTML OOUI fieldset which is collapsible.
	 * Used by checkUserHelper.js and it's where the wikitext
	 *  table is added into the results page.
	 *
	 * @return string The HTML of the fieldset.
	 */
	protected function getCheckUserHelperFieldsetHTML() {
		$fieldsetHTML = $this->getCheckUserHelperFieldset();
		if ( $fieldsetHTML ) {
			return $this->getCheckUserHelperFieldset()->getHTML( false );
		} else {
			return '';
		}
	}

	/**
	 * @inheritDoc
	 *
	 * @param string|null $table One of the tables in CheckUserQueryInterface::RESULT_TABLES.
	 *   If set to null, this will throw a LogicException.
	 * @throws LogicException if $table is null a LogicException is thrown as ::getQueryInfo
	 * must have this information to return the correct query info.
	 */
	abstract public function getQueryInfo( ?string $table = null );

	/**
	 * Get the query info specific to cu_changes that will
	 * be extended with table independent query information
	 * (such as a actor_name WHERE condition). This
	 * method should only be called by the ::getQueryInfo
	 * implementation.
	 *
	 * @return array The query info specific to cu_changes
	 */
	abstract protected function getQueryInfoForCuChanges(): array;

	/**
	 * Get the query info specific to cu_log_event that will
	 * be extended with table independent query information
	 * (such as a actor_name WHERE condition). This
	 * method should only be called by the ::getQueryInfo
	 * implementation.
	 *
	 * @return array The query info specific to cu_log_event
	 */
	abstract protected function getQueryInfoForCuLogEvent(): array;

	/**
	 * Get the query info specific to cu_private_event that will
	 * be extended with table independent query information
	 * (such as a actor_name WHERE condition). This
	 * method should only be called by the ::getQueryInfo
	 * implementation.
	 *
	 * @return array The query info specific to cu_private_event
	 */
	abstract protected function getQueryInfoForCuPrivateEvent(): array;

	/**
	 * Take the raw results list and turn it into an array where the
	 * keys are the index field value and the values are the rows with
	 * this index field.
	 *
	 * Can be used by implementations to combine rows as necessary.
	 *
	 * @stable to override
	 *
	 * @param stdClass[] $results The results from all three tables as an array
	 *   where the rows are values, keys are numeric, and the order is
	 *   defined as the order returned by the queries and then the query
	 *   results ordered in the same order as self::RESULT_TABLES.
	 * @return stdClass[] The results grouped by the index field where the
	 *   key is the index field and the value is an array of the rows
	 *   with this index field.
	 */
	protected function groupResultsByIndexField( array $results ): array {
		// Expand the result set into an array, with the key as the timestamp and
		// value as an array of rows that have this timestamp.
		$groupedResults = [];
		$indexField = $this->getIndexField();
		foreach ( $results as $row ) {
			if ( array_key_exists( $row->$indexField, $groupedResults ) ) {
				// Use an array of rows as two given rows could have the same
				// timestamp value.
				$groupedResults[$row->$indexField][] = $row;
			} else {
				$groupedResults[$row->$indexField] = [ $row ];
			}
		}
		return $groupedResults;
	}

	/** @inheritDoc */
	public function reallyDoQuery( $offset, $limit, $order ): IResultWrapper {
		$results = [];
		// Run the three SQL queries for each results table.
		foreach ( $this->buildQueryInfo( $offset, $limit, $order ) as $queryInfo ) {
			[ $tables, $fields, $conds, $fname, $options, $join_conds ] = $queryInfo;

			$results = array_merge(
				$results,
				iterator_to_array( $this->mDb->select( $tables, $fields, $conds, $fname, $options, $join_conds ) )
			);
		}
		$results = $this->groupResultsByIndexField( $results );
		// Make a new result wrapper that combines the results by ordering all
		// by their timestamp and then returning the first $limit of the items.
		if ( $order === self::QUERY_DESCENDING ) {
			krsort( $results );
		} else {
			ksort( $results );
		}
		// Can remove the keys now that the results have been sorted.
		$results = array_values( $results );
		// Flatten the array. If for a given timestamp more than row is present,
		// from the same table then this method will keep the order in which
		// they were returned by IDatabase::select. If the rows come from
		// different tables, the rows will be ordered by table in the order
		// the tables are defined in self::RESULT_TABLES.
		$flattened_results = [];
		array_walk_recursive( $results, static function ( $value ) use ( &$flattened_results ) {
			$flattened_results[] = $value;
		} );
		// Apply the limit to the results.
		$flattened_results = array_slice( $flattened_results, 0, $limit );

		// Return the generated data as a FakeResultWrapper.
		return new FakeResultWrapper( $flattened_results );
	}

	/**
	 * Builds the query information. This is the same code as written in
	 * IndexPager::buildQueryInfo, ReverseChronologicalPager::buildQueryInfo
	 * and RangeChronologicalPager::buildQueryInfo, but modifies it to
	 * provide ::getQueryInfo with the arguments passed to this method.
	 *
	 * @inheritDoc
	 */
	public function buildQueryInfo( $offset, $limit, $order ): array {
		// Copied, with modification, from IndexPager::buildQueryInfo
		$fname = __METHOD__ . ' (' . $this->getSqlComment() . ')';
		$queryInfo = [];
		// Select data from all three tables when reading new, and only cu_changes when reading old.
		$resultTables = self::RESULT_TABLES;
		if ( $this->getConfig()->get( 'CheckUserEventTablesMigrationStage' ) & SCHEMA_COMPAT_READ_OLD ) {
			$resultTables = [ self::CHANGES_TABLE ];
		}
		foreach ( $resultTables as $table ) {
			$info = $this->getQueryInfo( $table );
			$tables = $info['tables'];
			$fields = $info['fields'];
			$conds = $info['conds'] ?? [];
			$options = $info['options'] ?? [];
			$join_conds = $info['join_conds'] ?? [];
			$indexColumns = (array)$this->mIndexField;
			$sortColumns = array_merge( $indexColumns, $this->mExtraSortFields );

			if ( $order === self::QUERY_ASCENDING ) {
				$options['ORDER BY'] = $sortColumns;
				$operator = $this->mIncludeOffset ? '>=' : '>';
			} else {
				$orderBy = [];
				foreach ( $sortColumns as $col ) {
					$orderBy[] = $col . ' DESC';
				}
				$options['ORDER BY'] = $orderBy;
				$operator = $this->mIncludeOffset ? '<=' : '<';
			}
			if ( $offset ) {
				// From IndexPager
				$offsets = explode( '|', $offset, count( $indexColumns ) );
				$indexColumns = array_slice( $indexColumns, 0, count( $offsets ) );
				// Replace 'timestamp' with the timestamp column name for the given table.
				$timestampField = $this->getTimestampField( $table );
				$indexColumns = array_map( static function ( $value ) use ( $timestampField ) {
					return $value === 'timestamp' ? $timestampField : $value;
				}, $indexColumns );
				// From IndexPager
				$conds[] = $this->mDb->buildComparison( $operator, array_combine( $indexColumns, $offsets ) );
			}
			$options['LIMIT'] = intval( $limit );

			// Copied from ReverseChronologicalPager::buildQueryInfo
			if ( $this->endOffset ) {
				$conds[] = $this->mDb->buildComparison(
					'<', [ $this->getTimestampField( $table ) => $this->endOffset ]
				);
			}

			// Copied from RangeChronologicalPager::buildQueryInfo
			if ( $this->startOffset ) {
				$conds[] = $this->mDb->buildComparison(
					'>=', [ $this->getTimestampField( $table ) => $this->startOffset ]
				);
			}
			// Add the data that would normally be returned by this method to an array
			// so that it can be returned for all three tables at once.
			$queryInfo[$table] = [ $tables, $fields, $conds, $fname, $options, $join_conds ];
		}
		return $queryInfo;
	}
}

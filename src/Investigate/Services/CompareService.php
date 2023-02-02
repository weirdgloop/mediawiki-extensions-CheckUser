<?php

namespace MediaWiki\CheckUser\Investigate\Services;

use IDatabase;
use LogicException;
use MediaWiki\CheckUser\CheckUserActorMigration;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\User\UserIdentityLookup;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Rdbms\Subquery;

class CompareService extends ChangeService {
	/** @var ServiceOptions */
	private $options;

	/** @var ILoadBalancer */
	private $loadBalancer;

	/**
	 * @internal For use by ServiceWiring
	 */
	public const CONSTRUCTOR_OPTIONS = [
		'CheckUserInvestigateMaximumRowCount',
	];

	/** @var int */
	private $limit;

	/**
	 * @param ServiceOptions $options
	 * @param ILoadBalancer $loadBalancer
	 * @param UserIdentityLookup $userIdentityLookup
	 */
	public function __construct(
		ServiceOptions $options,
		ILoadBalancer $loadBalancer,
		UserIdentityLookup $userIdentityLookup
	) {
		parent::__construct(
			$loadBalancer->getConnection( DB_REPLICA ),
			$loadBalancer->getConnection( DB_REPLICA ),
			$userIdentityLookup
		);

		$this->loadBalancer = $loadBalancer;
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->limit = $options->get( 'CheckUserInvestigateMaximumRowCount' );
	}

	/**
	 * Get edits made from an ip
	 *
	 * @param string $ipHex
	 * @param string|null $excludeUser
	 * @return int
	 */
	public function getTotalEditsFromIp(
		string $ipHex,
		string $excludeUser = null
	): int {
		$actorQuery = CheckUserActorMigration::newMigration()->getJoin( 'cuc_user' );

		$queryBuilder = $this->loadBalancer->getConnection( DB_REPLICA )->newSelectQueryBuilder()
			->select( 'cuc_id' )
			->tables( [ 'cu_changes' ] + $actorQuery['tables'] )
			->where( [
				'cuc_ip_hex' => $ipHex,
				'cuc_type' => [ RC_EDIT, RC_NEW ],
			] )
			->joinConds( $actorQuery['joins'] )
			->caller( __METHOD__ );

		if ( $excludeUser ) {
			$queryBuilder->where(
				'actor_name != ' . $this->dbQuoter->addQuotes( $excludeUser )
			);
		}

		return $queryBuilder->fetchRowCount();
	}

	/**
	 * Get the compare query info
	 *
	 * @param string[] $targets
	 * @param string[] $excludeTargets
	 * @param string $start
	 * @return array
	 */
	public function getQueryInfo( array $targets, array $excludeTargets, string $start ): array {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );

		$actorQuery = CheckUserActorMigration::newMigration()->getJoin( 'cuc_user' );

		if ( $targets === [] ) {
			throw new LogicException( 'Cannot get query info when $targets is empty.' );
		}
		$limit = (int)( $this->limit / count( $targets ) );

		$sqlText = [];
		foreach ( $targets as $target ) {
			$conds = $this->buildCondsForSingleTarget( $target, $excludeTargets, $start );
			if ( $conds !== null ) {
				$queryBuilder = $dbr->newSelectQueryBuilder()
					->select( [
						'cuc_id',
						'cuc_ip',
						'cuc_ip_hex',
						'cuc_agent',
						'cuc_timestamp',
					] + $actorQuery['fields'] )
					->tables( [ 'cu_changes' ] + $actorQuery['tables'] )
					->where( $conds )
					->joinConds( $actorQuery['joins'] )
					->caller( __METHOD__ );
				if ( $dbr->unionSupportsOrderAndLimit() ) {
					$queryBuilder->orderBy( 'cuc_timestamp', SelectQueryBuilder::SORT_DESC )
						->limit( $limit );
				}
				$sqlText[] = $queryBuilder->getSQL();
			}
		}

		$derivedTable = $dbr->unionQueries( $sqlText, IDatabase::UNION_DISTINCT );

		return [
			'tables' => [ 'a' => new Subquery( $derivedTable ) ],
			'fields' => [
				'cuc_user' => 'a.cuc_user',
				'cuc_user_text' => 'a.cuc_user_text',
				'cuc_ip' => 'a.cuc_ip',
				'cuc_ip_hex' => 'a.cuc_ip_hex',
				'cuc_agent' => 'a.cuc_agent',
				'first_edit' => 'MIN(a.cuc_timestamp)',
				'last_edit' => 'MAX(a.cuc_timestamp)',
				'total_edits' => 'count(*)',
			],
			'options' => [
				'GROUP BY' => [
					'cuc_user',
					'cuc_user_text',
					'cuc_ip',
					'cuc_ip_hex',
					'cuc_agent',
				],
			],
		];
	}

	/**
	 * Get the query info for a single target.
	 *
	 * For the main investigation, this is used in a subquery that contributes to a derived
	 * table, used by getQueryInfo.
	 *
	 * For a limit check, this is used to build a query that is used to check whether the number of results for
	 * the target exceed the limit-per-target in getQueryInfo.
	 *
	 * @param string $target
	 * @param string[] $excludeTargets
	 * @param string $start
	 * @return array|null Return null for invalid target
	 */
	private function buildCondsForSingleTarget(
		string $target,
		array $excludeTargets,
		string $start
	): ?array {
		$conds = $this->buildTargetConds( $target );
		if ( $conds === [] ) {
			return null;
		}

		$conds = array_merge(
			$conds,
			$this->buildExcludeTargetsConds( $excludeTargets ),
			$this->buildStartConds( $start )
		);

		$conds['cuc_type'] = [ RC_EDIT, RC_NEW, RC_LOG ];

		return $conds;
	}

	/**
	 * We set a maximum number of rows in cu_changes to be grouped in the Compare table query,
	 * for performance reasons (see ::getQueryInfo). We share these uniformly between the targets,
	 * so the maximum number of rows per target is the limit divided by the number of targets.
	 *
	 * @param array $targets
	 * @return int
	 */
	private function getLimitPerTarget( array $targets ) {
		return (int)( $this->limit / count( $targets ) );
	}

	/**
	 * Check if we have incomplete data for any of the targets.
	 *
	 * @param string[] $targets
	 * @param string[] $excludeTargets
	 * @param string $start
	 * @return string[]
	 */
	public function getTargetsOverLimit(
		array $targets,
		array $excludeTargets,
		string $start
	): array {
		if ( $targets === [] ) {
			return $targets;
		}

		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );

		// If the database does not support order and limit on a UNION
		// then none of the targets can be over the limit.
		if ( !$dbr->unionSupportsOrderAndLimit() ) {
			return [];
		}

		$targetsOverLimit = [];
		$offset = $this->getLimitPerTarget( $targets );

		$actorQuery = CheckUserActorMigration::newMigration()->getJoin( 'cuc_user' );

		foreach ( $targets as $target ) {
			$conds = $this->buildCondsForSingleTarget( $target, $excludeTargets, $start );
			if ( $conds !== null ) {
				$limitCheck = $dbr->newSelectQueryBuilder()
					->select( 'cuc_id' )
					->tables( [ 'cu_changes' ] + $actorQuery['tables'] )
					->where( $conds )
					->joinConds( $actorQuery['joins'] )
					->offset( $offset )
					->limit( 1 )
					->caller( __METHOD__ );
				if ( $limitCheck->fetchRowCount() > 0 ) {
					$targetsOverLimit[] = $target;
				}
			}
		}

		return $targetsOverLimit;
	}
}

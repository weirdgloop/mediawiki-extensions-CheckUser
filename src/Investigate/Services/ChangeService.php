<?php

namespace MediaWiki\CheckUser\Investigate\Services;

use MediaWiki\CheckUser\CheckUserQueryInterface;
use MediaWiki\CheckUser\Services\CheckUserLookupUtils;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\User\UserIdentityLookup;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\AndExpressionGroup;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\OrExpressionGroup;

abstract class ChangeService implements CheckUserQueryInterface {

	public const CONSTRUCTOR_OPTIONS = [
		'CheckUserEventTablesMigrationStage',
	];

	protected IConnectionProvider $dbProvider;
	protected CheckUserLookupUtils $checkUserLookupUtils;
	private UserIdentityLookup $userIdentityLookup;

	protected bool $eventTableReadNew;

	/**
	 * @param ServiceOptions $options
	 * @param IConnectionProvider $dbProvider
	 * @param UserIdentityLookup $userIdentityLookup
	 * @param CheckUserLookupUtils $checkUserLookupUtils
	 */
	public function __construct(
		ServiceOptions $options,
		IConnectionProvider $dbProvider,
		UserIdentityLookup $userIdentityLookup,
		CheckUserLookupUtils $checkUserLookupUtils
	) {
		$this->dbProvider = $dbProvider;
		$this->userIdentityLookup = $userIdentityLookup;
		$this->checkUserLookupUtils = $checkUserLookupUtils;
		// Calling $options::assertRequiredOptions is not necessary here, as it should be called by
		// classes which extend this class.
		$this->eventTableReadNew = boolval(
			$options->get( 'CheckUserEventTablesMigrationStage' ) & SCHEMA_COMPAT_READ_NEW
		);
	}

	/**
	 * Builds a query predicate depending on what type of
	 * target is passed in
	 *
	 * @param string[] $targets
	 * @param string $table The table the query is being performed on and must also be one of the tables listed in
	 *    {@link CheckUserQueryInterface::RESULT_TABLES}
	 * @return IExpression|null The conditions to be added to the query, or null if all the targets were invalid.
	 */
	protected function buildTargetExprMultiple( array $targets, string $table = self::CHANGES_TABLE ): ?IExpression {
		$targetExpressions = array_map( function ( $target ) use ( $table ) {
			return $this->buildTargetExpr( $target, $table );
		}, $targets );

		// Remove any null values from the target expressions array.
		$targetExpressions = array_filter( $targetExpressions );

		if ( !$targetExpressions ) {
			// If no targets provided in $targets were valid, then the $targetExpressions array can be empty. In this
			// case there are no conditions that can be generated, so null should be returned.
			return null;
		}

		// Combine the target IExpressions using OR.
		$orExpressionGroup = new OrExpressionGroup();
		foreach ( $targetExpressions as $targetExpr ) {
			$orExpressionGroup = $orExpressionGroup->orExpr( $targetExpr );
		}
		return $orExpressionGroup;
	}

	/**
	 * Builds a query predicate depending on what type of
	 * target is passed in
	 *
	 * @param string $target
	 * @param string $table The table the query is being performed on and must also be one of the tables listed in
	 *   {@link CheckUserQueryInterface::RESULT_TABLES}
	 * @return IExpression|null
	 */
	protected function buildTargetExpr( string $target, string $table = self::CHANGES_TABLE ): ?IExpression {
		if ( IPUtils::isIpAddress( $target ) ) {
			return $this->checkUserLookupUtils->getIPTargetExpr( $target, false, $table );
		} else {
			// TODO: This may filter out invalid values, changing the number of
			// targets. The per-target limit should change too (T246393).
			$user = $this->userIdentityLookup->getUserIdentityByName( $target );
			if ( $user ) {
				$userId = $user->getId();
				if ( $userId !== 0 ) {
					return $this->dbProvider->getReplicaDatabase()->expr( 'actor_user', '=', $userId );
				}
			}
		}

		return null;
	}

	/**
	 * Build conditions which can be used to exclude the given $targets from the results.
	 *
	 * @param string[] $targets The targets to be excluded
	 * @param string $table The table the query is being performed on and must also be one of the tables listed in
	 *    {@link CheckUserQueryInterface::RESULT_TABLES}
	 * @return IExpression|null The conditions to be added to the query, or null if no conditions are necessary
	 */
	protected function buildExcludeTargetsExpr( array $targets, string $table = self::CHANGES_TABLE ): ?IExpression {
		$ipTargets = [];
		$userTargets = [];

		foreach ( $targets as $target ) {
			if ( IPUtils::isIpAddress( $target ) ) {
				$ipTargets[] = IPUtils::toHex( $target );
			} else {
				$user = $this->userIdentityLookup->getUserIdentityByName( $target );
				if ( $user ) {
					$userId = $user->getId();
					if ( $userId !== 0 ) {
						$userTargets[] = $userId;
					}
				}
			}
		}

		if ( !count( $ipTargets ) && !count( $userTargets ) ) {
			// There will be no conditions if there are no users or IPs to exclude, so return null early.
			return null;
		}

		$expressionGroup = new AndExpressionGroup();
		if ( count( $ipTargets ) > 0 ) {
			$expressionGroup = $expressionGroup->and(
				self::RESULT_TABLE_TO_PREFIX[$table] . 'ip_hex', '!=', $ipTargets
			);
		}
		if ( count( $userTargets ) > 0 ) {
			$excludeUserTargetsExpr = $this->dbProvider->getReplicaDatabase()
				->expr( 'actor_user', '!=', $userTargets )
				->or( 'actor_user', '=', null );
			$expressionGroup = $expressionGroup->andExpr( $excludeUserTargetsExpr );
		}

		return $expressionGroup;
	}

	/**
	 * Build an IExpression for the start timestamp.
	 *
	 * @param string $start timestamp
	 * @param string $table The table the query is being performed on and must also be one of the tables listed in
	 *    {@link CheckUserQueryInterface::RESULT_TABLES}
	 * @return ?IExpression the WHERE conditions to add to the query, or null if there are none to add.
	 */
	protected function buildStartExpr( string $start, string $table = self::CHANGES_TABLE ): ?IExpression {
		if ( $start === '' ) {
			// If the start is empty, then we do not have any conditions to add.
			return null;
		}

		// TODO: T360712: Add cuc_id to the start conds to ensure unique ordering
		$dbr = $this->dbProvider->getReplicaDatabase();
		return $dbr->expr( self::RESULT_TABLE_TO_PREFIX[$table] . 'timestamp', '>=', $dbr->timestamp( $start ) );
	}
}

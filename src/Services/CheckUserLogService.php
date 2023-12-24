<?php

namespace MediaWiki\CheckUser\Services;

use CommentStore;
use DeferredUpdates;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\Title\Title;
use MediaWiki\User\ActorStore;
use Psr\Log\LoggerInterface;
use Sanitizer;
use User;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class CheckUserLogService {

	private IConnectionProvider $dbProvider;
	private CommentStore $commentStore;
	private CommentFormatter $commentFormatter;
	private LoggerInterface $logger;
	private ActorStore $actorStore;

	/** @var int */
	private $culReasonMigrationStage;

	/**
	 * @param IConnectionProvider $dbProvider
	 * @param CommentStore $commentStore
	 * @param CommentFormatter $commentFormatter
	 * @param LoggerInterface $logger
	 * @param ActorStore $actorStore
	 * @param int $culReasonMigrationStage
	 */
	public function __construct(
		IConnectionProvider $dbProvider,
		CommentStore $commentStore,
		CommentFormatter $commentFormatter,
		LoggerInterface $logger,
		ActorStore $actorStore,
		int $culReasonMigrationStage
	) {
		$this->dbProvider = $dbProvider;
		$this->commentStore = $commentStore;
		$this->commentFormatter = $commentFormatter;
		$this->logger = $logger;
		$this->actorStore = $actorStore;
		$this->culReasonMigrationStage = $culReasonMigrationStage;
	}

	/**
	 * Adds a log entry to the CheckUserLog.
	 *
	 * @param User $user
	 * @param string $logType
	 * @param string $targetType
	 * @param string $target
	 * @param string $reason
	 * @param int $targetID
	 * @return void
	 */
	public function addLogEntry(
		User $user, string $logType, string $targetType, string $target, string $reason, int $targetID = 0
	) {
		if ( $targetType == 'ip' ) {
			[ $rangeStart, $rangeEnd ] = IPUtils::parseRange( $target );
			$targetHex = $rangeStart;
			if ( $rangeStart == $rangeEnd ) {
				$rangeStart = '';
				$rangeEnd = '';
			}
		} else {
			$targetHex = '';
			$rangeStart = '';
			$rangeEnd = '';
		}

		$timestamp = ConvertibleTimestamp::now();
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$data = [
			'cul_actor' => $this->actorStore->acquireActorId( $user, $dbw ),
			'cul_type' => $logType,
			'cul_target_id' => $targetID,
			'cul_target_text' => trim( $target ),
			'cul_target_hex' => $targetHex,
			'cul_range_start' => $rangeStart,
			'cul_range_end' => $rangeEnd
		];

		if ( $this->culReasonMigrationStage & SCHEMA_COMPAT_WRITE_NEW ) {
			$plaintextReason = $this->getPlaintextReason( $reason );
		} else {
			$plaintextReason = '';
		}

		if ( $this->culReasonMigrationStage & SCHEMA_COMPAT_WRITE_OLD ) {
			$data['cul_reason'] = $reason;
		}

		$fname = __METHOD__;
		$commentStore = $this->commentStore;
		$logger = $this->logger;
		$writeNew = $this->culReasonMigrationStage & SCHEMA_COMPAT_WRITE_NEW;

		DeferredUpdates::addCallableUpdate(
			static function () use (
				$data, $timestamp, $reason, $plaintextReason, $fname, $dbw, $commentStore, $logger, $writeNew
			) {
				try {
					if ( $writeNew ) {
						$data += $commentStore->insert( $dbw, 'cul_reason', $reason );
						$data += $commentStore->insert( $dbw, 'cul_reason_plaintext', $plaintextReason );
					}
					$dbw->newInsertQueryBuilder()
						->insertInto( 'cu_log' )
						->row(
							[
								'cul_timestamp' => $dbw->timestamp( $timestamp )
							] + $data
						)
						->caller( $fname )
						->execute();
				} catch ( DBError $e ) {
					$logger->critical(
						'CheckUserLog entry was not recorded. This means checks can occur without being auditable. ' .
						'Immediate fix required.'
					);
					throw $e;
				}
			}
		);
	}

	/**
	 * Get the plaintext reason
	 *
	 * @param string $reason
	 * @return string
	 */
	public function getPlaintextReason( $reason ) {
		return Sanitizer::stripAllTags(
			$this->commentFormatter->formatBlock(
				$reason, Title::newFromText( 'Special:CheckUserLog' ),
				false, false, false
			)
		);
	}
}

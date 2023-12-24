<?php

namespace MediaWiki\CheckUser\Investigate\Services;

use MediaWiki\CheckUser\CheckUserCommentStore;

class TimelineService extends ChangeService {
	/**
	 * Get timeline query info
	 *
	 * @param string[] $targets
	 * @param string[] $excludeTargets
	 * @param string $start
	 * @return array
	 */
	public function getQueryInfo( array $targets, array $excludeTargets, string $start ): array {
		$commentQuery = CheckUserCommentStore::getStore()->getJoin( 'cuc_comment' );

		return [
			'tables' => [ 'cu_changes', 'cuc_user_actor' => 'actor' ] + $commentQuery['tables'],
			'fields' => [
				'cuc_namespace', 'cuc_title', 'cuc_actiontext', 'cuc_timestamp', 'cuc_minor',
				'cuc_page_id', 'cuc_type', 'cuc_this_oldid', 'cuc_last_oldid', 'cuc_ip',
				'cuc_xff', 'cuc_agent', 'cuc_id', 'cuc_user' => 'cuc_user_actor.actor_user',
				'cuc_user_text' => 'cuc_user_actor.actor_name'
			] + $commentQuery['fields'],
			'conds' => array_merge(
				$this->buildTargetCondsMultiple( $targets ),
				$this->buildExcludeTargetsConds( $excludeTargets ),
				$this->buildStartConds( $start )
			),
			'join_conds' => [
				'cuc_user_actor' => [ 'JOIN', 'cuc_user_actor.actor_id=cuc_actor' ]
			] + $commentQuery['joins']
		];
	}
}

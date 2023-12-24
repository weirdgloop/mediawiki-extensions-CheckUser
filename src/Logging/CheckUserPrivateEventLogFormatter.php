<?php

namespace MediaWiki\CheckUser\Logging;

use InvalidArgumentException;
use LogFormatter;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserRigorOptions;
use Message;

/**
 * LogFormatter for the rows stored in cu_private_event that are
 * not visible outside of CheckUser.
 *
 * Supports the log types checkuser-private-event/*
 */
class CheckUserPrivateEventLogFormatter extends LogFormatter {
	/**
	 * @inheritDoc
	 */
	protected function getMessageParameters() {
		$params = parent::getMessageParameters();

		if (
			$this->entry->getSubtype() == 'password-reset-email-sent' ||
			$this->entry->getSubtype() == 'login-success' ||
			$this->entry->getSubtype() == 'login-failure' ||
			$this->entry->getSubtype() == 'login-failure-with-good-password'
		) {
			// For messages:
			// * logentry-checkuser-private-event-login-success
			// * logentry-checkuser-private-event-login-failure
			// * logentry-checkuser-private-event-login-failure-with-good-password
			// * logentry-checkuser-private-event-password-reset-email-sent
			if ( $this->entry->getSubtype() == 'password-reset-email-sent' ) {
				$accountName = $this->entry->getParameters()['4::receiver'];
			} else {
				$accountName = $this->entry->getParameters()['4::target'];
			}
			$userFactory = MediaWikiServices::getInstance()->getUserFactory();
			// User can be non-existent or invalid in the case of failed login attempts.
			$user = $userFactory->newFromName( $accountName, UserRigorOptions::RIGOR_NONE );
			if ( !$user ) {
				throw new InvalidArgumentException( "The account name $accountName is not valid." );
			}
			$hidden = $user->isHidden()
				&& !$this->context->getAuthority()->isAllowed( 'hideuser' );
			if ( $hidden ) {
				$params[3] = Message::rawParam( Html::element(
					'span',
					[ 'class' => 'history-deleted' ],
					$this->msg( 'rev-deleted-user' )->text()
				) );
				$params[4] = '';
			} else {
				// Phan isn't happy unless $this->makeUserLink result is immediately wrapped in Message::rawParam
				$params[3] = Message::rawParam( $this->makeUserLink( $user ) );
				if ( $user->isHidden() ) {
					$params[3]['raw'] = Html::rawElement(
						'span',
						[ 'class' => [ 'mw-history-suppressed', 'history-deleted' ] ],
						$params[3]['raw']
					);
				}
				$params[4] = $accountName;
			}
		} elseif ( $this->entry->getSubtype() == 'email-sent' ) {
			// For message logentry-checkuser-private-event-email-sent
			$params[3] = $this->entry->getParameters()['4::hash'];
		} elseif ( $this->entry->getSubtype() == 'migrated-cu_changes-log-event' ) {
			// For message logentry-checkuser-private-event-migrated-cu_changes-log-event
			$params[3] = $this->entry->getParameters()['4::actiontext'];
		}

		return $params;
	}

	/** @inheritDoc */
	protected function getActionMessage() {
		$actionMessage = parent::getActionMessage();
		if ( $this->entry->getSubtype() === 'migrated-cu_changes-log-event' ) {
			// Use ::parse so that the wikitext in the actiontext parameter can be
			// parsed and displayed. Similar to WikitextLogFormatter, but defined
			// here to allow mixing non-wikitext and wikitext log parameters for
			// different messages.
			return $actionMessage->parse();
		}
		return $actionMessage;
	}

	/** @inheritDoc */
	protected function getParametersForApi() {
		if (
			$this->entry->getSubtype() == 'password-reset-email-sent' ||
			$this->entry->getSubtype() == 'login-success' ||
			$this->entry->getSubtype() == 'login-failure' ||
			$this->entry->getSubtype() == 'login-failure-with-good-password'
		) {
			$entryParametersForReturn = $this->entry->getParameters();
			// Hide the username if it is hidden and the user does not
			// have the rights to view it.
			if ( $this->entry->getSubtype() == 'password-reset-email-sent' ) {
				$accountName = $entryParametersForReturn['4::receiver'];
			} else {
				$accountName = $entryParametersForReturn['4::target'];
			}
			$userFactory = MediaWikiServices::getInstance()->getUserFactory();
			// User can be non-existent or invalid in the case of failed login attempts.
			$user = $userFactory->newFromName( $accountName, UserRigorOptions::RIGOR_NONE );
			if ( !$user ) {
				throw new InvalidArgumentException( "The account name $accountName is not valid." );
			}
			$hidden = $user->isHidden()
				&& !$this->context->getAuthority()->isAllowed( 'hideuser' );
			if ( $hidden ) {
				if ( $this->entry->getSubtype() == 'password-reset-email-sent' ) {
					$entryParametersForReturn['4::receiver'] = $this->msg( 'rev-deleted-user' )->text();
				} else {
					$entryParametersForReturn['4::target'] = $this->msg( 'rev-deleted-user' )->text();
				}
			}
			return $entryParametersForReturn;
		}
		return parent::getParametersForApi();
	}
}

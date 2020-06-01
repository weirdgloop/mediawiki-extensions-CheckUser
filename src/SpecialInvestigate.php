<?php

namespace MediaWiki\CheckUser;

use Html;
use HTMLForm;
use OOUI\ButtonGroupWidget;
use OOUI\ButtonWidget;
use OOUI\Element;
use OOUI\HtmlSnippet;
use OOUI\IndexLayout;
use OOUI\MessageWidget;
use OOUI\TabOptionWidget;
use OOUI\Tag;
use SpecialCheckUser;
use User;
use Wikimedia\IPUtils;

class SpecialInvestigate extends \FormSpecialPage {
	/** @var PagerFactory */
	private $preliminaryCheckPagerFactory;

	/** @var PagerFactory */
	private $comparePagerFactory;

	/** @var TimelinePagerFactory */
	private $timelinePagerFactory;

	/** @var TokenQueryManager */
	private $tokenQueryManager;

	/** @var IndexLayout|null */
	private $layout;

	/** @var array|null */
	private $tokenData;

	/** @var HTMLForm|null */
	private $form;

	/** @var string|null */
	private $tokenWithoutPaginationData;

	/**
	 * @param PagerFactory $preliminaryCheckPagerFactory
	 * @param PagerFactory $comparePagerFactory
	 * @param PagerFactory $timelinePagerFactory
	 * @param TokenQueryManager $tokenQueryManager
	 */
	public function __construct(
		PagerFactory $preliminaryCheckPagerFactory,
		PagerFactory $comparePagerFactory,
		PagerFactory $timelinePagerFactory,
		TokenQueryManager $tokenQueryManager
	) {
		parent::__construct( 'Investigate', 'investigate' );
		$this->preliminaryCheckPagerFactory = $preliminaryCheckPagerFactory;
		$this->comparePagerFactory = $comparePagerFactory;
		$this->timelinePagerFactory = $timelinePagerFactory;

		$this->tokenQueryManager = $tokenQueryManager;
	}

	/**
	 * @inheritDoc
	 */
	protected function preText() {
		// Add necessary styles
		$this->getOutput()->addModuleStyles( [
			'mediawiki.widgets.TagMultiselectWidget.styles',
		] );
		// Add button link to the log page on the main form.
		// Open in the current tab.
		$this->addIndicators( /** $newTab */ false, /** $logOnly */ true );

		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->getOutput()->addModuleStyles( 'ext.checkUser.investigate.styles' );
		$this->getOutput()->addModules( [ 'ext.checkUser.investigate' ] );

		parent::execute( $par );

		// Show the tabs if there is any request data.
		// The tabs should also be shown even if the form was a POST request because
		// the filters could have failed validation.
		if ( $this->getTokenData() !== [] ) {
			// Clear the existing form so it can be part of the tab layout.
			$this->getOutput()->clearHTML();

			$this->addIndicators();
			$this->addPageSubtitle();
			$this->addTabs( $par )->addTabContent( $par );
			$this->getOutput()->addHTML( $this->getLayout() );
		}
	}

	/**
	 * Returns the OOUI Index Layout and adds the module dependencies for OOUI.
	 *
	 * @return IndexLayout
	 */
	private function getLayout() : IndexLayout {
		if ( $this->layout === null ) {
			$this->getOutput()->enableOOUI();
			$this->getOutput()->addModuleStyles( [
				'oojs-ui-widgets.styles',
			] );

			$this->layout = new IndexLayout( [
				'framed' => false,
				'expanded' => false,
			] );
		}

		return $this->layout;
	}

	/**
	 * Add tabs to the layout. Provide the current tab so that tab can be highlighted.
	 *
	 * @param string $par
	 * @return self
	 */
	private function addTabs( string $par ) : self {
		$config = [];
		[
			'tabSelectWidget' => $tabSelectWidget,
		] = $this->getLayout()->getConfig( $config );

		$token = $this->getTokenWithoutPaginationData();

		$tabs = array_map( function ( $tab ) use ( $par, $token ) {
			$label = $this->getTabName( $tab );
			return new TabOptionWidget( [
				'label' => $label,
				'labelElement' => ( new Tag( 'a' ) )->setAttributes( [
					'href' => $this->getPageTitle( $label )->getLocalURL( [
						'token' => $token,
					] ),
				] ),
				'selected' => ( $par === $this->getTabParam( $tab ) ),
			] );
		}, [
			'preliminary-check',
			'compare',
			'timeline',
		] );

		$tabSelectWidget->addItems( $tabs );

		return $this;
	}

	/**
	 * @return string|null
	 */
	private function getTokenWithoutPaginationData() {
		if ( $this->tokenWithoutPaginationData === null ) {
			$this->tokenWithoutPaginationData = $this->getUpdatedToken( [
				'offset' => null,
			] );
		}
		return $this->tokenWithoutPaginationData;
	}

	/**
	 * Add HTML to Layout.
	 *
	 * @param string $html
	 * @return self
	 */
	private function addHtml( string $html ) : self {
		$config = [];
		[
			'contentPanel' => $contentPanel
		] = $this->getLayout()->getConfig( $config );

		$contentPanel->addItems( [
			new Element( [
				'content' => new HtmlSnippet( $html ),
			] ),
		] );

		return $this;
	}

	/**
	 * Add Pager Output to Layout.
	 *
	 * @param \ParserOutput $parserOutput
	 * @return self
	 */
	private function addParserOutput( \ParserOutput $parserOutput ) : self {
		$this->getOutput()->addParserOutputMetadata( $parserOutput );
		$this->addHTML( $parserOutput->getText() );

		return $this;
	}

	/**
	 * Add Tab content to Layout
	 *
	 * @param string $par
	 * @return self
	 */
	private function addTabContent( string $par ) : self {
		switch ( $par ) {
			case $this->getTabParam( 'preliminary-check' ):
				$pager = $this->preliminaryCheckPagerFactory->createPager( $this->getContext() );
				$hasIpTargets = (bool)array_filter(
					$this->getTokenData()['targets'] ?? [],
					function ( $target ) {
						return IPUtils::isIPAddress( $target );
					}
				);

				if ( $pager->getNumRows() ) {
					$this->addParserOutput( $pager->getFullOutput() );
				} elseif ( !$hasIpTargets ) {
					$this->addHTML(
						$this->msg( 'checkuser-investigate-notice-no-results' )->parse()
					);
				}

				if ( $hasIpTargets ) {
					$compareLabel = $this->msg( 'checkuser-investigate-tab-compare' )->parse();
					// getFullURL handles the query params:
					// https://www.mediawiki.org/wiki/Help:Links#External_links_to_internal_pages
					$link = $this->getPageTitle( $compareLabel )->getFullURL( [
						'token' => $this->getTokenWithoutPaginationData(),
					] );
					$message = $this->msg( 'checkuser-investigate-preliminary-notice-ip-targets', $link )->parse();
					$this->addHTML( new MessageWidget( [
						'type' => 'notice',
						'label' => new HtmlSnippet( $message )
					] ) );
				}

				return $this;
			case $this->getTabParam( 'compare' ):
				$pager = $this->comparePagerFactory->createPager( $this->getContext() );
				$numRows = $pager->getNumRows();
				$numFilters = count( $this->getTokenData()['exclude-targets'] ?? [] );

				if ( $numRows || $numFilters ) {
					// Add the filter form field.
					$this->addHTML( $this->getForm()->getHTML( $this->getForm()->wasSubmitted() ) );
				}

				if ( $numRows ) {
					$targetsOverLimit = $pager->getTargetsOverLimit();
					if ( $targetsOverLimit ) {
						$message = $this->msg(
							'checkuser-investigate-compare-notice-exceeded-limit',
							$this->getLanguage()->commaList( $targetsOverLimit )
						)->parse();
						$this->addHTML( new MessageWidget( [
							'type' => 'warning',
							'label' => new HtmlSnippet( $message )
						] ) );
					}

					$this->addParserOutput( $pager->getFullOutput() );
				} else {
					$messageKey = $numFilters ?
						'checkuser-investigate-compare-notice-no-results-filters' :
						'checkuser-investigate-compare-notice-no-results';
					$message = $this->msg( $messageKey )->parse();
					$this->addHTML( new MessageWidget( [
						'type' => 'warning',
						'label' => new HtmlSnippet( $message )
					] ) );
				}
				return $this;
			case $this->getTabParam( 'timeline' ):
				$pager = $this->timelinePagerFactory->createPager( $this->getContext() );
				$numRows = $pager->getNumRows();
				$numFilters = count( $this->getTokenData()['exclude-targets'] ?? [] );

				if ( $numRows || $numFilters ) {
					// Add the filter form field.
					// TODO: enable once filters are working
					// $this->addHTML( $this->getForm()->getHTML( $this->getForm()->wasSubmitted() ) );
				}

				if ( $numRows ) {
					$this->addParserOutput( $pager->getFullOutput() );
				} else {
					$messageKey = $numFilters ?
						'checkuser-investigate-timeline-notice-no-results-filters' :
						'checkuser-investigate-timeline-notice-no-results';
					$message = $this->msg( $messageKey )->parse();
					$this->addHTML( new MessageWidget( [
						'type' => 'warning',
						'label' => new HtmlSnippet( $message )
					] ) );
				}
				return $this;
			default:
				return $this;
		}
	}

	/**
	 * Given a tab name, return the subpage $par.
	 *
	 * @param string $tab
	 *
	 * @return string
	 */
	private function getTabParam( string $tab ) : string {
		return str_replace( ' ', '_', $this->getTabName( $tab ) );
	}

	/**
	 * Given a tab name, return the supage tab name.
	 *
	 * @param string $tab
	 *
	 * @return string
	 */
	private function getTabName( string $tab ) : string {
		return $this->msg( 'checkuser-investigate-tab-' . $tab )->parse();
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( $this->getMessagePrefix() )->text();
	}

	/**
	 * @inheritDoc
	 */
	protected function getMessagePrefix() {
		return 'checkuser-' . strtolower( $this->getName() );
	}

	/**
	 * Add page subtitle including the name of the targets
	 * in the investigation
	 */
	private function addPageSubtitle() {
		$targets = $this->getTokenData()['targets'] ?? [];
		if ( $targets ) {
			$targets = $this->getLanguage()->listToText( array_map( function ( $target ) {
				return Html::rawElement( 'strong', [], htmlspecialchars( $target ) );
			}, $targets ) );
			$subtitle = $this->msg( 'checkuser-investigate-page-subtitle', $targets );
			$this->getOutput()->addSubtitle( $subtitle );
		}
	}

	/**
	 * Add buttons to start a new investigation and linking
	 * to InvestigateLog page
	 *
	 * @param bool $newTab Whether to open the link in a new tab
	 * @param bool $logOnly Whether to show only the log button
	 */
	private function addIndicators( $newTab = true, $logOnly = false ) {
		$log = new ButtonWidget( [
			'label' => $this->msg( 'checkuser-investigate-indicator-logs' )->text(),
			'href' => self::getTitleFor( 'InvestigateLog' )->getLinkURL(),
			'target' => $newTab ? '_blank' : '',
		] );

		$newForm = new ButtonWidget( [
			'label' => $this->msg( 'checkuser-investigate-indicator-new-investigation' )->text(),
			'href' => self::getTitleFor( 'Investigate' )->getLinkURL(),
			'target' => $newTab ? '_blank' : '',
		] );

		if ( $logOnly ) {
			$this->getOutput()->setIndicators( [
				'ext-checkuser-investigation-btns' => $log,
			] );
		} else {
			$this->getOutput()->setIndicators( [
				'ext-checkuser-investigation-btns' => new ButtonGroupWidget( [
					'classes' => [ 'ext-checkuser-investigate-indicators' ],
					'items' => [ $newForm, $log ],

				] ),
			] );
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}

	/**
	 * @inheritDoc
	 */
	protected function getForm() {
		if ( $this->form === null ) {
			$this->form = parent::getForm();
		}

		return $this->form;
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormFields() {
		$data = $this->getTokenData();
		$prefix = $this->getMessagePrefix();

		if ( $data === [] ) {
			return [
				'Targets' => [
					'type' => 'usersmultiselect',
					'name' => 'targets',
					'label-message' => $prefix . '-targets-label',
					'placeholder' => $this->msg( $prefix . '-targets-placeholder' )->text(),
					'required' => true,
					'max' => 10,
					'exists' => true,
					'ipallowed' => true,
					'iprange' => true,
					'default' => implode( "\n", $data['targets'] ?? [] ),
					'input' => [
						'autocomplete' => false,
					],
				],
				'Reason' => [
					'type' => 'text',
					'name' => 'reason',
					'label-message' => $prefix . '-reason-label',
					'required' => true,
					'autocomplete' => false,
				],
			];
		}

		$fields = [];

		// Filters for both Compare & Timeline
		$compareTab = $this->getTabParam( 'compare' );
		$timelineTab = $this->getTabParam( 'timeline' );

		// Filters for both Compare & Timeline
		if ( in_array( $this->par, [ $compareTab, $timelineTab ], true ) ) {
			$fields['ExcludeTargets'] = [
				'type' => 'usersmultiselect',
				'name' => 'exclude-targets',
				'label-message' => $prefix . '-filters-exclude-targets-label',
				'exists' => true,
				'required' => false,
				'ipallowed' => true,
				'iprange' => false,
				'default' => implode( "\n", $data['exclude-targets'] ?? [] ),
				'input' => [
					'autocomplete' => false,
				],
			];
		}

		if ( $this->par === $compareTab ) {
			$fields['Targets'] = [
				'type' => 'hidden',
				'name' => 'targets',
			];
		}

		if ( $this->par === $timelineTab ) {
			// @TODO Add filters specific to the timeline tab.
		}

		return $fields;
	}

	/**
	 * @inheritDoc
	 */
	protected function alterForm( HTMLForm $form ) {
		// Not done by default in OOUI forms, but done here to match
		// intended design in T237034. See FormSpecialPage::getForm
		if ( $this->getTokenData() === [] ) {
			$form->setWrapperLegendMsg( $this->getMessagePrefix() . '-legend' );
		} else {
			$tabs = [ $this->getTabParam( 'compare' ), $this->getTabParam( 'timeline' ) ];
			if ( in_array( $this->par, $tabs ) ) {
				$form->setAction( $this->getRequest()->getRequestURL() );
				$form->setWrapperLegendMsg( $this->getMessagePrefix() . '-filters-legend' );
				// If the page is a result of a POST then validation failed, and the form should be open.
				// If the page is a result of a GET then validation succeeded and the form should be closed.
				$form->setCollapsibleOptions( !$this->getRequest()->wasPosted() );
			}
		}
	}

	/**
	 * Get data from the request token.
	 *
	 * @return array
	 */
	private function getTokenData() : array {
		if ( $this->tokenData === null ) {
			$this->tokenData = $this->tokenQueryManager->getDataFromRequest( $this->getRequest() );
		}

		return $this->tokenData;
	}

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $data ) {
		$update = [
			'offset' => null,
		];

		if ( isset( $data['Reason'] ) ) {
			$update['reason'] = $data['Reason'];
		}
		if ( isset( $data['ExcludeTargets' ] ) ) {
			$update['exclude-targets'] = $this->getArrayFromField( $data, 'ExcludeTargets' );
		}
		if ( isset( $data['Targets' ] ) ) {
			$tokenData = $this->getTokenData();
			$update['targets'] = $this->getArrayFromField( $data, 'Targets' );

			$this->addLogEntries(
				$update['targets'],
				$update['reason'] ?? $tokenData['reason']
			);

			$update['targets'] = array_unique( array_merge(
				$update['targets'],
				$tokenData['targets'] ?? []
			) );
		}

		$token = $this->getUpdatedToken( $update );

		if ( isset( $this->par ) && $this->par !== '' ) {
			// Redirect to the same subpage with an updated token.
			$url = $this->getRedirectUrl( $token );
		} else {
			// Redirect to preliminary check.
			$url = $this->getPageTitle( $this->getTabName( 'preliminary-check' ) )->getFullUrlForRedirect( [
				'token' => $token,
			] );
		}
		$this->getOutput()->redirect( $url );

		return \Status::newGood();
	}

	/**
	 * Add a log entry for each target under investigation.
	 *
	 * @param string[] $targets
	 * @param string $reason
	 */
	protected function addLogEntries( array $targets, string $reason ) {
		$logType = 'investigate';

		foreach ( $targets as $target ) {
			if ( IPUtils::isIPAddress( $target ) ) {
				$targetType = 'ip';
				$targetId = 0;
			} else {
				// The form validated that the user exists on this wiki
				$targetType = 'user';
				$targetId = User::idFromName( $target );
			}

			SpecialCheckUser::addLogEntry(
				$logType,
				$targetType,
				$target,
				$reason,
				$targetId
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'users';
	}

	/**
	 * Get an updated token.
	 *
	 * Preforms an array merge on the updates with what is in the current token.
	 * Setting a value to null will remove it.
	 *
	 * @param array $update
	 * @return string
	 */
	private function getUpdatedToken( array $update ) : string {
		return $this->tokenQueryManager->updateToken(
			$this->getRequest(),
			$update
		);
	}

	/**
	 * Get a redirect URL with a token
	 *
	 * @param string $token
	 * @return string
	 */
	private function getRedirectUrl( string $token ) : string {
		$parts = wfParseURL( $this->getRequest()->getFullRequestURL() );
		$query = isset( $parts['query'] ) ? wfCgiToArray( $parts['query'] ) : [];
		$query['token'] = $token;
		$parts['query'] = wfArrayToCgi( $query );
		return wfAssembleUrl( $parts );
	}

	/**
	 * Get an array of values from a new line seperated field.
	 *
	 * @param array $data
	 * @param string $field
	 * @return string[]
	 */
	private function getArrayFromField( array $data, string $field ) : array {
		if ( !isset( $data[$field] ) ) {
			return [];
		}

		if ( !is_string( $data[$field] ) ) {
			return [];
		}

		if ( $data[$field] === '' ) {
			return [];
		}

		return explode( "\n", $data[$field] );
	}
}

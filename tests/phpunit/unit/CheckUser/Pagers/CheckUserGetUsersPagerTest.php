<?php

namespace MediaWiki\CheckUser\Tests\Unit\CheckUser\Pagers;

use HashConfig;
use LogicException;
use MediaWiki\CheckUser\CheckUser\Pagers\CheckUserGetUsersPager;
use MediaWiki\CheckUser\ClientHints\ClientHintsReferenceIds;
use MediaWiki\CheckUser\Services\UserAgentClientHintsLookup;
use MediaWiki\CheckUser\Services\UserAgentClientHintsManager;
use MediaWiki\User\UserIdentityValue;
use RequestContext;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Test class for CheckUserGetUsersPager class
 *
 * @group CheckUser
 *
 * @covers \MediaWiki\CheckUser\CheckUser\Pagers\CheckUserGetUsersPager
 */
class CheckUserGetUsersPagerTest extends CheckUserPagerCommonUnitTest {

	protected function getPagerClass(): string {
		return CheckUserGetUsersPager::class;
	}

	/** @dataProvider provideFormatRow */
	public function testFormatRow( $rowArgument ) {
		$objectUnderTest = $this->getMockBuilder( CheckUserGetUsersPager::class )
			->disableOriginalConstructor()
			->onlyMethods( [] )
			->getMock();
		$this->assertSame(
			'',
			$objectUnderTest->formatRow( $rowArgument ),
			'::formatRow should return the empty string as it is not called.'
		);
	}

	public static function provideFormatRow() {
		return [
			'Empty array' => [ [] ],
			'Empty object' => [ (object)[] ],
			'Array with items' => [ [ 'user_text' => 'test' ] ],
			'Object with items' => [ (object)[ 'user' => 0 ] ],
		];
	}

	public function testGetQueryInfoThrowsExceptionWithNullTable() {
		$object = $this->getMockBuilder( CheckUserGetUsersPager::class )
			->disableOriginalConstructor()
			->onlyMethods( [] )
			->getMock();
		$this->expectException( LogicException::class );
		$object->getQueryInfo( null );
	}

	/** @dataProvider provideGetQueryInfo */
	public function testGetQueryInfo( $table, $tableSpecificQueryInfo, $expectedQueryInfo ) {
		// Mock config on main request context for ::getIpConds which is static
		// and gets the config from the main request.
		RequestContext::getMain()->setConfig(
			new HashConfig( [ 'CheckUserCIDRLimit' => [
				'IPv4' => 16,
				'IPv6' => 19,
			] ] )
		);
		$this->commonTestGetQueryInfo(
			UserIdentityValue::newAnonymous( '127.0.0.1' ), false,
			$table, $tableSpecificQueryInfo, $expectedQueryInfo
		);
	}

	public static function provideGetQueryInfo() {
		return [
			'cu_changes table' => [
				'cu_changes', [
					'tables' => [ 'cu_changes' ],
					'conds' => [ 'cuc_only_for_read_old' => 0 ],
					'fields' => [], 'options' => [], 'join_conds' => [],
				],
				[
					'tables' => [ 'cu_changes' ],
					'conds' => [ 'cuc_ip_hex' => IPUtils::toHex( '127.0.0.1' ), 'cuc_only_for_read_old' => 0 ],
					'fields' => [],
					'options' => [ 'USE INDEX' => [ 'cu_changes' => 'cuc_ip_hex_time' ] ],
					'join_conds' => [],
				]
			],
			'cu_log_event table' => [
				'cu_log_event', [
					'tables' => [ 'cu_log_event' ], 'conds' => [],
					'fields' => [], 'options' => [], 'join_conds' => [],
				],
				[
					'tables' => [ 'cu_log_event' ],
					'conds' => [ 'cule_ip_hex' => IPUtils::toHex( '127.0.0.1' ) ],
					'fields' => [],
					'options' => [ 'USE INDEX' => [ 'cu_log_event' => 'cule_ip_hex_time' ] ],
					'join_conds' => [],
				]
			],
			'cu_private_event table' => [
				'cu_private_event', [
					'tables' => [ 'cu_private_event' ], 'conds' => [],
					'fields' => [], 'options' => [], 'join_conds' => [],
				],
				[
					'tables' => [ 'cu_private_event' ],
					'conds' => [ 'cupe_ip_hex' => IPUtils::toHex( '127.0.0.1' ) ],
					'fields' => [],
					'options' => [ 'USE INDEX' => [ 'cu_private_event' => 'cupe_ip_hex_time' ] ],
					'join_conds' => [],
				]
			],
		];
	}

	/** @dataProvider provideGetQueryInfoForCuChanges */
	public function testGetQueryInfoForCuChanges( $eventTableMigrationStage, $displayClientHints, $expectedQueryInfo ) {
		$this->commonGetQueryInfoForTableSpecificMethod(
			'getQueryInfoForCuChanges',
			[
				'eventTableReadNew' => boolval( $eventTableMigrationStage & SCHEMA_COMPAT_READ_NEW ),
				'displayClientHints' => $displayClientHints
			],
			$expectedQueryInfo
		);
	}

	public static function provideGetQueryInfoForCuChanges() {
		return [
			'Returns expected keys to arrays and includes cu_changes in tables while reading new' => [
				SCHEMA_COMPAT_READ_NEW, false, [
					// Fields should be an array
					'fields' => [],
					// Assert at least cu_changes in the table list
					'tables' => [ 'cu_changes' ],
					// When reading new, do not include rows from cu_changes
					// that were marked as only being for read old.
					'conds' => [ 'cuc_only_for_read_old' => 0 ],
					// Should be all of these as arrays
					'options' => [],
					'join_conds' => [],
				]
			],
			'Returns expected keys to arrays and includes cu_changes in tables while reading old' => [
				SCHEMA_COMPAT_READ_OLD, false, [
					// Fields should be an array
					'fields' => [],
					// Assert at least cu_changes in the table list
					'tables' => [ 'cu_changes' ],
					// Should be all of these as arrays
					'conds' => [],
					'options' => [],
					'join_conds' => [],
				]
			],
			'Client Hints enabled' => [
				SCHEMA_COMPAT_READ_OLD,
				true,
				[
					'fields' => [
						'client_hints_reference_id' => 'cuc_this_oldid',
						'client_hints_reference_type' => UserAgentClientHintsManager::IDENTIFIER_CU_CHANGES
					]
				]
			],
		];
	}

	/** @dataProvider provideGetQueryInfoForCuLogEvent */
	public function testGetQueryInfoForCuLogEvent( $displayClientHints, $expectedQueryInfo ) {
		$this->commonGetQueryInfoForTableSpecificMethod(
			'getQueryInfoForCuLogEvent',
			[
				'displayClientHints' => $displayClientHints
			],
			$expectedQueryInfo
		);
	}

	public static function provideGetQueryInfoForCuLogEvent() {
		return [
			'Returns expected keys to arrays and includes cu_log_event in tables' => [
				false,
				[
					# Fields should be an array
					'fields' => [],
					# Tables array should have at least cu_private_event
					'tables' => [ 'cu_log_event' ],
					# All other values should be arrays
					'conds' => [],
					'options' => [],
					'join_conds' => [],
				]
			],
			'Client Hints enabled' => [
				true,
				[
					'fields' => [
						'client_hints_reference_id' => 'cule_log_id',
						'client_hints_reference_type' => UserAgentClientHintsManager::IDENTIFIER_CU_LOG_EVENT
					]
				]
			],
		];
	}

	/** @dataProvider provideGetQueryInfoForCuPrivateEvent */
	public function testGetQueryInfoForCuPrivateEvent( $displayClientHints, $expectedQueryInfo ) {
		$this->commonGetQueryInfoForTableSpecificMethod(
			'getQueryInfoForCuPrivateEvent',
			[
				'displayClientHints' => $displayClientHints
			],
			$expectedQueryInfo
		);
	}

	public static function provideGetQueryInfoForCuPrivateEvent() {
		return [
			'Returns expected keys to arrays and includes cu_private_event in tables' => [
				false,
				[
					# Fields should be an array
					'fields' => [],
					# Tables array should have at least cu_private_event
					'tables' => [ 'cu_private_event' ],
					# All other values should be arrays
					'conds' => [],
					'options' => [],
					'join_conds' => [],
				]
			],
			'Client Hints enabled' => [
				true,
				[
					'fields' => [
						'client_hints_reference_id' => 'cupe_id',
						'client_hints_reference_type' => UserAgentClientHintsManager::IDENTIFIER_CU_PRIVATE_EVENT
					]
				]
			],
		];
	}

	/** @dataProvider providePreprocessResults */
	public function testPreprocessResults(
		$results, $displayClientHints, $expectedReferenceIdsForLookup, $expectedUserSets
	) {
		// Get the object to test with
		$objectUnderTest = $this->getMockBuilder( CheckUserGetUsersPager::class )
			->disableOriginalConstructor()
			->onlyMethods( [] )
			->getMock();
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		// Set whether to display Client Hints.
		$objectUnderTest->displayClientHints = $displayClientHints;
		if ( $displayClientHints ) {
			// If displaying Client Hints, then expect that the method under test looks up
			// the Client Hints data objects using the UserAgentClientHintsLookup service
			// and also that the reference IDs being used are as expected.
			$mockClientHintsLookup = $this->createMock( UserAgentClientHintsLookup::class );
			$mockClientHintsLookup->expects( $this->once() )
				->method( 'getClientHintsByReferenceIds' )
				->with( $this->callback( function ( $referenceIds ) use ( $expectedReferenceIdsForLookup ) {
					// Assert that the ClientHintsReferenceIds object passed has the
					// correct reference IDs
					// If this is the case, then return true.
					$this->assertArrayEquals(
						$expectedReferenceIdsForLookup->getReferenceIds(),
						$referenceIds->getReferenceIds(),
						false,
						true,
						'::preprocessResults did use the expected reference IDs to lookup the Client Hints data.'
					);
					return true;
				} ) );
			$objectUnderTest->clientHintsLookup = $mockClientHintsLookup;
		} else {
			// If not displaying Client Hints data, no lookup should be done.
			$mockClientHintsLookup = $this->createMock( UserAgentClientHintsLookup::class );
			$mockClientHintsLookup->expects( $this->never() )->method( 'getClientHintsByReferenceIds' );
			$objectUnderTest->clientHintsLookup = $mockClientHintsLookup;
		}
		// Call the method under test.
		$objectUnderTest->preprocessResults( $results );
		// Assert that the userSets array contains the expected items.
		$actualWithoutClientHints = $objectUnderTest->userSets;
		unset( $actualWithoutClientHints['clienthints'] );
		$this->assertArrayContains(
			$actualWithoutClientHints,
			$expectedUserSets,
			'::preprocessResults did not set the "userSets" property to the expected array.'
		);
		// Check that the expected and actual 'clienthints' arrays have the same keys.
		$this->assertArrayEquals(
			array_keys( $expectedUserSets['clienthints'] ),
			array_keys( $objectUnderTest->userSets['clienthints'] ),
			false,
			false,
			'::preprocessResults did not set the "clienthints" array of the "userSets" property ' .
			'to the expected array.'
		);
		// Check the ClientHintsReferenceIds objects have the same reference IDs for each name.
		foreach ( $objectUnderTest->userSets['clienthints'] as $name => $referenceIds ) {
			$this->assertArrayEquals(
				$expectedUserSets['clienthints'][$name]->getReferenceIds(),
				$referenceIds->getReferenceIds(),
				false,
				true,
				'::preprocessResults did not set the "clienthints" array of the "userSets" property ' .
				'to the expected array.'
			);
		}
	}

	public static function providePreprocessResults() {
		$smallestFakeTimestamp = ConvertibleTimestamp::convert(
			TS_MW,
			ConvertibleTimestamp::time() - 1600
		);
		$middleFakeTimestamp = ConvertibleTimestamp::convert(
			TS_MW,
			ConvertibleTimestamp::time() - 400
		);
		$largestFakeTimestamp = ConvertibleTimestamp::now();
		// TODO: Test that the user agents are cut off at 10 + IP/XFF combos are cut off.
		return [
			'No rows in the result' => [
				new FakeResultWrapper( [] ),
				// Whether to display client hints
				false,
				// Expected ClientHintsReferenceIds used for lookup
				null,
				[
					'first' => [],
					'last' => [],
					'edits' => [],
					'ids' => [],
					'infosets' => [],
					'agentsets' => [],
					'clienthints' => [],
				]
			],
			'One row in the result with Client Hints disabled' => [
				new FakeResultWrapper( [
					[
						'user_text' => 'Test',
						'user' => 1,
						'ip' => '127.0.0.1',
						'xff' => null,
						'agent' => 'Testing user agent',
						'timestamp' => $largestFakeTimestamp
					],
				] ),
				// Whether to display client hints
				false,
				// Expected ClientHintsReferenceIds used for lookup
				null,
				[
					'first' => [ 'Test' => $largestFakeTimestamp ],
					'last' => [ 'Test' => $largestFakeTimestamp ],
					'edits' => [ 'Test' => 1 ],
					'ids' => [ 'Test' => 1 ],
					'infosets' => [ 'Test' => [ [ '127.0.0.1', null ] ] ],
					'agentsets' => [ 'Test' => [ 'Testing user agent' ] ],
					'clienthints' => [ 'Test' => new ClientHintsReferenceIds() ],
				]
			],
			'Multiple rows in the result with Client Hints display enabled' => [
				new FakeResultWrapper( [
					[
						'user_text' => 'Test',
						'user' => 1,
						'ip' => '127.0.0.1',
						'xff' => '125.6.5.4',
						'agent' => 'Testing user agent',
						'timestamp' => $largestFakeTimestamp,
						'client_hints_reference_id' => 1,
						'client_hints_reference_type' => UserAgentClientHintsManager::IDENTIFIER_CU_CHANGES,
					],
					[
						'user_text' => 'Testing',
						'user' => 2,
						'ip' => '127.0.0.2',
						'xff' => null,
						'agent' => 'Testing user agent',
						'timestamp' => $middleFakeTimestamp,
						'client_hints_reference_id' => 123,
						'client_hints_reference_type' => UserAgentClientHintsManager::IDENTIFIER_CU_CHANGES,
					],
					[
						'user_text' => 'Test',
						'user' => 1,
						'ip' => '127.0.0.2',
						'xff' => null,
						'agent' => 'Testing user agent1234',
						'timestamp' => $middleFakeTimestamp,
						'client_hints_reference_id' => 2,
						'client_hints_reference_type' => UserAgentClientHintsManager::IDENTIFIER_CU_LOG_EVENT,
					],
					[
						'user_text' => 'Test',
						'user' => 1,
						'ip' => '127.0.0.1',
						'xff' => null,
						'agent' => 'Testing user agent',
						'timestamp' => $smallestFakeTimestamp,
						'client_hints_reference_id' => 456,
						'client_hints_reference_type' => UserAgentClientHintsManager::IDENTIFIER_CU_PRIVATE_EVENT,
					]
				] ),
				// Whether to display client hints
				true,
				// Expected ClientHintsReferenceIds used for lookup
				new ClientHintsReferenceIds( [
					UserAgentClientHintsManager::IDENTIFIER_CU_CHANGES => [ 1, 123 ],
					UserAgentClientHintsManager::IDENTIFIER_CU_LOG_EVENT => [ 2 ],
					UserAgentClientHintsManager::IDENTIFIER_CU_PRIVATE_EVENT => [ 456 ],
				] ),
				[
					'first' => [ 'Test' => $smallestFakeTimestamp, 'Testing' => $middleFakeTimestamp ],
					'last' => [ 'Test' => $largestFakeTimestamp, 'Testing' => $middleFakeTimestamp ],
					'edits' => [ 'Test' => 3, 'Testing' => 1 ],
					'ids' => [ 'Test' => 1, 'Testing' => 2 ],
					'infosets' => [
						'Test' => [
							[ '127.0.0.1', '125.6.5.4' ], [ '127.0.0.2', null ], [ '127.0.0.1', null ]
						],
						'Testing' => [
							[ '127.0.0.2', null ]
						],
					],
					'agentsets' => [
						'Test' => [ 'Testing user agent', 'Testing user agent1234' ],
						'Testing' => [ 'Testing user agent' ]
					],
					'clienthints' => [
						'Test' => new ClientHintsReferenceIds( [
							UserAgentClientHintsManager::IDENTIFIER_CU_CHANGES => [ 1 ],
							UserAgentClientHintsManager::IDENTIFIER_CU_LOG_EVENT => [ 2 ],
							UserAgentClientHintsManager::IDENTIFIER_CU_PRIVATE_EVENT => [ 456 ],
						] ),
						'Testing' => new ClientHintsReferenceIds( [
							UserAgentClientHintsManager::IDENTIFIER_CU_CHANGES => [ 123 ],
						] ),
					]
				]
			],
		];
	}
}

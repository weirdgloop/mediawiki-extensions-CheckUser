<?php

namespace MediaWiki\CheckUser\Tests\Integration\Services;

use MediaWiki\CheckUser\CheckUserQueryInterface;
use MediaWiki\CheckUser\Services\CheckUserCentralIndexManager;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Wikimedia\IPUtils;

/**
 * @group CheckUser
 * @group Database
 * @covers \MediaWiki\CheckUser\Services\CheckUserCentralIndexManager
 */
class CheckUserCentralIndexManagerTest extends MediaWikiIntegrationTestCase {

	private static int $enwikiMapId;
	private static int $dewikiMapId;

	private function getObjectUnderTest(): CheckUserCentralIndexManager {
		return $this->getServiceContainer()->get( 'CheckUserCentralIndexManager' );
	}

	public function addDBData() {
		// Add some testing wiki_map rows
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'cuci_wiki_map' )
			->rows( [ [ 'ciwm_wiki' => 'enwiki' ], [ 'ciwm_wiki' => 'dewiki' ] ] )
			->caller( __METHOD__ )
			->execute();
		self::$enwikiMapId = $this->newSelectQueryBuilder()
			->select( 'ciwm_id' )
			->from( 'cuci_wiki_map' )
			->where( [ 'ciwm_wiki' => 'enwiki' ] )
			->caller( __METHOD__ )
			->fetchField();
		self::$dewikiMapId = $this->newSelectQueryBuilder()
			->select( 'ciwm_id' )
			->from( 'cuci_wiki_map' )
			->where( [ 'ciwm_wiki' => 'dewiki' ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	public function addTestingDataForPurging() {
		// Add some testing cuci_temp_edit rows
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'cuci_temp_edit' )
			->rows( [
				// Add some testing cuci_temp_edit rows which are expired
				[
					'cite_ip_hex' => IPUtils::toHex( '1.2.3.4' ), 'cite_ciwm_id' => self::$enwikiMapId,
					'cite_timestamp' => '20230405060708',
				],
				[
					'cite_ip_hex' => IPUtils::toHex( ':::' ), 'cite_ciwm_id' => self::$enwikiMapId,
					'cite_timestamp' => '20230406060708',
				],
				[
					'cite_ip_hex' => IPUtils::toHex( '1.2.3.6' ), 'cite_ciwm_id' => self::$dewikiMapId,
					'cite_timestamp' => '20230407060708',
				],
				// Add some testing cuci_temp_edit rows which are not expired
				[
					'cite_ip_hex' => IPUtils::toHex( '1.2.3.7' ), 'cite_ciwm_id' => self::$enwikiMapId,
					'cite_timestamp' => '20240405060708',
				],
				[
					'cite_ip_hex' => IPUtils::toHex( '1.2.3.8' ), 'cite_ciwm_id' => self::$enwikiMapId,
					'cite_timestamp' => '20240406060708',
				],
			] )
			->caller( __METHOD__ )
			->execute();
		// Add some testing cuci_user rows
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'cuci_user' )
			->rows( [
				// Add some testing cuci_user rows which are expired
				[ 'ciu_central_id' => 1, 'ciu_ciwm_id' => self::$enwikiMapId, 'ciu_timestamp' => '20230505060708' ],
				[ 'ciu_central_id' => 2, 'ciu_ciwm_id' => self::$enwikiMapId, 'ciu_timestamp' => '20230506060708' ],
				[ 'ciu_central_id' => 2, 'ciu_ciwm_id' => self::$dewikiMapId, 'ciu_timestamp' => '20230507060708' ],
				// Add some testing cuci_user rows which are not expired
				[ 'ciu_central_id' => 4, 'ciu_ciwm_id' => self::$enwikiMapId, 'ciu_timestamp' => '20240505060708' ],
				[ 'ciu_central_id' => 5, 'ciu_ciwm_id' => self::$enwikiMapId, 'ciu_timestamp' => '20240506060708' ],
			] )
			->caller( __METHOD__ )
			->execute();
		// Ensure that the DB is correctly set up for the tests.
		$this->assertSame( 5, $this->getRowCountForTable( 'cuci_user' ) );
		$this->assertSame( 5, $this->getRowCountForTable( 'cuci_temp_edit' ) );
	}

	private function getRowCountForTable( string $table ): int {
		return $this->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->table( $table )
			->caller( __METHOD__ )
			->fetchField();
	}

	/** @dataProvider providePurgeExpiredRows */
	public function testPurgeExpiredRows(
		$domain, $maxRowsToPurge, $expectedReturnValue, $expectedTimestampsInTempEditTable,
		$expectedTimestampsInUserTable
	) {
		$this->addTestingDataForPurging();
		$this->assertSame(
			$expectedReturnValue,
			$this->getObjectUnderTest()->purgeExpiredRows( '20231007060708', $domain, $maxRowsToPurge )
		);
		// Assert that the rows were correctly purged from the DB, and the other rows remain as is by looking for
		// the timestamps (as each row has a unique timestamp in our test data).
		$this->assertArrayEquals(
			$expectedTimestampsInTempEditTable,
			$this->newSelectQueryBuilder()
				->select( 'cite_timestamp' )
				->from( 'cuci_temp_edit' )
				->fetchFieldValues()
		);
		$this->assertArrayEquals(
			$expectedTimestampsInUserTable,
			$this->newSelectQueryBuilder()
				->select( 'ciu_timestamp' )
				->from( 'cuci_user' )
				->fetchFieldValues()
		);
	}

	public static function providePurgeExpiredRows() {
		return [
			'Database domain with no actions in the central index tables' => [
				'unknown', 100, 0,
				[ '20230405060708', '20230406060708', '20230407060708', '20240405060708', '20240406060708' ],
				[ '20230505060708', '20230506060708', '20230507060708', '20240505060708', '20240506060708' ],
			],
			'Data to purge but maximum rows is 1' => [
				'enwiki', 1, 2,
				[ '20230406060708', '20230407060708', '20240405060708', '20240406060708' ],
				[ '20230506060708', '20230507060708', '20240505060708', '20240506060708' ],
			],
			'Data to purge' => [
				'enwiki', 100, 4,
				[ '20230407060708', '20240405060708', '20240406060708' ],
				[ '20230507060708', '20240505060708', '20240506060708' ],
			],
		];
	}

	/** @dataProvider provideDomainIds */
	public function testGetWikiMapIdForDomainId( $domainId, $expectedWikiMapIdCallback ) {
		$this->assertSame(
			$expectedWikiMapIdCallback(),
			$this->getObjectUnderTest()->getWikiMapIdForDomainId( $domainId )
		);
	}

	public static function provideDomainIds() {
		return [
			'Pre-existing domain ID' => [ 'enwiki', fn () => static::$enwikiMapId ],
			'New domain ID' => [ 'jawiki', fn () => 3 ],
		];
	}

	/** @dataProvider provideVirtualDomainsMappingConfigValues */
	public function testGetWikiMapIdOnDefinedVirtualDomainsMapping( $virtualDomainsMappingConfig ) {
		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, $virtualDomainsMappingConfig );
		$this->testGetWikiMapIdForDomainId( 'hiwiki', fn () => 3 );
	}

	public static function provideVirtualDomainsMappingConfigValues() {
		return [
			'Virtual domains config has no value set for virtual-checkuser-global' => [ [] ],
			'Virtual domains config has virtual-checkuser-global set but no db set' => [
				[ CheckUserQueryInterface::VIRTUAL_GLOBAL_DB_DOMAIN => [] ],
			],
			'Virtual domains config has virtual-checkuser-global set with db as false' => [
				[ CheckUserQueryInterface::VIRTUAL_GLOBAL_DB_DOMAIN => [ 'db' => false ] ],
			],
		];
	}
}

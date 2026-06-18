<?php

namespace MWStake\MediaWiki\Component\DataStash\Tests;

use MWStake\MediaWiki\Component\DataStash\StashManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\ILoadBalancer;

\defined( 'DB_PRIMARY' ) || \define( 'DB_PRIMARY', 0 );
\defined( 'DB_REPLICA' ) || \define( 'DB_REPLICA', 1 );

/**
 * @coversDefaultClass \MWStake\MediaWiki\Component\DataStash\StashManager
 */
class StashManagerTest extends TestCase {

	private function makeUser( bool $registered = true ): MockObject {
		$user = $this->getMockBuilder( \MediaWiki\User\UserIdentity::class )
			->onlyMethods( [ 'getId', 'getName', 'isRegistered' ] )
			->getMockForAbstractClass();
		$user->method( 'getId' )->willReturn( 12 );
		$user->method( 'getName' )->willReturn( 'TestUser' );
		$user->method( 'isRegistered' )->willReturn( $registered );
		return $user;
	}

	private function makeCacheFactory( BagOStuff $cache ): MockObject {
		$cacheFactory = $this->getMockBuilder( \ObjectCacheFactory::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getLocalClusterInstance' ] )
			->getMock();
		$cacheFactory->method( 'getLocalClusterInstance' )->willReturn( $cache );
		return $cacheFactory;
	}

	private function makeSelectQueryBuilder( mixed $fetchFieldResult, bool $withCaller ): MockObject {
		$methods = [ 'field', 'from', 'where', 'fetchField' ];
		if ( $withCaller ) {
			$methods[] = 'caller';
		}
		$queryBuilder = $this->getMockBuilder( \stdClass::class )
			->addMethods( $methods )
			->getMock();

		$queryBuilder->method( 'field' )->willReturnSelf();
		$queryBuilder->method( 'from' )->willReturnSelf();
		$queryBuilder->method( 'where' )->willReturnSelf();
		if ( $withCaller ) {
			$queryBuilder->method( 'caller' )->willReturnSelf();
		}
		$queryBuilder->method( 'fetchField' )->willReturn( $fetchFieldResult );
		return $queryBuilder;
	}

	private function makeManager(
		BagOStuff $cache,
		ILoadBalancer $loadBalancer,
		LoggerInterface $logger
	): StashManager {
		return new StashManager(
			$loadBalancer,
			$this->makeCacheFactory( $cache ),
			$logger
		);
	}

	private function mockPrimaryReplicaConnections(
		ILoadBalancer $loadBalancer,
		object $dbPrimary,
		object $dbReplica
	): void {
		$loadBalancer->method( 'getConnection' )
			->willReturnCallback( static function ( $index ) use ( $dbPrimary, $dbReplica ) {
				if ( $index === DB_PRIMARY ) {
					return $dbPrimary;
				}
				if ( $index === DB_REPLICA ) {
					return $dbReplica;
				}
				return null;
			} );
	}

	/** @covers ::get */
	public function testGetReturnsDefaultForAnonymousUser(): void {
		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->never() )->method( 'get' );

		$loadBalancer = $this->createMock( ILoadBalancer::class );
		$loadBalancer->expects( $this->never() )->method( 'getConnection' );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' );

		$manager = $this->makeManager( $cache, $loadBalancer, $logger );

		$this->assertSame(
			'default-value',
			$manager->get( 'feature-key', $this->makeUser( false ), 'default-value' )
		);
	}

	/** @covers ::get */
	public function testGetUsesCachedValueWithoutDatabaseRead(): void {
		$user = $this->makeUser();
		$cached = [ 'a' => 1 ];
		$cacheKey = 'local-cache-key';

		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->once() )->method( 'makeKey' )->willReturn( $cacheKey );
		$cache->expects( $this->once() )->method( 'get' )->with( $cacheKey )->willReturn( $cached );
		$cache->expects( $this->never() )->method( 'set' );

		$loadBalancer = $this->createMock( ILoadBalancer::class );
		$loadBalancer->expects( $this->never() )->method( 'getConnection' );

		$logger = $this->createMock( LoggerInterface::class );
		$manager = $this->makeManager( $cache, $loadBalancer, $logger );

		$this->assertSame( $cached, $manager->get( 'feature-key', $user ) );
	}

	/** @covers ::get */
	public function testGetReadsDatabaseOnCacheMissAndStoresResultInCache(): void {
		$user = $this->makeUser();
		$cacheKey = 'local-cache-key';
		$dbData = [ 'state' => 'ok' ];

		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->once() )->method( 'makeKey' )->willReturn( $cacheKey );
		$cache->expects( $this->once() )->method( 'get' )->with( $cacheKey )->willReturn( null );
		$cache->expects( $this->once() )->method( 'set' )->with(
			$cacheKey,
			$dbData,
			\Wikimedia\LightweightObjectStore\ExpirationAwareness::TTL_MONTH
		);

		$selectQueryBuilder = $this->makeSelectQueryBuilder( \json_encode( $dbData ), true );
		$dbPrimary = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'newSelectQueryBuilder' ] )
			->getMock();
		$dbPrimary->method( 'newSelectQueryBuilder' )->willReturn( $selectQueryBuilder );

		$loadBalancer = $this->createMock( ILoadBalancer::class );
		$loadBalancer->expects( $this->once() )
			->method( 'getConnection' )
			->with( DB_PRIMARY )
			->willReturn( $dbPrimary );

		$logger = $this->createMock( LoggerInterface::class );
		$manager = $this->makeManager( $cache, $loadBalancer, $logger );

		$this->assertSame( $dbData, $manager->get( 'feature-key', $user ) );
	}

	/** @covers ::get */
	public function testGetReturnsDefaultWhenCacheAndDatabaseMiss(): void {
		$user = $this->makeUser();
		$cacheKey = 'local-cache-key';

		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->once() )->method( 'makeKey' )->willReturn( $cacheKey );
		$cache->expects( $this->once() )->method( 'get' )->with( $cacheKey )->willReturn( null );
		$cache->expects( $this->never() )->method( 'set' );

		$selectQueryBuilder = $this->makeSelectQueryBuilder( false, true );
		$dbPrimary = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'newSelectQueryBuilder' ] )
			->getMock();
		$dbPrimary->method( 'newSelectQueryBuilder' )->willReturn( $selectQueryBuilder );

		$loadBalancer = $this->createMock( ILoadBalancer::class );
		$loadBalancer->method( 'getConnection' )->with( DB_PRIMARY )->willReturn( $dbPrimary );

		$logger = $this->createMock( LoggerInterface::class );
		$manager = $this->makeManager( $cache, $loadBalancer, $logger );

		$this->assertSame( [ 'fallback' => true ], $manager->get( 'feature-key', $user, [ 'fallback' => true ] ) );
	}

	/** @covers ::getGlobal */
	public function testGetGlobalUsesGlobalCacheKeyAndReturnsFallbackForAnonUser(): void {
		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->never() )->method( 'makeGlobalKey' );
		$cache->expects( $this->never() )->method( 'get' );

		$loadBalancer = $this->createMock( ILoadBalancer::class );
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' );

		$manager = $this->makeManager( $cache, $loadBalancer, $logger );

		$this->assertSame(
			[ 'fallback' => true ],
			$manager->getGlobal( 'feature-key', $this->makeUser( false ), [ 'fallback' => true ] )
		);
	}

	/** @covers ::stash */
	public function testStashReturnsEarlyForAnonymousUser(): void {
		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->never() )->method( 'set' );

		$loadBalancer = $this->createMock( ILoadBalancer::class );
		$loadBalancer->expects( $this->never() )->method( 'getConnection' );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' );

		$manager = $this->makeManager( $cache, $loadBalancer, $logger );
		$manager->stash( 'feature-key', [ 'x' => 1 ], $this->makeUser( false ) );

		$this->addToAssertionCount( 1 );
	}

	/** @covers ::stash */
	public function testStashSkipsWriteWhenPayloadHashIsUnchanged(): void {
		$user = $this->makeUser();
		$value = [ 'x' => 1 ];
		$existingSha1 = \sha1( \json_encode( $value ) );

		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->never() )->method( 'set' );

		$shaQueryBuilder = $this->makeSelectQueryBuilder( $existingSha1, false );
		$dbReplica = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'newSelectQueryBuilder' ] )
			->getMock();
		$dbReplica->expects( $this->once() )->method( 'newSelectQueryBuilder' )->willReturn( $shaQueryBuilder );

		$loadBalancer = $this->createMock( ILoadBalancer::class );
		$loadBalancer->expects( $this->once() )
			->method( 'getConnection' )
			->with( DB_REPLICA )
			->willReturn( $dbReplica );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->never() )->method( 'error' );
		$logger->expects( $this->never() )->method( 'info' );

		$manager = $this->makeManager( $cache, $loadBalancer, $logger );
		$manager->stash( 'feature-key', $value, $user );

		$this->addToAssertionCount( 1 );
	}

	/** @covers ::stash */
	public function testStashInsertsNewRowAndInvalidatesLocalCache(): void {
		$user = $this->makeUser();
		$cacheKey = 'local-cache-key';
		$value = [ 'x' => 1, 'y' => 2 ];

		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->once() )->method( 'makeKey' )->willReturn( $cacheKey );
		$cache->expects( $this->once() )->method( 'set' )->with( $cacheKey, null, 1 );

		$shaQueryBuilder = $this->makeSelectQueryBuilder( null, false );
		$wikiQueryBuilder = $this->makeSelectQueryBuilder( null, false );

		$insertQueryBuilder = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'insert', 'row', 'caller', 'execute' ] )
			->getMock();
		$insertQueryBuilder->method( 'insert' )->willReturnSelf();
		$insertQueryBuilder->method( 'row' )->willReturnSelf();
		$insertQueryBuilder->method( 'caller' )->willReturnSelf();
		$insertQueryBuilder->expects( $this->once() )->method( 'execute' );

		$dbReplica = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'newSelectQueryBuilder' ] )
			->getMock();
		$dbReplica->expects( $this->exactly( 2 ) )->method( 'newSelectQueryBuilder' )
			->willReturnOnConsecutiveCalls( $shaQueryBuilder, $wikiQueryBuilder );

		$dbPrimary = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'newInsertQueryBuilder', 'timestamp' ] )
			->getMock();
		$dbPrimary->method( 'newInsertQueryBuilder' )->willReturn( $insertQueryBuilder );
		$dbPrimary->method( 'timestamp' )->willReturn( '20260618120000' );

		$loadBalancer = $this->createMock( ILoadBalancer::class );
		$this->mockPrimaryReplicaConnections( $loadBalancer, $dbPrimary, $dbReplica );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->exactly( 2 ) )->method( 'info' );
		$logger->expects( $this->never() )->method( 'error' );

		$manager = $this->makeManager( $cache, $loadBalancer, $logger );
		$manager->stash( 'feature-key', $value, $user );

		$this->addToAssertionCount( 1 );
	}

	/** @covers ::stashGlobally */
	public function testStashGloballyUpdatesExistingRowAndInvalidatesGlobalCache(): void {
		$user = $this->makeUser();
		$cacheKey = 'global-cache-key';
		$value = [ 'x' => 'new-value' ];

		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->once() )->method( 'makeGlobalKey' )->willReturn( $cacheKey );
		$cache->expects( $this->once() )->method( 'set' )->with( $cacheKey, null, 1 );

		$shaQueryBuilder = $this->makeSelectQueryBuilder( 'old-hash', false );
		$wikiQueryBuilder = $this->makeSelectQueryBuilder( '__global__', false );

		$updateQueryBuilder = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'update', 'set', 'where', 'caller', 'execute' ] )
			->getMock();
		$updateQueryBuilder->method( 'update' )->willReturnSelf();
		$updateQueryBuilder->method( 'set' )->willReturnSelf();
		$updateQueryBuilder->method( 'where' )->willReturnSelf();
		$updateQueryBuilder->method( 'caller' )->willReturnSelf();
		$updateQueryBuilder->expects( $this->once() )->method( 'execute' );

		$dbReplica = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'newSelectQueryBuilder' ] )
			->getMock();
		$dbReplica->expects( $this->exactly( 2 ) )->method( 'newSelectQueryBuilder' )
			->willReturnOnConsecutiveCalls( $shaQueryBuilder, $wikiQueryBuilder );

		$dbPrimary = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'newUpdateQueryBuilder', 'timestamp' ] )
			->getMock();
		$dbPrimary->method( 'newUpdateQueryBuilder' )->willReturn( $updateQueryBuilder );
		$dbPrimary->method( 'timestamp' )->willReturn( '20260618120000' );

		$loadBalancer = $this->createMock( ILoadBalancer::class );
		$this->mockPrimaryReplicaConnections( $loadBalancer, $dbPrimary, $dbReplica );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->exactly( 2 ) )->method( 'info' );
		$logger->expects( $this->never() )->method( 'error' );

		$manager = $this->makeManager( $cache, $loadBalancer, $logger );
		$manager->stashGlobally( 'feature-key', $value, $user );

		$this->addToAssertionCount( 1 );
	}

	/** @covers ::stashGlobally */
	public function testStashLogsErrorWhenWriteThrows(): void {
		$user = $this->makeUser();
		$value = [ 'x' => 1 ];

		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->never() )->method( 'set' );

		$shaQueryBuilder = $this->makeSelectQueryBuilder( null, false );
		$wikiQueryBuilder = $this->makeSelectQueryBuilder( '__global__', false );

		$updateQueryBuilder = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'update', 'set', 'where', 'caller', 'execute' ] )
			->getMock();
		$updateQueryBuilder->method( 'update' )->willReturnSelf();
		$updateQueryBuilder->method( 'set' )->willReturnSelf();
		$updateQueryBuilder->method( 'where' )->willReturnSelf();
		$updateQueryBuilder->method( 'caller' )->willReturnSelf();
		$updateQueryBuilder->method( 'execute' )->willThrowException( new \RuntimeException( 'write failed' ) );

		$dbReplica = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'newSelectQueryBuilder' ] )
			->getMock();
		$dbReplica->expects( $this->exactly( 2 ) )->method( 'newSelectQueryBuilder' )
			->willReturnOnConsecutiveCalls( $shaQueryBuilder, $wikiQueryBuilder );

		$dbPrimary = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'newUpdateQueryBuilder', 'timestamp' ] )
			->getMock();
		$dbPrimary->method( 'newUpdateQueryBuilder' )->willReturn( $updateQueryBuilder );
		$dbPrimary->method( 'timestamp' )->willReturn( '20260618120000' );

		$loadBalancer = $this->createMock( ILoadBalancer::class );
		$this->mockPrimaryReplicaConnections( $loadBalancer, $dbPrimary, $dbReplica );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'error' );
		$logger->expects( $this->never() )->method( 'info' );

		$manager = $this->makeManager( $cache, $loadBalancer, $logger );
		$manager->stashGlobally( 'feature-key', $value, $user );

		$this->addToAssertionCount( 1 );
	}
}

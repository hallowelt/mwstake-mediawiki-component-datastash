<?php

namespace MWStake\MediaWiki\Component\DataStash;

use JsonSerializable;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Psr\Log\LoggerInterface;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\ILoadBalancer;

class StashManager {

	private const GLOBAL_STASH_ID = '__global__';

	/** @var string */
	private string $wikiId;

	/** @var BagOStuff */
	private BagOStuff $cache;

	public function __construct(
		private readonly ILoadBalancer $lb,
		private readonly \ObjectCacheFactory $cacheFactory,
		private readonly LoggerInterface $logger
	) {
		$this->wikiId = WikiMap::getCurrentWikiId();
		$this->cache = $this->cacheFactory->getLocalClusterInstance();
	}

	/**
	 * Stash data for this wiki
	 *
	 * @param string $key
	 * @param JsonSerializable|array $value
	 * @param UserIdentity $forUser
	 * @return void
	 */
	public function stash( string $key, JsonSerializable|array $value, UserIdentity $forUser ) {
		$this->doStash( $key, $value, $forUser, $this->wikiId );
	}

	/**
	 * Stash data globally
	 *
	 * @param string $key
	 * @param JsonSerializable|array $value
	 * @param UserIdentity $forUser
	 * @return void
	 */
	public function stashGlobally( string $key, JsonSerializable|array $value, UserIdentity $forUser ) {
		$this->doStash( $key, $value, $forUser, null );
	}

	/**
	 * @param string $key
	 * @param UserIdentity $forUser
	 * @param mixed|null $default
	 * @return false|mixed
	 */
	public function get( string $key, UserIdentity $forUser, $default = null ) {
		if ( !$this->assertValidUser( $forUser ) ) {
			return $default;
		}
		$cacheKey = $this->getCacheKey( $key, $forUser, $this->wikiId );
		return $this->getCached( $key, $forUser, $this->wikiId, $cacheKey ) ?? $default;
	}

	/**
	 * @param string $key
	 * @param UserIdentity $forUser
	 * @param mixed|null $default
	 * @return array|null
	 */
	public function getGlobal( string $key, UserIdentity $forUser, $default = null ): ?array {
		if ( !$this->assertValidUser( $forUser ) ) {
			return $default;
		}
		$cacheKey = $this->getCacheKey( $key, $forUser, null );
		return $this->getCached( $key, $forUser, null, $cacheKey ) ?? $default;
	}

	/**
	 * @param string $key
	 * @param UserIdentity $forUser
	 * @param string|null $forWiki
	 * @param string $cacheKey
	 * @return array|null
	 */
	private function getCached( string $key, UserIdentity $forUser, ?string $forWiki, string $cacheKey ): ?array {
		$cachedValue = $this->cache->get( $cacheKey );
		if ( !is_array( $cachedValue ) ) {
			$dbValue = $this->getFromDb( $key, $forUser, $forWiki );
			if ( $dbValue === null ) {
				return null;
			}
			$this->cache->set( $cacheKey, $dbValue, ExpirationAwareness::TTL_MONTH );
			return $dbValue;
		}
		return $cachedValue;
	}

	/**
	 * @param string $key
	 * @param UserIdentity $forUser
	 * @param string|null $forWiki
	 * @return false|mixed
	 */
	private function getFromDb( string $key, UserIdentity $forUser, ?string $forWiki ): ?array {
		$db = $this->lb->getConnection( DB_PRIMARY );
		$row = $db->newSelectQueryBuilder()
			->field( 'mwds_data' )
			->from( 'mws_data_stash' )
			->where( [
				'mwds_key' => $key,
				'mwds_owner' => $forUser->getId(),
				'mwds_owner_type' => 'user',
				'mwds_wiki_id' => $forWiki ?: self::GLOBAL_STASH_ID,
			] )
			->caller( __METHOD__ )
			->fetchField();

		if ( !$row ) {
			return null;
		}

		$json = json_decode( $row, true );
		return is_array( $json ) ? $json : false;
	}

	/**
	 * @param string $key
	 * @param JsonSerializable|array $value
	 * @param UserIdentity $forUser
	 * @param string|null $forWiki
	 * @return void
	 */
	private function doStash( string $key, JsonSerializable|array $value, UserIdentity $forUser, ?string $forWiki ) {
		if ( !$this->assertValidUser( $forUser ) ) {
			return;
		}
		$data = json_encode( $value );
		$sha1 = sha1( $data );

		if ( $this->getSha1( $key, $forUser, $forWiki ) === $sha1 ) {
			// Already set - no change
			return;
		}
		$method = __METHOD__;

		DeferredUpdates::addCallableUpdate( function () use ( $key, $data, $sha1, $forUser, $forWiki, $method ) {
			$db = $this->lb->getConnection( DB_PRIMARY );
			// Get wiki_id of the currently stored value, local wiki or global
			$storedWikiId = $this->getField( $key, $forUser, $forWiki, 'mwds_wiki_id' );
			try {
				if ( $storedWikiId ) {
					$db->newUpdateQueryBuilder()
						->update( 'mws_data_stash' )
						->set( [
							'mwds_data' => $data,
							'mwds_sha1' => $sha1,
							'mwds_touched' => $db->timestamp(),
						] )
						->where( [
							'mwds_key' => $key,
							'mwds_owner' => $forUser->getId(),
							'mwds_owner_type' => 'user',
							'mwds_wiki_id' => $forWiki ?: self::GLOBAL_STASH_ID
						] )
						->caller( $method )
						->execute();
				} else {
					$db->newInsertQueryBuilder()
						->insert( 'mws_data_stash' )
						->row( [
							'mwds_key' => $key,
							'mwds_owner' => $forUser->getId(),
							'mwds_owner_type' => 'user',
							'mwds_wiki_id' => $forWiki ?: self::GLOBAL_STASH_ID,
							'mwds_data' => $data,
							'mwds_sha1' => $sha1,
							'mwds_touched' => $db->timestamp()
						] )
						->caller( $method )
						->execute();
				}

				$this->logger->info( 'Set stash for key "{key}" and user "{userName}" on wiki "{wikiId}"', [
					'key' => $key,
					'userName' => $forUser->getName(),
					'wikiId' => $forWiki ?: self::GLOBAL_STASH_ID
				] );

				$this->invalidateCache(
					$forWiki ?
						$this->getCacheKey( $key, $forUser, $forWiki ) :
						$this->getCacheKey( $key, $forUser, null )
				);
			} catch ( \Throwable $throwable ) {
				$this->logger->error(
					'Error setting stash for key "{key}" and user "{userName}" on wiki "{wikiId}": {error}',
					[
						'key' => $key,
						'userName' => $forUser->getName(),
						'wikiId' => $forWiki ?: self::GLOBAL_STASH_ID,
						'error' => $throwable->getMessage()
					]
				);
			}
		} );
	}

	/**
	 * @param string $key
	 * @param UserIdentity $forUser
	 * @param string|null $forWiki
	 * @return string
	 */
	private function getCacheKey( string $key, UserIdentity $forUser, ?string $forWiki ): string {
		if ( $forWiki === null ) {
			return $this->cache->makeGlobalKey( 'mws-datastash', $key, $forUser->getName() );
		}
		return $this->cache->makeKey( 'mws-datastash', $key, $forUser->getName() );
	}

	/**
	 * @param string $key
	 * @param UserIdentity $forUser
	 * @param string|null $forWiki
	 * @return string|null
	 */
	private function getSha1( string $key, UserIdentity $forUser, ?string $forWiki ): ?string {
		return $this->getField( $key, $forUser, $forWiki, 'mwds_sha1' );
	}

	/**
	 * @param string $key
	 * @param UserIdentity $forUser
	 * @param string|null $forWiki
	 * @param string $field
	 * @return string|null
	 */
	private function getField( string $key, UserIdentity $forUser, ?string $forWiki, string $field ): ?string {
		$res = $this->lb->getConnection( DB_REPLICA )->newSelectQueryBuilder()
			->field( $field )
			->from( 'mws_data_stash' )
			->where( [
				'mwds_owner' => $forUser->getId(),
				'mwds_owner_type' => 'user',
				'mwds_key' => $key,
				'mwds_wiki_id' => $forWiki ?: self::GLOBAL_STASH_ID
			] )
			->fetchField();
		return $res ?: null;
	}

	/**
	 * @param UserIdentity $forUser
	 * @return bool
	 */
	private function assertValidUser( UserIdentity $forUser ): bool {
		if ( !$forUser->isRegistered() ) {
			$this->logger->warning( 'Attempted to stash data for an anon user' );
			return false;
		}
		return true;
	}

	/**
	 * @param string $cacheKey
	 * @return void
	 */
	private function invalidateCache( string $cacheKey ) {
		$this->cache->set( $cacheKey, null, 1 );

		$this->logger->info( 'Invalidated cache for key "{cacheKey}"', [
			'cacheKey' => $cacheKey
		] );
	}
}

<?php

namespace MWStake\MediaWiki\Component\DataStash;

use JsonSerializable;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\ILoadBalancer;

class StashManager {

	private const GLOBAL_STASH_ID = '__global__';

	/** @var string */
	private string $wikiId;

	public function __construct(
		private readonly ILoadBalancer $lb,
		private readonly \ObjectCacheFactory $cacheFactory,
		private readonly LoggerInterface $logger
	) {
		$this->wikiId = WikiMap::getCurrentWikiId();
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

	public function get( string $key, UserIdentity $forUser ) {
		$localCC = $this->getCacheKey( $key, $forUser, $this->wikiId );
		$globalCC = $this->getCacheKey( $key, $forUser, null );

		$cache = $this->cacheFactory->getLocalClusterInstance();
		$values = $cache->getMulti( [ $localCC, $globalCC ] );
		$x = 0;
	}

	/**
	 * @param string $key
	 * @param UserIdentity $forUser
	 * @return bool
	 */
	public function has( string $key, UserIdentity $forUser ): bool {
		$this->assertValidUser( $forUser );
		// Has locally, or has globally
		$sha1 = $this->getSha1( $key, $forUser, $this->wikiId ) ?? $this->getSha1( $key, $forUser, null );
		return $sha1 !== null;
	}

	/**
	 * @param string $key
	 * @param JsonSerializable|array $value
	 * @param UserIdentity $forUser
	 * @param string|null $forWiki
	 * @return void
	 */
	private function doStash( string $key, JsonSerializable|array $value, UserIdentity $forUser, ?string $forWiki ) {
		$this->assertValidUser( $forUser );
		$data = json_encode( $value );
		$sha1 = sha1( $data );

		if ( $this->getSha1( $key, $forUser, $forWiki ) === $sha1 ) {
			// Already set - no change
			return;
		}


		DeferredUpdates::addCallableUpdate( function() {
			$db = $this->lb->getConnection( DB_PRIMARY );
			// Get wiki_id of the currently stored value, local wiki or global
			$storedWikiId =
				$this->getField( $key, $forUser, $this->wikiId, 'mwds_wiki_id' ) ??
				$this->getField( $key, $forUser, null, 'mwds_wiki_id' );

			try {
				if ( $storedWikiId ) {
					$db->newUpdateQueryBuilder()
						->update( 'mws_data_stash' )
						->set( [
							'mwds_data' => $data,
							'mwds_sha1' => $sha1,
							'mwds_touched' => $db->timestamp(),
							'mwds_wiki_id' => $forWiki ?: self::GLOBAL_STASH_ID
						] )
						->where( [
							'mwds_key' => $key,
							'mwds_owner' => $forUser->getId(),
							'mwds_owner_type' => 'user',
							'mwds_wiki_id' => $storedWikiId
						] )
						->caller( __METHOD__ )
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
						->caller( __METHOD__ )
						->execute();
				}

				$this->logger->info( 'Set stash for key "{key}" and user "{userName}" on wiki "{wikiId}"', [
					'key' => $key,
					'userName' => $forUser->getName(),
					'wikiId' => $forWiki ?: self::GLOBAL_STASH_ID
				] );

				$this->invalidateCache( $key, $forUser );
			} catch ( \Throwable $throwable ) {
				$this->logger->error( 'Error setting stash for key "{key}" and user "{userName}" on wiki "{wikiId}": {error}', [
					'key' => $key,
					'userName' => $forUser->getName(),
					'wikiId' => $forWiki ?: self::GLOBAL_STASH_ID,
					'error' => $throwable->getMessage()
				] );
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
		$cache = $this->cacheFactory->getLocalServerInstance();
		if ( $forWiki === null ) {
			return $cache->makeGlobalKey( 'mws-datastash', $key, $forUser->getName() );
		}
		return $cache->makeKey( 'mws-datastash', $key, $forUser->getName() );
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
			->select( $field )
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
	 * @return void
	 */
	private function assertValidUser( UserIdentity $forUser ): void {
		if ( !$forUser->isRegistered() ) {
			throw new \InvalidArgumentException( 'Only registered users can have stashed data' );
		}
	}

	/**
	 * @param string $key
	 * @param UserIdentity $forUser
	 * @return void
	 */
	private function invalidateCache( string $key, UserIdentity $forUser) {
		$cacheKeyGlobal = $this->getCacheKey( $key, $forUser, null );
		$cacheKeyLocal = $this->getCacheKey( $key, $forUser, $this->wikiId );

		$cache = $this->cacheFactory->getLocalServerInstance();
		$cache->delete( $cacheKeyGlobal );
		$cache->delete( $cacheKeyLocal );
	}
}
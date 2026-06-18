<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\DataStash\StashManager;

return [
	'MWStake.DataStash' => static function ( MediaWikiServices $services ) {
		return new StashManager(
			$services->getDBLoadBalancer(),
			$services->getObjectCacheFactory(),
			LoggerFactory::getInstance( 'MWStake.DataStash' )
		);
	}
];

<?php

use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\DataStash\StashManager;

return [
	'MWStake.DataStash' => static function ( MediaWikiServices $services ) {
		return new StashManager(
			$services->getDBLoadBalancer(),
			$services->getObjectCacheFactory(),
			\MediaWiki\Logger\LoggerFactory::getInstance( 'MWStake.DataStash' )
		);
	}
];

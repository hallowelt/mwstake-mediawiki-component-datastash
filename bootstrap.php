<?php

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_DATASTASH_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_DATASTASH_VERSION', '1.0.0' );

MWStake\MediaWiki\ComponentLoader\Bootstrapper::getInstance()
->register( 'datastash', static function () {
	$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/ServiceWiring.php';

	$GLOBALS['wgExtensionFunctions'][] = static function () {
		$hookContainer = \MediaWiki\MediaWikiServices::getInstance()->getHookContainer();
		$hookContainer->register( 'LoadExtensionSchemaUpdates', static function ( $updater ) {
			if ( !$updater instanceof DatabaseUpdater ) {
				throw new LogicException( "LoadExtensionSchemaUpdates hook must be called with a DatabaseUpdater" );
			}

			$dbType = $updater->getDB()->getType();
			$updater->addExtensionTable(
				'mws_data_stash',
				__DIR__ . "/db/$dbType/data-stash.sql"
			);
		} );
	};

	$GLOBALS['wgRestAPIAdditionalRouteFiles'][] = wfRelativePath( __DIR__ . '/route.json', $GLOBALS['IP'] );
} );

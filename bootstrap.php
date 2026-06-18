<?php

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_DATASTASH_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_DATASTASH_VERSION', '1.0.0' );

MWStake\MediaWiki\ComponentLoader\Bootstrapper::getInstance()
->register( 'datastash', static function () {
	$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/ServiceWiring.php';
} );

<?php

namespace MWStake\MediaWiki\Component\DataStash\Rest;

use MediaWiki\Rest\SimpleHandler;
use MWStake\MediaWiki\Component\DataStash\StashManager;
use Wikimedia\ParamValidator\ParamValidator;

class GetStashDataHandler extends SimpleHandler {

	/**
	 * @param StashManager $stashManager
	 */
	public function __construct(
		private readonly StashManager $stashManager
	) {
	}

	/**
	 * @return \MediaWiki\Rest\Response|mixed
	 */
	public function execute() {
		$params = $this->getValidatedParams();
		$user = \RequestContext::getMain()->getUser();

		if ( $params['global'] ) {
			$data = $this->stashManager->getGlobal( $params['key'], $user );
		} else {
			$data = $this->stashManager->get( $params['key'], $user );
		}

		if ( $data === null ) {
			return $this->getResponseFactory()->createNoContent();
		}

		return $this->getResponseFactory()->createJson( $data );
	}

	/**
	 * @return array[]
	 */
	public function getParamSettings() {
		return [
			'key' => [
				static::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true
			],
			'global' => [
				static::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false
			]
		];
	}
}

<?php

namespace MWStake\MediaWiki\Component\DataStash\Rest;

use MediaWiki\Rest\SimpleHandler;
use MWStake\MediaWiki\Component\DataStash\StashManager;
use Wikimedia\ParamValidator\ParamValidator;

class SetStashDataHandler extends SimpleHandler {

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
		$body = $this->getValidatedBody();
		$user = \RequestContext::getMain()->getUser();

		$key = $params['key'];
		if ( $body['global'] ) {
			$this->stashManager->stashGlobally( $key, $body['data'], $user );
		} else {
			$this->stashManager->stash( $key, $body['data'], $user );
		}

		return $this->getResponseFactory()->createNoContent();
	}

	/**
	 * @return array[]
	 */
	public function getBodyParamSettings(): array {
		return [
			'data' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'array',
				ParamValidator::PARAM_REQUIRED => true
			],
			'global' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false
			]
		];
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
			]
		];
	}
}

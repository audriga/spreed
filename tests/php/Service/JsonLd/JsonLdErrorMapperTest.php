<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Service\JsonLd;

use OCA\Talk\Service\JsonLd\JsonLdErrorMapper;
use OCP\AppFramework\Http;
use Test\TestCase;

class JsonLdErrorMapperTest extends TestCase {
	private JsonLdErrorMapper $mapper;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = new JsonLdErrorMapper();
	}

	public function testUnauthorized(): void {
		$target = 'https://cloud.example.com/apps/spreed/lda/message/1042';

		$result = $this->mapper->unauthorized($target);

		$this->assertSame([
			'@context' => 'https://schema.org/',
			'@type' => 'LDAActionResponse',
			'status' => 'error',
			'description' => 'Authentication required',
			'potentialAction' => [
				'@type' => 'LoginAction',
				'type' => 'basic',
				'target' => $target,
			],
		], $result);
	}

	public function testErrorNotFound(): void {
		$result = $this->mapper->error(
			Http::STATUS_NOT_FOUND,
			'Message not found',
			'/apps/spreed/lda/message/99999',
		);

		$this->assertSame([
			'@context' => 'https://schema.org/',
			'@type' => 'ErrorAction',
			'name' => 'Not Found',
			'description' => 'Message not found',
			'requestPath' => '/apps/spreed/lda/message/99999',
		], $result);
	}

	public function testErrorForbidden(): void {
		$result = $this->mapper->error(
			Http::STATUS_FORBIDDEN,
			'Access denied to room',
			'/apps/spreed/lda/room/secret',
		);

		$this->assertSame([
			'@context' => 'https://schema.org/',
			'@type' => 'ErrorAction',
			'name' => 'Forbidden',
			'description' => 'Access denied to room',
			'requestPath' => '/apps/spreed/lda/room/secret',
		], $result);
	}

	public function testErrorInternalServerError(): void {
		$result = $this->mapper->error(
			Http::STATUS_INTERNAL_SERVER_ERROR,
			'Database error',
			'/apps/spreed/lda/thread/1042',
		);

		$this->assertSame('Internal Server Error', $result['name']);
	}
}

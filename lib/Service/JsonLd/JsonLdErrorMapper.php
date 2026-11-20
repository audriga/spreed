<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Service\JsonLd;

use OCP\AppFramework\Http;

class JsonLdErrorMapper {
	private const CONTEXT = 'https://schema.org/';

	/**
	 * @return array<string, mixed>
	 */
	public function unauthorized(string $target): array {
		return [
			'@context' => self::CONTEXT,
			'@type' => 'LDAActionResponse',
			'status' => 'error',
			'description' => 'Authentication required',
			'potentialAction' => [
				'@type' => 'LoginAction',
				'type' => 'basic',
				'target' => $target,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function error(int $status, string $description, string $requestPath): array {
		return [
			'@context' => self::CONTEXT,
			'@type' => 'ErrorAction',
			'name' => match ($status) {
				Http::STATUS_BAD_REQUEST => 'Bad Request',
				Http::STATUS_FORBIDDEN => 'Forbidden',
				Http::STATUS_NOT_FOUND => 'Not Found',
				Http::STATUS_INTERNAL_SERVER_ERROR => 'Internal Server Error',
				default => 'Error',
			},
			'description' => $description,
			'requestPath' => $requestPath,
		];
	}
}

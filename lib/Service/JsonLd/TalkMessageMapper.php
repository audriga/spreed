<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Service\JsonLd;

class TalkMessageMapper {
	private const CONTEXT = 'https://schema.org/';

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function mapMessage(array $data, string $messageUrl, string $viewUrl): array {
		$message = $this->buildMessage($data);
		$message['potentialAction'] = [
			$this->viewAction($viewUrl),
			$this->refreshAction($messageUrl),
		];
		return $message;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function messageItem(array $data, string $viewUrl): array {
		$item = $this->buildMessage($data);
		unset($item['@context']);
		$item['potentialAction'] = [$this->viewAction($viewUrl)];
		return $item;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	public function mapThread(
		string $id,
		string $headline,
		array $items,
		int $totalCount,
		string $viewUrl,
		string $refreshUrl,
		string $commentUrl,
		?string $previousUrl = null,
		?string $nextUrl = null,
	): array {
		return [
			'@context' => self::CONTEXT,
			'@type' => 'Conversation',
			'id' => $id,
			'headline' => $headline,
			'numberOfItems' => count($items),
			'totalCount' => $totalCount,
			'itemListElement' => $items,
			'potentialAction' => [
				$this->viewAction($viewUrl),
				$this->refreshAction($refreshUrl),
				$this->commentAction($commentUrl),
				...$this->navigateActions($previousUrl, $nextUrl),
			],
		];
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	public function mapRoom(
		string $token,
		string $name,
		?string $image,
		array $items,
		int $totalCount,
		string $viewUrl,
		string $refreshUrl,
		string $commentUrl,
		?string $previousUrl = null,
		?string $nextUrl = null,
	): array {
		return [
			'@context' => self::CONTEXT,
			'@type' => 'Conversation',
			'id' => $token,
			'name' => $name,
			'image' => $image,
			'numberOfItems' => count($items),
			'totalCount' => $totalCount,
			'itemListElement' => $items,
			'potentialAction' => [
				$this->viewAction($viewUrl),
				$this->refreshAction($refreshUrl),
				$this->commentAction($commentUrl),
				...$this->navigateActions($previousUrl, $nextUrl),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function mapActionResponse(string $id): array {
		return [
			'@context' => self::CONTEXT,
			'@type' => 'LDAActionResponse',
			'status' => 'success',
			'id' => $id,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function buildMessage(array $data): array {
		if (($data['messageType'] ?? '') === 'json-ld-card' && isset($data['jsonld'])) {
			$card = $data['jsonld'];
			$card['@context'] = self::CONTEXT;
			$card['id'] = (string)$data['id'];
			$card['isPartOf'] = $data['isPartOf'];
			return $card;
		}

		$message = [
			'@context' => self::CONTEXT,
			'@type' => 'Message',
			'encodingFormat' => 'text/markdown',
			'id' => (string)$data['id'],
			'description' => $this->description($data),
			'sender' => $data['sender'],
			'recipient' => $data['recipient'],
		];
		if (!empty($data['parentItem'])) {
			$message['parentItem'] = $data['parentItem'];
		}

		return $message + [
			'dateSent' => $data['dateSent'],
			'dateCreated' => $data['dateCreated'],
			'dateModified' => $data['dateModified'],
			'interactionStatistic' => $data['interactionStatistic'],
			'isPartOf' => $data['isPartOf'],
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function description(array $data): ?string {
		if ($data['messageType'] === 'comment') {
			return $data['description'];
		}
		return sprintf('This message of type "%s" is not yet supported.', $data['messageType']);
	}

	/**
	 * @return list<array<string, string>>
	 */
	private function navigateActions(?string $previousUrl, ?string $nextUrl): array {
		$actions = [];
		if ($previousUrl !== null) {
			$actions[] = ['@type' => 'NavigateAction', 'type' => 'previous', 'target' => $previousUrl];
		}
		if ($nextUrl !== null) {
			$actions[] = ['@type' => 'NavigateAction', 'type' => 'next', 'target' => $nextUrl];
		}
		return $actions;
	}

	/**
	 * @return array<string, string>
	 */
	private function viewAction(string $target): array {
		return ['@type' => 'ViewAction', 'target' => $target];
	}

	/**
	 * @return array<string, string>
	 */
	private function refreshAction(string $target): array {
		return ['@type' => 'RefreshAction', 'target' => $target, 'authentication' => 'basic'];
	}

	/**
	 * @return array<string, string>
	 */
	private function commentAction(string $target): array {
		return ['@type' => 'CommentAction', 'target' => $target, 'authentication' => 'basic'];
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Service\JsonLd;

use OCA\Talk\Service\JsonLd\TalkMessageMapper;
use Test\TestCase;

class TalkMessageMapperTest extends TestCase {
	private const MESSAGE_URL = 'https://cloud.example.com/apps/spreed/lda/message/1042';
	private const VIEW_MESSAGE_URL = 'https://cloud.example.com/index.php/call/cv4f3axu#message_1042';
	private const REFRESH_THREAD_URL = 'https://cloud.example.com/apps/spreed/lda/thread/1042';
	private const COMMENT_THREAD_URL = 'https://cloud.example.com/apps/spreed/lda/thread/1042';
	private const REFRESH_ROOM_URL = 'https://cloud.example.com/apps/spreed/lda/room/cv4f3axu';
	private const COMMENT_ROOM_URL = 'https://cloud.example.com/apps/spreed/lda/room/cv4f3axu';
	private const VIEW_ROOM_URL = 'https://cloud.example.com/index.php/call/cv4f3axu';
	private const ROOM_IMAGE_URL = 'https://cloud.example.com/index.php/apps/spreed/avatar/cv4f3axu';

	private TalkMessageMapper $mapper;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = new TalkMessageMapper();
	}

	private function alice(): array {
		return [
			'@type' => 'Person',
			'name' => 'Alice Smith',
			'email' => 'alice@example.com',
			'image' => 'https://cloud.example.com/index.php/avatar/alice/128',
		];
	}

	private function bob(): array {
		return [
			'@type' => 'Person',
			'name' => 'Bob Jones',
			'email' => 'bob@example.com',
			'image' => 'https://cloud.example.com/index.php/avatar/bob/128',
		];
	}

	private function conversation(): array {
		return [
			'@type' => 'Conversation',
			'id' => 'cv4f3axu',
			'name' => 'General Discussion',
		];
	}

	private function sampleTextMessageData(): array {
		return [
			'id' => '1042',
			'messageType' => 'comment',
			'description' => 'Hey everyone, the deployment is planned for 3pm today.',
			'sender' => $this->alice(),
			'recipient' => [$this->bob()],
			'dateSent' => '2026-10-15T14:30:00+02:00',
			'dateCreated' => '2026-10-15T14:30:00+02:00',
			'dateModified' => '2026-10-15T14:35:00+02:00',
			'parentItem' => null,
			'interactionStatistic' => [
				[
					'@type' => 'InteractionCounter',
					'interactionType' => 'https://schema.org/LikeAction',
					'name' => '👍',
					'userInteractionCount' => 1,
					'agent' => [$this->bob()],
				],
			],
			'isPartOf' => $this->conversation(),
		];
	}

	public function testMapSingleTextMessage(): void {
		$result = $this->mapper->mapMessage(
			$this->sampleTextMessageData(),
			self::MESSAGE_URL,
			self::VIEW_MESSAGE_URL,
		);

		$this->assertSame([
			'@context' => 'https://schema.org/',
			'@type' => 'Message',
			'encodingFormat' => 'text/markdown',
			'id' => '1042',
			'description' => 'Hey everyone, the deployment is planned for 3pm today.',
			'sender' => $this->alice(),
			'recipient' => [$this->bob()],
			'dateSent' => '2026-10-15T14:30:00+02:00',
			'dateCreated' => '2026-10-15T14:30:00+02:00',
			'dateModified' => '2026-10-15T14:35:00+02:00',
			'interactionStatistic' => [
				[
					'@type' => 'InteractionCounter',
					'interactionType' => 'https://schema.org/LikeAction',
					'name' => '👍',
					'userInteractionCount' => 1,
					'agent' => [$this->bob()],
				],
			],
			'isPartOf' => $this->conversation(),
			'potentialAction' => [
				['@type' => 'ViewAction', 'target' => self::VIEW_MESSAGE_URL],
				['@type' => 'RefreshAction', 'target' => self::MESSAGE_URL, 'authentication' => 'basic'],
			],
		], $result);
	}

	public function testMapJsonLdCardMessageOutputsUnderlyingObject(): void {
		$cardData = [
			'id' => '1050',
			'messageType' => 'json-ld-card',
			'jsonld' => [
				'@context' => 'https://schema.org',
				'@type' => 'Person',
				'name' => 'Birdie Bird',
				'description' => 'Sample contact description',
				'image' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a4/Ada_Lovelace_portrait.jpg/440px-Ada_Lovelace_portrait.jpg',
				'url' => 'https://en.wikipedia.org/wiki/Ada_Lovelace',
			],
			'isPartOf' => $this->conversation(),
		];

		$result = $this->mapper->mapMessage(
			$cardData,
			'https://cloud.example.com/apps/spreed/lda/message/1050',
			'https://cloud.example.com/index.php/call/cv4f3axu#message_1050',
		);

		$this->assertSame('https://schema.org/', $result['@context']);
		$this->assertSame('Person', $result['@type']);
		$this->assertSame('1050', $result['id']);
		$this->assertSame('Birdie Bird', $result['name']);
		$this->assertSame($this->conversation(), $result['isPartOf']);
		$this->assertSame([
			['@type' => 'ViewAction', 'target' => 'https://cloud.example.com/index.php/call/cv4f3axu#message_1050'],
			['@type' => 'RefreshAction', 'target' => 'https://cloud.example.com/apps/spreed/lda/message/1050', 'authentication' => 'basic'],
		], $result['potentialAction']);
	}

	public function testMapUnsupportedMessageTypeFallsBackToNotice(): void {
		$data = [
			'id' => '1060',
			'messageType' => 'voice-message',
			'description' => null,
			'sender' => $this->alice(),
			'recipient' => [$this->bob()],
			'dateSent' => '2026-10-15T15:00:00+02:00',
			'dateCreated' => '2026-10-15T15:00:00+02:00',
			'dateModified' => null,
			'parentItem' => null,
			'interactionStatistic' => [],
			'isPartOf' => $this->conversation(),
		];

		$result = $this->mapper->mapMessage(
			$data,
			'https://cloud.example.com/apps/spreed/lda/message/1060',
			'https://cloud.example.com/index.php/call/cv4f3axu#message_1060',
		);

		$this->assertSame('Message', $result['@type']);
		$this->assertSame('This message of type "voice-message" is not yet supported.', $result['description']);
	}

	public function testMapReplyIncludesParentItem(): void {
		$data = $this->sampleTextMessageData();
		$data['id'] = '1043';
		$data['parentItem'] = ['@type' => 'Message', 'id' => '1042'];

		$result = $this->mapper->mapMessage(
			$data,
			'https://cloud.example.com/apps/spreed/lda/message/1043',
			'https://cloud.example.com/index.php/call/cv4f3axu#message_1043',
		);

		$this->assertSame(['@type' => 'Message', 'id' => '1042'], $result['parentItem']);
	}

	public function testMapThreadConversation(): void {
		$item1 = $this->mapper->messageItem(
			$this->sampleTextMessageData(),
			self::VIEW_MESSAGE_URL,
		);

		$replyData = $this->sampleTextMessageData();
		$replyData['id'] = '1043';
		$replyData['sender'] = $this->bob();
		$replyData['recipient'] = [$this->alice()];
		$replyData['parentItem'] = ['@type' => 'Message', 'id' => '1042'];
		$replyData['description'] = 'Sounds good, all tests are green on staging.';
		$item2 = $this->mapper->messageItem(
			$replyData,
			'https://cloud.example.com/index.php/call/cv4f3axu#message_1043',
		);

		$result = $this->mapper->mapThread(
			'1042',
			'Thread: Hey everyone, the deployment is planned for 3pm today.',
			[$item1, $item2],
			2,
			self::VIEW_MESSAGE_URL,
			self::REFRESH_THREAD_URL,
			self::COMMENT_THREAD_URL,
		);

		$this->assertSame([
			'@context' => 'https://schema.org/',
			'@type' => 'Conversation',
			'id' => '1042',
			'headline' => 'Thread: Hey everyone, the deployment is planned for 3pm today.',
			'numberOfItems' => 2,
			'totalCount' => 2,
			'itemListElement' => [$item1, $item2],
			'potentialAction' => [
				['@type' => 'ViewAction', 'target' => self::VIEW_MESSAGE_URL],
				['@type' => 'RefreshAction', 'target' => self::REFRESH_THREAD_URL, 'authentication' => 'basic'],
				['@type' => 'CommentAction', 'target' => self::COMMENT_THREAD_URL, 'authentication' => 'basic'],
			],
		], $result);

		// Items in thread have ViewAction only and no outer @context
		$this->assertArrayNotHasKey('@context', $item1);
		$this->assertSame([['@type' => 'ViewAction', 'target' => self::VIEW_MESSAGE_URL]], $item1['potentialAction']);
	}

	public function testMapRoomConversation(): void {
		$item1 = $this->mapper->messageItem(
			$this->sampleTextMessageData(),
			self::VIEW_MESSAGE_URL,
		);

		$result = $this->mapper->mapRoom(
			'cv4f3axu',
			'General Discussion',
			self::ROOM_IMAGE_URL,
			[$item1],
			1,
			self::VIEW_ROOM_URL,
			self::REFRESH_ROOM_URL,
			self::COMMENT_ROOM_URL,
		);

		$this->assertSame([
			'@context' => 'https://schema.org/',
			'@type' => 'Conversation',
			'id' => 'cv4f3axu',
			'name' => 'General Discussion',
			'image' => self::ROOM_IMAGE_URL,
			'numberOfItems' => 1,
			'totalCount' => 1,
			'itemListElement' => [$item1],
			'potentialAction' => [
				['@type' => 'ViewAction', 'target' => self::VIEW_ROOM_URL],
				['@type' => 'RefreshAction', 'target' => self::REFRESH_ROOM_URL, 'authentication' => 'basic'],
				['@type' => 'CommentAction', 'target' => self::COMMENT_ROOM_URL, 'authentication' => 'basic'],
			],
		], $result);
	}

	public function testMapRoomAddsTotalCountAndNavigateActions(): void {
		$item1 = $this->mapper->messageItem($this->sampleTextMessageData(), self::VIEW_MESSAGE_URL);
		$previousUrl = 'https://cloud.example.com/apps/spreed/lda/room/cv4f3axu?lastId=1042&direction=previous&count=1';
		$nextUrl = 'https://cloud.example.com/apps/spreed/lda/room/cv4f3axu?lastId=1044&direction=next&count=1';

		$result = $this->mapper->mapRoom(
			'cv4f3axu',
			'General Discussion',
			null,
			[$item1],
			7,
			self::VIEW_ROOM_URL,
			self::REFRESH_ROOM_URL,
			self::COMMENT_ROOM_URL,
			$previousUrl,
			$nextUrl,
		);

		$this->assertSame(1, $result['numberOfItems']);
		$this->assertSame(7, $result['totalCount']);
		$this->assertSame([
			['@type' => 'ViewAction', 'target' => self::VIEW_ROOM_URL],
			['@type' => 'RefreshAction', 'target' => self::REFRESH_ROOM_URL, 'authentication' => 'basic'],
			['@type' => 'CommentAction', 'target' => self::COMMENT_ROOM_URL, 'authentication' => 'basic'],
			['@type' => 'NavigateAction', 'type' => 'previous', 'target' => $previousUrl],
			['@type' => 'NavigateAction', 'type' => 'next', 'target' => $nextUrl],
		], $result['potentialAction']);
	}

	public function testMapActionResponseSuccess(): void {
		$result = $this->mapper->mapActionResponse('1044');

		$this->assertSame([
			'@context' => 'https://schema.org/',
			'@type' => 'LDAActionResponse',
			'status' => 'success',
			'id' => '1044',
		], $result);
	}
}

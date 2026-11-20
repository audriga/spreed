<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Service\JsonLd;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\CommentsManager;
use OCA\Talk\Chat\ReactionManager;
use OCA\Talk\Exceptions\MessagingNotAllowedException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\AvatarService;
use OCA\Talk\Service\JsonLd\JsonLdTalkService;
use OCA\Talk\Service\JsonLd\TalkMessageMapper;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\ThreadService;
use OCA\Talk\Webinary;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\NotFoundException;
use OCP\IAvatarManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class JsonLdTalkServiceTest extends TestCase {
	private const ROOM_URL = 'https://cloud.example.com/apps/spreed/lda/room/tok12345';
	private const THREAD_URL = 'https://cloud.example.com/apps/spreed/lda/thread/1';

	/** @var array<int, IComment&MockObject> */
	private array $store = [];
	/** @var list<array<int, mixed>> */
	private array $sent = [];
	private bool $participantAllowed = true;
	private bool $readOnly = false;

	private JsonLdTalkService $service;

	protected function setUp(): void {
		parent::setUp();

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1);
		$room->method('getToken')->willReturn('tok12345');
		$room->method('getDisplayName')->willReturn('Test room');
		$room->method('getReadOnly')->willReturnCallback(fn (): int => $this->readOnly ? Room::READ_ONLY : Room::READ_WRITE);
		$room->method('getType')->willReturn(Room::TYPE_GROUP);
		$room->method('getLobbyState')->willReturn(Webinary::LOBBY_NONE);

		$attendee = new Attendee();
		$attendee->setActorType(Attendee::ACTOR_USERS);
		$attendee->setActorId('alice');
		$participant = $this->createMock(Participant::class);
		$participant->method('getAttendee')->willReturn($attendee);
		$participant->method('getPermissions')->willReturn(Attendee::PERMISSIONS_CHAT);
		$participant->method('hasModeratorPermissions')->willReturn(false);

		$manager = $this->createMock(Manager::class);
		$manager->method('getRoomForUserByToken')->willReturn($room);
		$manager->method('getRoomById')->willReturn($room);

		$participantService = $this->createMock(ParticipantService::class);
		$participantService->method('getParticipant')->willReturnCallback(function () use ($participant): Participant {
			if (!$this->participantAllowed) {
				throw new ParticipantNotFoundException('not a participant');
			}
			return $participant;
		});
		$participantService->method('getParticipantsForRoom')->willReturn([]);

		$chatManager = $this->createMock(ChatManager::class);
		$chatManager->method('getMessagesById')->willReturnCallback(
			fn (array $commentIds): array => array_intersect_key($this->store, array_flip($commentIds)),
		);
		$chatManager->method('sendMessage')->willReturnCallback(function (...$args): IComment {
			$this->sent[] = $args;
			$comment = $this->createMock(IComment::class);
			$comment->method('getId')->willReturn('42');
			return $comment;
		});

		$commentsManager = $this->createMock(CommentsManager::class);
		$commentsManager->method('getCommentsWithVerbForObjectSinceComment')->willReturnCallback(
			fn (string $objectType, string $objectId, array $verbs, int $lastKnownCommentId, string $sortDirection, int $limit, bool $includeLastKnown, string $topmostParentId): array
				=> $this->fetchComments($verbs, $lastKnownCommentId, $sortDirection, $limit, $topmostParentId),
		);
		$commentsManager->method('countCommentsWithVerbForObject')->willReturnCallback(
			fn (string $objectType, string $objectId, array $verbs, string $topmostParentId = ''): int
				=> count($this->matchingComments($verbs, $topmostParentId)),
		);

		$threadService = $this->createMock(ThreadService::class);
		$threadService->method('findByThreadId')->willThrowException(new DoesNotExistException('no thread'));

		$avatarService = $this->createMock(AvatarService::class);
		$avatarService->method('getAvatar')->willThrowException(new \RuntimeException('no avatar'));
		$avatarManager = $this->createMock(IAvatarManager::class);
		$avatarManager->method('getAvatar')->willThrowException(new \RuntimeException('no avatar'));

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn(null);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters): string => match ($route) {
				'spreed.jsonld.message' => 'https://cloud.example.com/apps/spreed/lda/message/' . $parameters['id'],
				'spreed.jsonld.thread' => 'https://cloud.example.com/apps/spreed/lda/thread/' . $parameters['id'],
				'spreed.jsonld.room' => 'https://cloud.example.com/apps/spreed/lda/room/' . $parameters['token'],
				'spreed.Page.showCall' => 'https://cloud.example.com/index.php/call/' . $parameters['token'],
			},
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-01-01T00:00:00+00:00'));

		$this->service = new JsonLdTalkService(
			$manager,
			$chatManager,
			$commentsManager,
			$participantService,
			$threadService,
			$this->createMock(ReactionManager::class),
			$avatarService,
			$userManager,
			$avatarManager,
			$urlGenerator,
			$timeFactory,
			new TalkMessageMapper(),
		);
	}

	private function addComment(int $id, string $verb = ChatManager::VERB_MESSAGE, int $topmost = 0): void {
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn((string)$id);
		$comment->method('getVerb')->willReturn($verb);
		$comment->method('getTopmostParentId')->willReturn((string)$topmost);
		$comment->method('getParentId')->willReturn((string)$topmost);
		$comment->method('getMessage')->willReturn("message $id");
		$comment->method('getActorType')->willReturn(Attendee::ACTOR_USERS);
		$comment->method('getActorId')->willReturn('bob');
		$comment->method('getObjectType')->willReturn('chat');
		$comment->method('getObjectId')->willReturn('1');
		$comment->method('getCreationDateTime')->willReturn(new \DateTime('2026-01-01T00:00:00+00:00'));
		$comment->method('getMetaData')->willReturn([]);
		$comment->method('getReactions')->willReturn([]);
		$this->store[$id] = $comment;
	}

	/**
	 * Same rules as CommentsManager::getCommentsWithVerbForObjectSinceComment(): verb whitelist,
	 * thread filter, exclusive cursor, sort direction and limit. Ids stand in for creation order.
	 *
	 * @return list<IComment>
	 */
	private function fetchComments(array $verbs, int $lastKnownCommentId, string $sortDirection, int $limit, string $topmostParentId): array {
		$comments = $this->matchingComments($verbs, $topmostParentId);
		if ($lastKnownCommentId > 0) {
			$comments = array_filter($comments, static fn (IComment $comment): bool => $sortDirection === 'desc'
				? (int)$comment->getId() < $lastKnownCommentId
				: (int)$comment->getId() > $lastKnownCommentId);
		}

		usort($comments, static fn (IComment $a, IComment $b): int => $sortDirection === 'desc'
			? (int)$b->getId() <=> (int)$a->getId()
			: (int)$a->getId() <=> (int)$b->getId());

		return $limit > 0 ? array_slice($comments, 0, $limit) : $comments;
	}

	/**
	 * @return list<IComment>
	 */
	private function matchingComments(array $verbs, string $topmostParentId): array {
		$matching = [];
		foreach ($this->store as $id => $comment) {
			if ($verbs !== [] && !in_array($comment->getVerb(), $verbs, true)) {
				continue;
			}
			if ($topmostParentId !== '' && (string)$id !== $topmostParentId && $comment->getTopmostParentId() !== $topmostParentId) {
				continue;
			}
			$matching[] = $comment;
		}
		return $matching;
	}

	private function navigation(array $result, string $type): ?string {
		foreach ($result['potentialAction'] as $action) {
			if (($action['@type'] ?? '') === 'NavigateAction' && $action['type'] === $type) {
				return $action['target'];
			}
		}
		return null;
	}

	private function ids(array $result): array {
		return array_column($result['itemListElement'], 'id');
	}

	public function testRoomWithoutCursorReturnsNewestPage(): void {
		for ($id = 1; $id <= 5; $id++) {
			$this->addComment($id);
		}

		$result = $this->service->getRoom('alice', 'tok12345', 0, 'previous', 2);

		$this->assertSame(['4', '5'], $this->ids($result));
		$this->assertSame(2, $result['numberOfItems']);
		$this->assertSame(5, $result['totalCount']);
		$this->assertSame(self::ROOM_URL . '?lastId=4&direction=previous&count=2', $this->navigation($result, 'previous'));
		$this->assertNull($this->navigation($result, 'next'));
		$this->assertNull($result['image']);
	}

	public function testRoomPreviousPageOffersBothDirections(): void {
		for ($id = 1; $id <= 5; $id++) {
			$this->addComment($id);
		}

		$result = $this->service->getRoom('alice', 'tok12345', 4, 'previous', 2);

		$this->assertSame(['2', '3'], $this->ids($result));
		$this->assertSame(self::ROOM_URL . '?lastId=2&direction=previous&count=2', $this->navigation($result, 'previous'));
		$this->assertSame(self::ROOM_URL . '?lastId=3&direction=next&count=2', $this->navigation($result, 'next'));
	}

	public function testRoomOldestPageHasNoPrevious(): void {
		for ($id = 1; $id <= 5; $id++) {
			$this->addComment($id);
		}

		$result = $this->service->getRoom('alice', 'tok12345', 2, 'previous', 2);

		$this->assertSame(['1'], $this->ids($result));
		$this->assertNull($this->navigation($result, 'previous'));
		$this->assertSame(self::ROOM_URL . '?lastId=1&direction=next&count=2', $this->navigation($result, 'next'));
	}

	public function testRoomNextDirectionReturnsNewerMessagesOldestFirst(): void {
		for ($id = 1; $id <= 5; $id++) {
			$this->addComment($id);
		}

		$result = $this->service->getRoom('alice', 'tok12345', 1, 'next', 2);

		$this->assertSame(['2', '3'], $this->ids($result));
		$this->assertSame(self::ROOM_URL . '?lastId=2&direction=previous&count=2', $this->navigation($result, 'previous'));
		$this->assertSame(self::ROOM_URL . '?lastId=3&direction=next&count=2', $this->navigation($result, 'next'));
	}

	public function testNextWithoutCursorFallsBackToNewestPage(): void {
		for ($id = 1; $id <= 5; $id++) {
			$this->addComment($id);
		}

		$result = $this->service->getRoom('alice', 'tok12345', 0, 'next', 2);

		$this->assertSame(['4', '5'], $this->ids($result));
		$this->assertNull($this->navigation($result, 'next'));
	}

	public function testRoomListsOnlyWhitelistedVerbs(): void {
		$this->addComment(1, ChatManager::VERB_MESSAGE);
		$this->addComment(2, ChatManager::VERB_SYSTEM);
		$this->addComment(3, ChatManager::VERB_REACTION);
		$this->addComment(4, ChatManager::VERB_MESSAGE_DELETED);
		$this->addComment(5, ChatManager::VERB_OBJECT_SHARED);
		$this->addComment(6, ChatManager::VERB_MESSAGE);

		$result = $this->service->getRoom('alice', 'tok12345', 0, 'previous', 100);

		$this->assertSame(['1', '5', '6'], $this->ids($result));
		$this->assertSame(3, $result['totalCount']);
	}

	public function testThreadPagesRootAndRepliesOnly(): void {
		$this->addComment(1);
		$this->addComment(2);
		$this->addComment(3, ChatManager::VERB_MESSAGE, 1);
		$this->addComment(4, ChatManager::VERB_MESSAGE, 1);
		$this->addComment(5, ChatManager::VERB_MESSAGE, 1);

		$newest = $this->service->getThread('alice', 1, 0, 'previous', 2);

		$this->assertSame(['4', '5'], $this->ids($newest));
		$this->assertSame(4, $newest['totalCount']);
		$this->assertSame(self::THREAD_URL . '?lastId=4&direction=previous&count=2', $this->navigation($newest, 'previous'));
		$this->assertNull($this->navigation($newest, 'next'));

		$oldest = $this->service->getThread('alice', 1, 4, 'previous', 2);

		$this->assertSame(['1', '3'], $this->ids($oldest));
		$this->assertSame(4, $oldest['totalCount']);
		$this->assertNull($this->navigation($oldest, 'previous'));
		$this->assertSame(self::THREAD_URL . '?lastId=3&direction=next&count=2', $this->navigation($oldest, 'next'));
	}

	public function testGetMessageThrowsWhenMessageDoesNotExist(): void {
		$this->expectException(NotFoundException::class);

		$this->service->getMessage('alice', 99);
	}

	public function testGetRoomThrowsWhenUserIsNotParticipant(): void {
		$this->addComment(1);
		$this->participantAllowed = false;
		$this->expectException(ParticipantNotFoundException::class);

		$this->service->getRoom('alice', 'tok12345', 0, 'previous', 100);
	}

	public function testCreateRoomMessageIsRejectedInReadOnlyRoom(): void {
		$this->readOnly = true;

		try {
			$this->service->createRoomMessage('alice', 'tok12345', 'hello');
			$this->fail('Expected MessagingNotAllowedException');
		} catch (MessagingNotAllowedException) {
			$this->assertSame([], $this->sent);
		}
	}

	public function testCreateRoomMessagePostsWithoutReplyTarget(): void {
		$result = $this->service->createRoomMessage('alice', 'tok12345', 'hello');

		$this->assertSame(['@context' => 'https://schema.org/', '@type' => 'LDAActionResponse', 'status' => 'success', 'id' => '42'], $result);
		$this->assertSame('hello', $this->sent[0][4]);
		$this->assertNull($this->sent[0][6]);
	}

	public function testCreateThreadCommentRepliesToRoot(): void {
		$this->addComment(1);

		$this->service->createThreadComment('alice', 1, 'reply');

		$this->assertSame('reply', $this->sent[0][4]);
		$this->assertSame('1', $this->sent[0][6]->getId());
	}
}

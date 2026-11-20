<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Service\JsonLd;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\CommentsManager;
use OCA\Talk\Chat\ReactionManager;
use OCA\Talk\Exceptions\MessagingNotAllowedException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\AvatarService;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\ThreadService;
use OCA\Talk\Webinary;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IAvatarManager;
use OCP\IURLGenerator;
use OCP\IUserManager;

class JsonLdTalkService {
	public const MAX_COUNT = 100;
	public const DIRECTION_PREVIOUS = 'previous';
	public const DIRECTION_NEXT = 'next';
	private const AVATAR_SIZE = 64;
	private const MESSAGE_VERBS = [
		ChatManager::VERB_MESSAGE,
		ChatManager::VERB_OBJECT_SHARED,
		ChatManager::VERB_COMMAND,
		ChatManager::VERB_VOICE_MESSAGE,
		ChatManager::VERB_RECORD_AUDIO,
		ChatManager::VERB_RECORD_VIDEO,
		ChatManager::VERB_PRIVATE_REPLY,
	];

	public function __construct(
		private Manager $manager,
		private ChatManager $chatManager,
		private CommentsManager $commentsManager,
		private ParticipantService $participantService,
		private ThreadService $threadService,
		private ReactionManager $reactionManager,
		private AvatarService $avatarService,
		private IUserManager $userManager,
		private IAvatarManager $avatarManager,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $timeFactory,
		private TalkMessageMapper $mapper,
	) {
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 * @throws RoomNotFoundException
	 * @throws ParticipantNotFoundException
	 */
	public function getMessage(string $userId, int $messageId): array {
		[$room, $participant, $comment] = $this->resolveMessage($userId, $messageId);
		$data = $this->buildMessageData($room, $participant, $comment, $this->getUserParticipants($room));

		return $this->mapper->mapMessage(
			$data,
			$this->messageUrl($messageId),
			$this->messageViewUrl($room, $messageId),
		);
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 * @throws RoomNotFoundException
	 * @throws ParticipantNotFoundException
	 */
	public function getThread(string $userId, int $messageId, int $lastId, string $direction, int $count): array {
		[$room, $participant, $root] = $this->resolveMessage($userId, $messageId);
		$this->assertThreadRoot($root);

		$page = $this->getMessagePage($room, $messageId, $lastId, $direction, $count);
		$items = $this->buildItems($room, $participant, $page['comments'], $this->getUserParticipants($room));

		return $this->mapper->mapThread(
			(string)$messageId,
			$this->threadHeadline($room, $root),
			$items,
			$page['totalCount'],
			$this->messageViewUrl($room, $messageId),
			$this->threadUrl($messageId),
			$this->threadUrl($messageId),
			$this->navigationUrl($this->threadUrl($messageId), $page['previousCursor'], self::DIRECTION_PREVIOUS, $count),
			$this->navigationUrl($this->threadUrl($messageId), $page['nextCursor'], self::DIRECTION_NEXT, $count),
		);
	}

	/**
	 * @return array<string, mixed>
	 * @throws RoomNotFoundException
	 * @throws ParticipantNotFoundException
	 */
	public function getRoom(string $userId, string $token, int $lastId, string $direction, int $count): array {
		$room = $this->manager->getRoomForUserByToken($token, $userId);
		$participant = $this->participantService->getParticipant($room, $userId);

		$page = $this->getMessagePage($room, 0, $lastId, $direction, $count);
		$items = $this->buildItems($room, $participant, $page['comments'], $this->getUserParticipants($room));

		return $this->mapper->mapRoom(
			$room->getToken(),
			$room->getDisplayName($userId),
			$this->roomAvatar($room, $userId),
			$items,
			$page['totalCount'],
			$this->roomViewUrl($room->getToken()),
			$this->roomUrl($token),
			$this->roomUrl($token),
			$this->navigationUrl($this->roomUrl($token), $page['previousCursor'], self::DIRECTION_PREVIOUS, $count),
			$this->navigationUrl($this->roomUrl($token), $page['nextCursor'], self::DIRECTION_NEXT, $count),
		);
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 * @throws RoomNotFoundException
	 * @throws ParticipantNotFoundException
	 * @throws MessagingNotAllowedException
	 */
	public function createThreadComment(string $userId, int $messageId, string $message): array {
		[$room, $participant, $root] = $this->resolveMessage($userId, $messageId);
		$this->assertThreadRoot($root);

		return $this->postMessage($room, $participant, $userId, $message, $root);
	}

	/**
	 * @return array<string, mixed>
	 * @throws RoomNotFoundException
	 * @throws ParticipantNotFoundException
	 * @throws MessagingNotAllowedException
	 */
	public function createRoomMessage(string $userId, string $token, string $message): array {
		$room = $this->manager->getRoomForUserByToken($token, $userId);
		$participant = $this->participantService->getParticipant($room, $userId);

		return $this->postMessage($room, $participant, $userId, $message);
	}

	/**
	 * @return array{Room, Participant, IComment}
	 * @throws NotFoundException
	 * @throws RoomNotFoundException
	 * @throws ParticipantNotFoundException
	 */
	private function resolveMessage(string $userId, int $messageId): array {
		$comment = $this->chatManager->getMessagesById([$messageId])[$messageId] ?? null;
		if (!$comment instanceof IComment || $comment->getObjectType() !== 'chat') {
			throw new NotFoundException('Message not found');
		}

		$room = $this->manager->getRoomById((int)$comment->getObjectId());
		$participant = $this->participantService->getParticipant($room, $userId);

		return [$room, $participant, $comment];
	}

	/**
	 * @throws NotFoundException
	 */
	private function assertThreadRoot(IComment $comment): void {
		if ($comment->getTopmostParentId() !== '0') {
			throw new NotFoundException('Thread not found');
		}
	}

	/**
	 * @throws MessagingNotAllowedException
	 */
	private function postMessage(Room $room, Participant $participant, string $userId, string $message, ?IComment $parent = null): array {
		$this->assertCanPost($room, $participant);

		$comment = $this->chatManager->sendMessage(
			$room,
			$participant,
			Attendee::ACTOR_USERS,
			$userId,
			$message,
			$this->timeFactory->getDateTime('now', new \DateTimeZone('UTC')),
			$parent,
		);

		return $this->mapper->mapActionResponse((string)$comment->getId());
	}

	/**
	 * @throws MessagingNotAllowedException
	 */
	private function assertCanPost(Room $room, Participant $participant): void {
		if ($room->getReadOnly() === Room::READ_ONLY || $room->getType() === Room::TYPE_CHANGELOG) {
			throw new MessagingNotAllowedException();
		}

		if ($room->getLobbyState() !== Webinary::LOBBY_NONE
			&& !$participant->hasModeratorPermissions()
			&& !($participant->getPermissions() & Attendee::PERMISSIONS_LOBBY_IGNORE)) {
			throw new MessagingNotAllowedException();
		}

		if (!($participant->getPermissions() & Attendee::PERMISSIONS_CHAT)) {
			throw new MessagingNotAllowedException();
		}
	}

	private function threadHeadline(Room $room, IComment $root): string {
		try {
			$title = $this->threadService->findByThreadId($room->getId(), (int)$root->getId())->getName();
		} catch (DoesNotExistException) {
			$title = '';
		}

		return $title !== '' ? $title : $root->getMessage();
	}

	/**
	 * Pages the messages of a room (threadId 0) or thread (root id) around $lastId.
	 * $lastId 0 starts at the newest message. Comments are returned oldest first.
	 *
	 * @return array{comments: list<IComment>, previousCursor: ?int, nextCursor: ?int, totalCount: int}
	 */
	private function getMessagePage(Room $room, int $threadId, int $lastId, string $direction, int $count): array {
		$objectId = (string)$room->getId();
		$topmostParentId = $threadId !== 0 ? (string)$threadId : '';
		$fetch = fn (int $cursor, string $sortDirection, int $limit): array => $this->commentsManager->getCommentsWithVerbForObjectSinceComment(
			'chat',
			$objectId,
			self::MESSAGE_VERBS,
			$cursor,
			$sortDirection,
			$limit,
			false,
			$topmostParentId,
		);

		$direction = $lastId === 0 ? self::DIRECTION_PREVIOUS : $direction;
		$rows = $fetch($lastId, $direction === self::DIRECTION_NEXT ? 'asc' : 'desc', $count + 1);
		$hasMore = count($rows) > $count;
		$comments = array_slice($rows, 0, $count);
		if ($direction === self::DIRECTION_PREVIOUS) {
			$comments = array_reverse($comments);
		}

		$oldestId = $comments === [] ? $lastId : (int)$comments[array_key_first($comments)]->getId();
		$newestId = $comments === [] ? $lastId : (int)$comments[array_key_last($comments)]->getId();
		$hasPrevious = $direction === self::DIRECTION_PREVIOUS
			? $hasMore
			: ($oldestId > 0 && $fetch($oldestId, 'desc', 1) !== []);
		$hasNext = $direction === self::DIRECTION_NEXT
			? $hasMore
			: ($newestId > 0 && $fetch($newestId, 'asc', 1) !== []);

		return [
			'comments' => $comments,
			'previousCursor' => $hasPrevious ? $oldestId : null,
			'nextCursor' => $hasNext ? $newestId : null,
			'totalCount' => $this->commentsManager->countCommentsWithVerbForObject('chat', $objectId, self::MESSAGE_VERBS, $topmostParentId),
		];
	}

	private function navigationUrl(string $baseUrl, ?int $cursor, string $direction, int $count): ?string {
		if ($cursor === null) {
			return null;
		}

		return $baseUrl . '?' . http_build_query(['lastId' => $cursor, 'direction' => $direction, 'count' => $count]);
	}

	/**
	 * @return list<Participant>
	 */
	private function getUserParticipants(Room $room): array {
		return array_values(array_filter(
			$this->participantService->getParticipantsForRoom($room),
			static fn (Participant $participant): bool => $participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS,
		));
	}

	/**
	 * @param IComment[] $comments
	 * @param list<Participant> $users
	 * @return list<array<string, mixed>>
	 */
	private function buildItems(Room $room, Participant $viewer, array $comments, array $users): array {
		return array_values(array_map(
			fn (IComment $comment): array => $this->mapper->messageItem(
				$this->buildMessageData($room, $viewer, $comment, $users),
				$this->messageViewUrl($room, (int)$comment->getId()),
			),
			$comments,
		));
	}

	/**
	 * @param list<Participant> $users
	 * @return array<string, mixed>
	 */
	private function buildMessageData(Room $room, Participant $viewer, IComment $comment, array $users): array {
		$card = $this->getJsonLdCard($comment);
		$created = $comment->getCreationDateTime()->format(\DateTimeInterface::ATOM);
		$editedAt = $comment->getMetaData()['last_edited_time'] ?? null;

		return [
			'id' => (string)$comment->getId(),
			'messageType' => $card !== null ? 'json-ld-card' : $comment->getVerb(),
			'jsonld' => $card,
			'description' => $comment->getMessage(),
			'sender' => $this->getPerson($room, $comment->getActorType(), $comment->getActorId()),
			'recipient' => $this->getRecipients($room, $users, $comment->getActorType(), $comment->getActorId()),
			'parentItem' => $this->getParentItem($comment),
			'dateSent' => $created,
			'dateCreated' => $created,
			'dateModified' => $editedAt ? (new \DateTimeImmutable('@' . $editedAt))->format(\DateTimeInterface::ATOM) : null,
			'interactionStatistic' => $this->getInteractionStatistic($room, $viewer, $comment),
			'isPartOf' => [
				'@type' => 'Conversation',
				'id' => $room->getToken(),
				'name' => $room->getDisplayName($viewer->getAttendee()->getActorId()),
			],
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function getJsonLdCard(IComment $comment): ?array {
		if ($comment->getVerb() !== ChatManager::VERB_OBJECT_SHARED) {
			return null;
		}

		$message = $this->decodeJson($comment->getMessage());
		$parameters = $message['parameters'] ?? [];
		if (($message['message'] ?? '') !== ChatManager::VERB_OBJECT_SHARED
			|| ($parameters['objectType'] ?? '') !== 'json-ld-card') {
			return null;
		}

		$card = $parameters['metaData']['jsonld'] ?? '';
		return is_string($card) ? $this->decodeJson($card) : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function decodeJson(string $json): ?array {
		try {
			$data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		return is_array($data) ? $data : null;
	}

	/**
	 * @param list<Participant> $users
	 * @return list<array<string, mixed>>
	 */
	private function getRecipients(Room $room, array $users, string $senderType, string $senderId): array {
		$recipients = [];
		foreach ($users as $user) {
			$attendee = $user->getAttendee();
			if ($attendee->getActorType() === $senderType && $attendee->getActorId() === $senderId) {
				continue;
			}
			$recipients[] = $this->getPerson($room, $attendee->getActorType(), $attendee->getActorId());
		}
		return $recipients;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getPerson(Room $room, string $actorType, string $actorId): array {
		if ($actorType === Attendee::ACTOR_USERS) {
			$user = $this->userManager->get($actorId);
			if ($user !== null) {
				return [
					'@type' => 'Person',
					'name' => $user->getDisplayName(),
					'email' => $user->getEMailAddress(),
					'image' => $this->userAvatar($actorId),
				];
			}
		}

		$name = $actorId;
		if ($actorType === Attendee::ACTOR_GUESTS) {
			try {
				$name = $this->participantService->getParticipantByActor($room, $actorType, $actorId)->getAttendee()->getDisplayName();
			} catch (ParticipantNotFoundException) {
			}
		}

		return [
			'@type' => 'Person',
			'name' => $name,
			'email' => null,
			'image' => null,
		];
	}

	/**
	 * @return array<string, string>|null
	 */
	private function getParentItem(IComment $comment): ?array {
		$parentId = $comment->getParentId();
		if ($parentId === '' || $parentId === '0') {
			return null;
		}

		return ['@type' => 'Message', 'id' => $parentId];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function getInteractionStatistic(Room $room, Participant $viewer, IComment $comment): array {
		if (empty($comment->getReactions())) {
			return [];
		}

		$statistic = [];
		$reactions = $this->reactionManager->retrieveReactionMessages($room, $viewer, (int)$comment->getId());
		foreach ($reactions as $emoji => $actors) {
			$statistic[] = [
				'@type' => 'InteractionCounter',
				'interactionType' => 'https://schema.org/LikeAction',
				'name' => (string)$emoji,
				'userInteractionCount' => count($actors),
				'agent' => array_map(
					fn (array $actor): array => $this->getPerson($room, $actor['actorType'], $actor['actorId']),
					$actors,
				),
			];
		}

		return $statistic;
	}

	private function userAvatar(string $userId): ?string {
		try {
			return $this->encodeAvatar($this->avatarManager->getAvatar($userId)->getFile(self::AVATAR_SIZE));
		} catch (\Exception) {
			return null;
		}
	}

	private function roomAvatar(Room $room, string $userId): ?string {
		try {
			return $this->encodeAvatar($this->avatarService->getAvatar($room, $this->userManager->get($userId)));
		} catch (\Exception) {
			return null;
		}
	}

	private function encodeAvatar(ISimpleFile $file): string {
		$content = $file->getContent();
		$mimeType = $file->getMimeType();

		$source = $mimeType === 'image/svg+xml' ? false : imagecreatefromstring($content);
		$scaled = $source === false ? false : imagescale($source, self::AVATAR_SIZE);
		if ($scaled !== false) {
			imagesavealpha($scaled, true);
			ob_start();
			imagepng($scaled);
			$content = ob_get_clean();
			$mimeType = 'image/png';
		}

		return 'data:' . $mimeType . ';base64,' . base64_encode($content);
	}

	private function messageUrl(int $messageId): string {
		return $this->urlGenerator->linkToRouteAbsolute('spreed.jsonld.message', ['id' => $messageId]);
	}

	private function threadUrl(int $messageId): string {
		return $this->urlGenerator->linkToRouteAbsolute('spreed.jsonld.thread', ['id' => $messageId]);
	}

	private function roomUrl(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute('spreed.jsonld.room', ['token' => $token]);
	}

	private function roomViewUrl(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute('spreed.Page.showCall', ['token' => $token]);
	}

	private function messageViewUrl(Room $room, int $messageId): string {
		return $this->roomViewUrl($room->getToken()) . '#message_' . $messageId;
	}
}

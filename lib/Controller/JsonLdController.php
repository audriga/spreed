<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Controller;

use OCA\Talk\Exceptions\MessagingNotAllowedException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Service\JsonLd\JsonLdErrorMapper;
use OCA\Talk\Service\JsonLd\JsonLdTalkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Comments\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class JsonLdController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private JsonLdTalkService $talkService,
		private JsonLdErrorMapper $errorMapper,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	#[FrontpageRoute(verb: 'GET', url: '/lda/message/{id}', requirements: ['id' => '\d+'])]
	public function message(int $id): JSONResponse {
		return $this->execute(
			$this->urlGenerator->linkToRouteAbsolute('spreed.jsonld.message', ['id' => $id]),
			Http::STATUS_OK,
			fn (string $userId): array => $this->talkService->getMessage($userId, $id),
		);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	#[FrontpageRoute(verb: 'GET', url: '/lda/thread/{id}', requirements: ['id' => '\d+'])]
	public function thread(int $id, string $lastId = '0', string $direction = JsonLdTalkService::DIRECTION_PREVIOUS, string $count = '100'): JSONResponse {
		return $this->execute(
			$this->urlGenerator->linkToRouteAbsolute('spreed.jsonld.thread', ['id' => $id]),
			Http::STATUS_OK,
			fn (string $userId): array => $this->talkService->getThread($userId, $id, ...$this->paging($lastId, $direction, $count)),
		);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	#[FrontpageRoute(verb: 'GET', url: '/lda/room/{token}', requirements: ['token' => '[a-z0-9]{4,30}'])]
	public function room(string $token, string $lastId = '0', string $direction = JsonLdTalkService::DIRECTION_PREVIOUS, string $count = '100'): JSONResponse {
		return $this->execute(
			$this->urlGenerator->linkToRouteAbsolute('spreed.jsonld.room', ['token' => $token]),
			Http::STATUS_OK,
			fn (string $userId): array => $this->talkService->getRoom($userId, $token, ...$this->paging($lastId, $direction, $count)),
		);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[CORS]
	#[FrontpageRoute(verb: 'POST', url: '/lda/thread/{id}', requirements: ['id' => '\d+'])]
	public function commentThread(int $id): JSONResponse {
		return $this->execute(
			$this->urlGenerator->linkToRouteAbsolute('spreed.jsonld.thread', ['id' => $id]),
			Http::STATUS_CREATED,
			fn (string $userId): array => $this->talkService->createThreadComment($userId, $id, $this->commentText()),
		);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[CORS]
	#[FrontpageRoute(verb: 'POST', url: '/lda/room/{token}', requirements: ['token' => '[a-z0-9]{4,30}'])]
	public function commentRoom(string $token): JSONResponse {
		return $this->execute(
			$this->urlGenerator->linkToRouteAbsolute('spreed.jsonld.room', ['token' => $token]),
			Http::STATUS_CREATED,
			fn (string $userId): array => $this->talkService->createRoomMessage($userId, $token, $this->commentText()),
		);
	}

	/**
	 * @param callable(string): array<string, mixed> $action
	 */
	private function execute(string $loginTarget, int $successStatus, callable $action): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse($this->errorMapper->unauthorized($loginTarget), Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($action($this->userId), $successStatus);
		} catch (\InvalidArgumentException $e) {
			return $this->error(Http::STATUS_BAD_REQUEST, $e->getMessage());
		} catch (NotFoundException|RoomNotFoundException) {
			return $this->error(Http::STATUS_NOT_FOUND, 'Message or room not found');
		} catch (ParticipantNotFoundException|MessagingNotAllowedException) {
			return $this->error(Http::STATUS_FORBIDDEN, 'Access denied to room');
		} catch (\Throwable $e) {
			$this->logger->error('JSON-LD request failed', ['exception' => $e]);
			return $this->error(Http::STATUS_INTERNAL_SERVER_ERROR, 'Internal server error');
		}
	}

	/**
	 * @return array{int, string, int}
	 * @throws \InvalidArgumentException
	 */
	private function paging(string $lastId, string $direction, string $count): array {
		if (!ctype_digit($lastId)) {
			throw new \InvalidArgumentException('lastId must be a non-negative integer');
		}
		if (!ctype_digit($count)) {
			throw new \InvalidArgumentException('count must be a non-negative integer');
		}
		if (!in_array($direction, [JsonLdTalkService::DIRECTION_PREVIOUS, JsonLdTalkService::DIRECTION_NEXT], true)) {
			throw new \InvalidArgumentException('direction must be previous or next');
		}

		return [(int)$lastId, $direction, min(max((int)$count, 1), JsonLdTalkService::MAX_COUNT)];
	}

	private function error(int $status, string $description): JSONResponse {
		return new JSONResponse(
			$this->errorMapper->error($status, $description, (string)$this->request->getPathInfo()),
			$status,
		);
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function commentText(): string {
		$text = $this->request->getParams()['resultComment']['text'] ?? null;
		if (!is_string($text) || trim($text) === '') {
			throw new \InvalidArgumentException('resultComment.text is required');
		}

		return $text;
	}
}

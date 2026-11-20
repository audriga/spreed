<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Controller;

use OCA\Talk\Controller\JsonLdController;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Service\JsonLd\JsonLdErrorMapper;
use OCA\Talk\Service\JsonLd\JsonLdTalkService;
use OCP\AppFramework\Http;
use OCP\Comments\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class JsonLdControllerTest extends TestCase {
	private const MESSAGE_URL = 'https://cloud.example.com/apps/spreed/lda/message/1042';
	private const THREAD_URL = 'https://cloud.example.com/apps/spreed/lda/thread/1042';
	private const ROOM_URL = 'https://cloud.example.com/apps/spreed/lda/room/cv4f3axu';

	private JsonLdTalkService&MockObject $talkService;
	private IRequest&MockObject $request;
	private IURLGenerator&MockObject $urlGenerator;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->talkService = $this->createMock(JsonLdTalkService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->request->method('getPathInfo')->willReturn('/apps/spreed/lda/message/1042');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters): string => match ($route) {
				'spreed.jsonld.message' => 'https://cloud.example.com/apps/spreed/lda/message/' . $parameters['id'],
				'spreed.jsonld.thread' => 'https://cloud.example.com/apps/spreed/lda/thread/' . $parameters['id'],
				'spreed.jsonld.room' => 'https://cloud.example.com/apps/spreed/lda/room/' . $parameters['token'],
			},
		);
	}

	private function controller(?string $userId): JsonLdController {
		return new JsonLdController(
			'spreed',
			$this->request,
			$userId,
			$this->talkService,
			new JsonLdErrorMapper(),
			$this->urlGenerator,
			$this->logger,
		);
	}

	public function testGetMessageReturnsJson(): void {
		$this->talkService->expects($this->once())
			->method('getMessage')
			->with('alice', 1042)
			->willReturn(['@type' => 'Message', 'id' => '1042', 'description' => 'Hi']);

		$response = $this->controller('alice')->message(1042);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Message', $response->getData()['@type']);
		$this->assertSame('application/json; charset=utf-8', $response->getHeaders()['Content-Type']);
	}

	public function testGetMessageUnauthenticatedReturns401LoginAction(): void {
		$this->talkService->expects($this->never())->method('getMessage');

		$response = $this->controller(null)->message(1042);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('LDAActionResponse', $response->getData()['@type']);
		$this->assertSame('LoginAction', $response->getData()['potentialAction']['@type']);
		$this->assertSame(self::MESSAGE_URL, $response->getData()['potentialAction']['target']);
	}

	public function testGetMessageNotFoundReturns404ErrorAction(): void {
		$this->talkService->method('getMessage')->willThrowException(new NotFoundException('Message not found'));

		$response = $this->controller('alice')->message(99999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('ErrorAction', $response->getData()['@type']);
		$this->assertSame('Not Found', $response->getData()['name']);
	}

	public function testGetMessageForbiddenReturns403ErrorAction(): void {
		$this->talkService->method('getMessage')->willThrowException(new ParticipantNotFoundException('Not participant'));

		$response = $this->controller('alice')->message(1042);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('ErrorAction', $response->getData()['@type']);
		$this->assertSame('Forbidden', $response->getData()['name']);
	}

	public function testGetThreadReturnsConversationJson(): void {
		$this->talkService->expects($this->once())
			->method('getThread')
			->with('alice', 1042, 0, 'previous', 100)
			->willReturn(['@type' => 'Conversation', 'id' => '1042', 'numberOfItems' => 2]);

		$response = $this->controller('alice')->thread(1042);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Conversation', $response->getData()['@type']);
	}

	public function testGetRoomReturnsRoomConversationJson(): void {
		$this->talkService->expects($this->once())
			->method('getRoom')
			->with('alice', 'cv4f3axu', 0, 'previous', 100)
			->willReturn(['@type' => 'Conversation', 'id' => 'cv4f3axu', 'numberOfItems' => 5]);

		$response = $this->controller('alice')->room('cv4f3axu');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Conversation', $response->getData()['@type']);
		$this->assertSame('cv4f3axu', $response->getData()['id']);
	}

	public function testGetRoomForwardsPagingArgumentsAndClampsCount(): void {
		$this->talkService->expects($this->once())
			->method('getRoom')
			->with('alice', 'cv4f3axu', 1042, 'next', 100)
			->willReturn(['@type' => 'Conversation', 'id' => 'cv4f3axu']);

		$this->controller('alice')->room('cv4f3axu', '1042', 'next', '500');
	}

	public function testGetRoomInvalidDirectionReturns400(): void {
		$this->talkService->expects($this->never())->method('getRoom');

		$response = $this->controller('alice')->room('cv4f3axu', '0', 'sideways', '100');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Bad Request', $response->getData()['name']);
	}

	public function testGetRoomNonNumericPagingValuesReturn400(): void {
		$this->talkService->expects($this->never())->method('getRoom');

		foreach ([['abc', 'previous', '100'], ['-5', 'previous', '100'], ['0', 'previous', 'many']] as [$lastId, $direction, $count]) {
			$response = $this->controller('alice')->room('cv4f3axu', $lastId, $direction, $count);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), "lastId=$lastId count=$count");
		}
	}

	public function testPostCommentToThreadReturns201ActionResponse(): void {
		$this->request->method('getParams')->willReturn([
			'@context' => 'https://schema.org/',
			'@type' => 'CommentAction',
			'resultComment' => [
				'@type' => 'Comment',
				'text' => 'New reply text',
			],
		]);

		$this->talkService->expects($this->once())
			->method('createThreadComment')
			->with('alice', 1042, 'New reply text')
			->willReturn(['@type' => 'LDAActionResponse', 'status' => 'success', 'id' => '1044']);

		$response = $this->controller('alice')->commentThread(1042);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
		$this->assertSame('1044', $response->getData()['id']);
	}

	public function testPostCommentToRoomReturns201ActionResponse(): void {
		$this->request->method('getParams')->willReturn([
			'@context' => 'https://schema.org/',
			'@type' => 'CommentAction',
			'resultComment' => [
				'@type' => 'Comment',
				'text' => 'New room message',
			],
		]);

		$this->talkService->expects($this->once())
			->method('createRoomMessage')
			->with('alice', 'cv4f3axu', 'New room message')
			->willReturn(['@type' => 'LDAActionResponse', 'status' => 'success', 'id' => '1045']);

		$response = $this->controller('alice')->commentRoom('cv4f3axu');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
		$this->assertSame('1045', $response->getData()['id']);
	}
}

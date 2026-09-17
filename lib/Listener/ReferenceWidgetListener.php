<?php

declare(strict_types=1);

namespace OCA\Talk\Listener;

use OCA\Talk\AppInfo\Application;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<Event> */
class ReferenceWidgetListener implements IEventListener {
	private LoggerInterface $logger;

	public function __construct(LoggerInterface $logger) {
		$this->logger = $logger;
	}

	public function handle(Event $event): void {
		if (!$event instanceof RenderReferenceEvent) {
			return;
		}

		// NOTE: Util::addScript() resolves to "apps/spreed/js/{$file}.js" literally
		// (see OCP\Util::addScript) - the rspack build prefixes every output file
		// with the package.json "name" ("talk"), not the app id ("spreed"), so the
		Util::addScript(Application::APP_ID, 'talk-sml-widget');

	}
}

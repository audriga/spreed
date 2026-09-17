<?php

declare(strict_types=1);

namespace OCA\Talk\Collaboration\Reference;

use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\IReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class SmlReferenceProvider implements IReferenceProvider {

	private const RICH_OBJECT_TYPE = 'spreed-localhost-preview';

	// Mirrors OCP\Collaboration\Reference\LinkReferenceProvider::MAX_CONTENT_LENGTH
	private const MAX_CONTENT_LENGTH = 5 * 1024 * 1024;

	private IClientService $clientService;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IClientService $clientService,
		LoggerInterface $logger,
	) {
		$this->clientService = $clientService;
		$this->logger = $logger;
	}


	public function matchReference(string $referenceText): bool {
		$matches = (bool)preg_match(IURLGenerator::URL_REGEX, $referenceText);
		$this->logger->debug('[SmlReferenceProvider] matchReference: ' . $referenceText . ' => ' . ($matches ? 'true' : 'false'));
		$this->logger->error('[SmlReferenceProvider][DEBUG] matchReference: "' . $referenceText . '" => ' . ($matches ? 'true' : 'false'));
		return $matches;
	}

	public function resolveReference(string $referenceText): ?IReference {
		if (!$this->matchReference($referenceText)) {
			return null;
		}

		$this->logger->debug('[SmlReferenceProvider] resolveReference: ' . $referenceText);
		$this->logger->error('[SmlReferenceProvider][DEBUG] resolveReference: ' . $referenceText);

		$jsonLd = $this->fetchJsonLd($referenceText);
		if ($jsonLd === null) {
			$this->logger->debug('[SmlReferenceProvider] No JSON-LD found for: ' . $referenceText);
			$this->logger->error('[SmlReferenceProvider][DEBUG] No JSON-LD found for: ' . $referenceText . ' -- resolveReference returning null');
			return null;
		}

		$this->logger->debug('[SmlReferenceProvider] Setting rich object with type: ' . self::RICH_OBJECT_TYPE);
		$this->logger->error('[SmlReferenceProvider][DEBUG] SUCCESS - setting rich object type=' . self::RICH_OBJECT_TYPE . ' for ' . $referenceText);

		$reference = new Reference($referenceText);
		$reference->setTitle((string)($jsonLd['name'] ?? $referenceText));
		$reference->setDescription((string)($jsonLd['description'] ?? ''));
		$reference->setUrl($referenceText);

		$reference->setRichObject(self::RICH_OBJECT_TYPE, [
			'title' => $jsonLd['name'] ?? $referenceText,
			'description' => $jsonLd['description'] ?? '',
			'url' => $referenceText,
			'jsonld' => json_encode($jsonLd),
			'custom' => true,
		]);

		return $reference;
	}

	/**
	 * Fetches the URL and extracts a JSON-LD payload, either from a
	 * <script type="application/ld+json"> tag in an HTML document, or
	 * directly from the body when the URL points at a JSON(-LD) file.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fetchJsonLd(string $url): ?array {
		$client = $this->clientService->newClient();

		$this->logger->error('[SmlReferenceProvider][DEBUG] fetchJsonLd start: ' . $url);

		try {
			$headResponse = $client->head($url, ['timeout' => 5]);
		} catch (\Exception $e) {
			$this->logger->error('[SmlReferenceProvider][DEBUG] HEAD request threw for ' . $url . ': ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}

		$this->logger->error('[SmlReferenceProvider][DEBUG] HEAD status=' . $headResponse->getStatusCode()
			. ' content-type="' . $headResponse->getHeader('Content-Type') . '"'
			. ' content-length="' . $headResponse->getHeader('Content-Length') . '"'
			. ' content-disposition="' . $headResponse->getHeader('Content-Disposition') . '"'
			. ' all-headers=' . json_encode($headResponse->getHeaders()));

		$contentLength = $headResponse->getHeader('Content-Length');
		if (is_numeric($contentLength) && (int)$contentLength > self::MAX_CONTENT_LENGTH) {
			$this->logger->error('[SmlReferenceProvider][DEBUG] Skip resolving, content length ' . $contentLength . ' exceeds cap: ' . $url);
			return null;
		}

		$contentType = $headResponse->getHeader('Content-Type');
		// Some servers serve .json/.jsonld files with a generic content type
		// (text/plain, application/octet-stream), or the extension only shows
		// up in a query parameter (e.g. a "download?...&files=foo.json"
		// share link) rather than the path itself, so fall back to scanning
		// the whole URL for a ".json"/".jsonld" looking segment.
		$looksLikeJsonFile = (bool)preg_match('/\.json(?:ld)?(?:[?&#]|$)/i', $url);
		$isJson = (bool)preg_match('/^application\/(ld\+json|json);?/i', $contentType) || $looksLikeJsonFile;
		$isHtml = (bool)preg_match('/^text\/html;?/i', $contentType);

		$this->logger->error('[SmlReferenceProvider][DEBUG] Decision: contentType="' . $contentType . '" looksLikeJsonFile=' . ($looksLikeJsonFile ? 'true' : 'false')
			. ' isJson=' . ($isJson ? 'true' : 'false') . ' isHtml=' . ($isHtml ? 'true' : 'false'));

		if (!$isJson && !$isHtml) {
			$this->logger->error('[SmlReferenceProvider][DEBUG] Skip resolving, unsupported content type "' . $contentType . '": ' . $url);
			return null;
		}

		try {
			$response = $client->get($url, ['timeout' => 5, 'stream' => true]);
		} catch (\Exception $e) {
			$this->logger->error('[SmlReferenceProvider][DEBUG] GET request threw for ' . $url . ': ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}

		$this->logger->error('[SmlReferenceProvider][DEBUG] GET status=' . $response->getStatusCode()
			. ' content-type="' . $response->getHeader('Content-Type') . '"');

		$body = $response->getBody();
		if (!is_resource($body)) {
			$this->logger->error('[SmlReferenceProvider][DEBUG] Could not read response body (not a resource, type=' . gettype($body) . ') for ' . $url);
			return null;
		}

		$content = fread($body, self::MAX_CONTENT_LENGTH);
		if ($content === false) {
			$this->logger->error('[SmlReferenceProvider][DEBUG] fread() returned false for ' . $url);
			return null;
		}
		if (!feof($body)) {
			$this->logger->error('[SmlReferenceProvider][DEBUG] Skip resolving, response exceeds cap: ' . $url);
			return null;
		}

		$this->logger->error('[SmlReferenceProvider][DEBUG] Fetched ' . strlen($content) . ' bytes, first 300: ' . substr($content, 0, 300));

		if ($isJson) {
			$this->logger->error('[SmlReferenceProvider][DEBUG] Treating response as a direct JSON-LD payload: ' . $url);
			$decoded = $this->decodeJsonLd($content);
			if ($decoded === null) {
				$this->logger->error('[SmlReferenceProvider][DEBUG] decodeJsonLd() returned null, json_last_error=' . json_last_error_msg());
			}
			return $decoded;
		}

		$decoded = $this->extractJsonLd($content);
		if ($decoded === null) {
			$this->logger->error('[SmlReferenceProvider][DEBUG] extractJsonLd() found no usable <script type="application/ld+json"> in the HTML for ' . $url);
		}
		return $decoded;
	}

	/**
	 * Extracts the first valid JSON-LD payload found in a
	 * <script type="application/ld+json"> tag of an HTML document, if any.
	 *
	 * @return array<string, mixed>|null
	 */
	private function extractJsonLd(string $html): ?array {
		$previousState = libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_use_internal_errors($previousState);

		$scripts = $dom->getElementsByTagName('script');
		$candidateCount = 0;

		foreach ($scripts as $script) {
			if (strtolower($script->getAttribute('type')) !== 'application/ld+json') {
				continue;
			}
			$candidateCount++;

			$decoded = $this->decodeJsonLd($script->textContent);
			if ($decoded !== null) {
				return $decoded;
			}

			$this->logger->error('[SmlReferenceProvider][DEBUG] Found <script type="application/ld+json"> #' . $candidateCount . ' but it did not decode to a usable object, json_last_error=' . json_last_error_msg());
		}

		$this->logger->error('[SmlReferenceProvider][DEBUG] extractJsonLd: total <script> tags=' . $scripts->length . ', ld+json candidates=' . $candidateCount);

		return null;
	}

	/**
	 * Decodes a raw JSON(-LD) string into the first object it contains.
	 *
	 * @return array<string, mixed>|null
	 */
	private function decodeJsonLd(string $json): ?array {
		$decoded = json_decode($json, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
			return null;
		}

		// A single file/script can list multiple JSON-LD blocks as a
		// top-level array (e.g. "@graph"-less multi-entity export); take the
		// first object we find in that case.
		if (array_is_list($decoded)) {
			foreach ($decoded as $item) {
				if (is_array($item)) {
					return $item;
				}
			}
			return null;
		}

		return $decoded;
	}

	public function getCachePrefix(string $referenceId): string {
		return $referenceId;
	}

	public function getCacheKey(string $referenceId): ?string {
		return null;
	}
}




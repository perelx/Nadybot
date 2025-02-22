<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\Loop;
use Exception;
use Nadybot\Core\Attributes as NCA;
use ReflectionObject;
use Safe\Exceptions\StreamException;

/**
 * The AsyncHttp class provides means to make HTTP and HTTPS requests.
 *
 * This class should not be instanced as it is, but instead Http class's
 * get() or post() method should be used to create instance of the
 * AsyncHttp class.
 */
class AsyncHttp {
	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public SocketManager $socketManager;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/**
	 * Override the address to connect to for integration tests
	 *
	 * @internal
	 */
	public static ?string $overrideAddress = null;

	/**
	 * Override the port to connect to for integration tests
	 *
	 * @internal
	 */
	public static ?int $overridePort = null;

	/** The URI to connect to */
	private string $uri;

	/**
	 * The function to call when data has arrived
	 *
	 * @var null|callable
	 */
	private $callback;

	/** Additional parameter to pass to the callback function */
	private mixed $data;

	/** The HTTP method to use (GET/POST/PUT/DELETE) */
	private string $method;

	/**
	 * Additional headers tp send with the request
	 *
	 * @var array<string,mixed> [key => value]
	 */
	private array $headers = [];

	/** Timeout after not receiving any data for $timeout seconds */
	private ?int $timeout = null;

	/**
	 * The query parameters to send with out query
	 *
	 * @var array<string,string|int>
	 */
	private array $queryParams = [];

	/**
	 * The raw data to send with a post request
	 *
	 * @var ?string
	 */
	private ?string $postData = null;

	/**
	 * The socket to communicate with
	 *
	 * @var null|resource
	 * @psalm-var null|resource|closed-resource
	 */
	private $stream = null;

	/** The notifier to notify us when something happens in the queue */
	private ?SocketNotifier $notifier;

	/** The data to send with a request */
	private string $requestData = '';

	/** The incoming response data */
	private string $responseData = '';

	/**
	 * The position in the $responseData where the header ends
	 *
	 * @var int|false Either a position or false if not (yet known)
	 */
	private $headersEndPos = false;

	/**
	 * The headers of the response
	 *
	 * @var string[]
	 */
	private array $responseHeaders = [];

	/** The HttpRequest object */
	private HttpRequest $request;

	/**
	 * An error string or false if no error
	 *
	 * @var string|false
	 */
	private $errorString = false;

	/** The timer that tracks stream timeout */
	private ?string $timeoutEvent = null;

	/** Indicates if there's still a transaction running (true) or not (false) */
	private bool $finished;

	/** How often to retry in case of errors */
	private int $retriesLeft = 5;

	/** Create a new instance */
	public function __construct(string $method, string $uri) {
		$this->method   = $method;
		$this->uri      = $uri;
		$this->finished = false;
	}

	/**
	 * Executes the HTTP query.
	 *
	 * @internal
	 */
	public function execute(): void {
		if (!$this->buildRequest()) {
			return;
		}

		$this->initTimeout();

		if (!$this->createStream()) {
			return;
		}
		if ($this->request->getScheme() === "ssl") {
			$this->activateTLS();
		} else {
			$this->setupStreamNotify();
		}

		$this->logger->info("Sending request: {$this->request->getData()}", ["uri" => $this->uri]);
	}

	/**
	 * Abort the request with the given error message
	 *
	 * @internal
	 */
	public function abortWithMessage(string $errorString): void {
		$query = http_build_query($this->queryParams);
		if (strlen($query)) {
			$query = "?{$query}";
		}
		$message = "{$errorString} for uri: '{$this->uri}{$query}'";
		$this->setError($message);
		$this->finish();
	}

	public function handleTlsHandshake(): void {
		$this->logger->info("Trying to activate TLS", ["uri" => $this->uri]);
		if (!isset($this->stream) || !is_resource($this->stream)) {
			$this->logger->info("Activating TLS not possible for closed stream", ["uri" => $this->uri]);
			return;
		}
		$sslResult = stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
		if ($sslResult === true) {
			if (isset($this->notifier)) {
				$this->socketManager->removeSocketNotifier($this->notifier);
			}
			$this->logger->info("TLS crypto activated successfully", ["uri" => $this->uri]);
			$this->setupStreamNotify();
		} elseif ($sslResult === false) {
			if (isset($this->notifier)) {
				$this->socketManager->removeSocketNotifier($this->notifier);
			}
			$this->logger->info("TLS crypto failed to activate", ["uri" => $this->uri]);
			if ($this->retriesLeft > 0) {
				$this->retriesLeft--;
				$this->close();
				Loop::defer([$this, "execute"]);
				return;
			}
			$this->abortWithMessage(
				"Failed to activate TLS for the connection to ".
				$this->getStreamUri()
			);
			return;
		} elseif ($sslResult === 0) {
			// Do nothing, just wait for next tick
		}
	}

	/**
	 * Handler method which will be called when activity occurs in the SocketNotifier.
	 *
	 * @internal
	 */
	public function onStreamActivity(int $type): void {
		if ($this->finished) {
			return;
		}

		switch ($type) {
			case SocketNotifier::ACTIVITY_READ:
				$this->processResponse();
				break;

			case SocketNotifier::ACTIVITY_WRITE:
				try {
					$this->processRequest();
				} catch (HttpRetryException $e) {
					$this->close();
					$this->execute();
					return;
				}
				break;
		}
	}

	/** Set a headers to be send with the request */
	public function withHeader(string $header, mixed $value): self {
		$this->headers[$header] = $value;
		return $this;
	}

	/** Set the request timeout */
	public function withTimeout(int $timeout): self {
		$this->timeout = $timeout;
		return $this;
	}

	/**
	 * Defines a callback which will be called later on when the remote server has responded or an error has occurred.
	 *
	 * The callback has following signature:
	 * <code>function callback($response, $data)</code>
	 *  * $response - Response as an object, it has properties:
	 *                $error: error message, if any
	 *                $headers: received HTTP headers as an array
	 *                $body: received contents
	 *  * $data     - optional value which is same as given as argument to
	 *                this method.
	 *
	 * @psalm-param callable(HttpResponse,mixed...) $callback
	 */
	public function withCallback(callable $callback, mixed ...$data): self {
		$this->callback = $callback;
		$this->data     = $data;
		return $this;
	}

	/**
	 * Set the query parameters to send with the request
	 *
	 * @param array<string,int|string> $params array of key/value pair parameters passed as a query
	 */
	public function withQueryParams(array $params): self {
		$this->queryParams = $params;
		return $this;
	}

	/** Set the raw data to be sent with a post request */
	public function withPostData(string $data): self {
		$this->postData = $data;
		return $this;
	}

	/**
	 * Waits until response is fully received from remote server and returns the response.
	 * Note that this blocks execution, but does not freeze the bot
	 * as the execution will return to event loop while waiting.
	 */
	public function waitAndReturnResponse(): HttpResponse {
		// run in event loop, waiting for loop->quit()
		$loop = Loop::get();
		$refObj = new ReflectionObject($loop);
		$refMeth = $refObj->getMethod("tick");
		$refMeth->setAccessible(true);
		while (!$this->finished) {
			$refMeth->invoke($loop);
			usleep(10000);
		}

		return $this->buildResponse();
	}

	/** Create the internal request */
	private function buildRequest(): bool {
		try {
			$this->request = new HttpRequest($this->method, $this->uri, $this->queryParams, $this->headers, $this->postData);
			$this->requestData = $this->request->getData();
		} catch (InvalidHttpRequest $e) {
			$this->abortWithMessage($e->getMessage());
			return false;
		}
		return true;
	}

	/** Sets error to given $errorString. */
	private function setError(string $errorString): void {
		$this->errorString = $errorString;
		$this->logger->error($errorString, ["uri" => $this->uri]);
	}

	/**
	 * Finish the transaction either as times out or regular
	 *
	 * Call the registered callback
	 */
	private function finish(): void {
		$this->finished = true;
		if ($this->timeoutEvent) {
			Loop::cancel($this->timeoutEvent);
			$this->timeoutEvent = null;
		}
		$this->close();
		$this->callCallback();
	}

	/** Removes socket notifier from bot's reactor loop and closes the stream. */
	private function close(): void {
		if (isset($this->notifier)) {
			$this->socketManager->removeSocketNotifier($this->notifier);
			$this->notifier = null;
		}
		if (isset($this->stream) && is_resource($this->stream)) {
			@fclose($this->stream);
		}
	}

	/** Calls the user supplied callback. */
	private function callCallback(): void {
		if ($this->callback !== null) {
			$response = $this->buildResponse();
			call_user_func($this->callback, $response, ...$this->data);
		}
	}

	/** Return a response object */
	private function buildResponse(): HttpResponse {
		$response = new HttpResponse();
		$response->request = $this->request;
		if (empty($this->errorString)) {
			$response->headers = $this->responseHeaders;
			$response->body    = $this->getResponseBody();
		} else {
			$response->error   = $this->errorString;
		}

		return $response;
	}

	/** Initialize a timer to handle timeout */
	private function initTimeout(): void {
		if ($this->timeout === null) {
			$this->timeout = $this->settingManager->getInt("http_timeout") ?? 10;
		}

		$this->timeoutEvent = Loop::delay(
			$this->timeout * 1000,
			fn () => $this->timeout()
		);
	}

	private function timeout(): void {
		if ($this->retriesLeft === 0 || $this->method !== 'get') {
			$this->abortWithMessage("Timeout error after waiting {$this->timeout} seconds");
			return;
		}
		$this->logger->info("Request to {uri} timed out after {timeout}s, retrying", [
			"uri" => $this->uri,
			"timeout" => $this->timeout ?? "<unknown>",
		]);
		$this->retriesLeft--;
		$this->close();
		$this->execute();
	}

	/** Initialize the internal stream object */
	private function createStream(): bool {
		$streamUri = $this->getStreamUri();
		try {
			$this->stream = \Safe\stream_socket_client(
				$streamUri,
				$errno,
				$errstr,
				0,
				$this->getStreamFlags()
			);
			\Safe\stream_set_blocking($this->stream, false);
		} catch (StreamException $e) {
			$this->logger->error("Unable to connect to {uri}: {error}", [
				"uri" => $streamUri,
				"error" => $errstr ?? $e->getMessage(),
				"exception" => $e,
			]);
			return false;
		}
		$this->logger->info("Stream for {$streamUri} created", ["uri" => $this->uri]);
		return true;
	}

	/**
	 * Get the URI where to connect to
	 *
	 * Taking into account integration test overrides
	 */
	private function getStreamUri(): string {
		$host = self::$overrideAddress ? self::$overrideAddress : $this->request->getHost();
		$port = self::$overridePort ? self::$overridePort : $this->request->getPort();
		return "tcp://{$host}:{$port}";
	}

	/** Get the flags to set for the stream, taking Linux and Windows into account */
	private function getStreamFlags(): int {
		$flags = STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT;
		return $flags;
	}

	/** Turn on TLS as soon as we can write and then continue processing as usual */
	private function activateTLS(): void {
		if (!is_resource($this->stream)) {
			$this->logger->info("Activating TLS not possible for closed stream", ["uri" => $this->uri]);
			return;
		}
		$this->notifier = new SocketNotifier(
			$this->stream,
			SocketNotifier::ACTIVITY_WRITE,
			[$this, "handleTlsHandshake"]
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	/** Setup the event loop to notify us when something happens in the stream */
	private function setupStreamNotify(): void {
		if (!is_resource($this->stream)) {
			$this->logger->info("Setting up stream notification not possible for closed stream", ["uri" => $this->uri]);
			return;
		}
		$this->notifier = new SocketNotifier(
			$this->stream,
			SocketNotifier::ACTIVITY_READ | SocketNotifier::ACTIVITY_WRITE,
			[$this, 'onStreamActivity']
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	/** Process a received response */
	private function processResponse(): void {
		try {
			$this->responseData .= $this->readAllFromSocket();
		} catch (HttpRetryException $e) {
			$this->close();
			$this->execute();
			return;
		}

		if (!$this->isStreamClosed()) {
			return;
		}

		if (!$this->areHeadersReceived()) {
			$this->processHeaders();
		}

		if ($this->isBodyLengthKnown()) {
			if ($this->isBodyFullyReceived()) {
				$this->finish();
			} elseif ($this->isStreamClosed()) {
				$this->abortWithMessage("Stream closed before receiving all data");
			}
		} elseif ($this->isStreamClosed()) {
			$this->finish();
		}
	}

	/** Parse the headers from the received response */
	private function processHeaders(): void {
		$this->headersEndPos = strpos($this->responseData, "\r\n\r\n");
		if ($this->headersEndPos !== false) {
			$headerData = substr($this->responseData, 0, $this->headersEndPos);
			$this->responseHeaders = $this->extractHeadersFromHeaderData($headerData);
		}
	}

	/** Get the response body only */
	private function getResponseBody(): string {
		if ($this->headersEndPos === false) {
			return "";
		}
		return substr($this->responseData, $this->headersEndPos + 4);
	}

	/** Check if we've received any headers yet */
	private function areHeadersReceived(): bool {
		return $this->headersEndPos !== false;
	}

	/** Check if our connection is closed */
	private function isStreamClosed(): bool {
		return !isset($this->stream) || !is_resource($this->stream) || feof($this->stream);
	}

	/** Check if the whole body has been received yet */
	private function isBodyFullyReceived(): bool {
		return $this->getBodyLength() <= strlen($this->getResponseBody());
	}

	/** Check if we know how many bytes to expect from the body */
	private function isBodyLengthKnown(): bool {
		return $this->getBodyLength() !== null;
	}

	/** Read all data from the socket and return it */
	private function readAllFromSocket(): string {
		$data = '';
		while (true) {
			if (!isset($this->stream) || !is_resource($this->stream)) {
				throw new Exception("Trying to read from closed socket");
			}
			$chunk = fread($this->stream, 8192);
			if ($chunk === false) {
				if (feof($this->stream)) {
					if ($this->retriesLeft--) {
						if (isset($this->timeoutEvent)) {
							Loop::cancel($this->timeoutEvent);
							$this->timeoutEvent = null;
						}
						throw new HttpRetryException();
					}
					$this->abortWithMessage("Server unexpectedly closed connection");
					break;
				}
				$this->abortWithMessage("Failed to read 8192 bytes from the stream");
				break;
			}
			if (strlen($chunk) === 0) {
				break; // nothing to read, stop looping
			}
			$this->logger->debug("{count} bytes read from {uri}", [
				"count" => strlen($chunk),
				"uri" => $this->uri,
				"data" => $chunk,
			]);
			$data .= $chunk;
		}

		if (!empty($data) && isset($this->timeoutEvent)) {
			// since data was read, reset timeout
			Loop::cancel($this->timeoutEvent);
			$this->timeoutEvent = Loop::delay(
				($this->timeout ?? 10) * 1000,
				fn () => $this->timeout()
			);
		}

		return $data;
	}

	/** Get the length of the body or null if unknown */
	private function getBodyLength(): ?int {
		if (isset($this->responseHeaders['content-length'])) {
			return intval($this->responseHeaders['content-length']);
		}
		return null;
	}

	/**
	 * Parse the received headers into an associative array [header => value]
	 *
	 * @return array<string,string>
	 */
	private function extractHeadersFromHeaderData(string $data): array {
		$headers = [];
		$lines = explode("\r\n", $data);
		[$version, $status, $statusMessage] = explode(" ", array_shift($lines), 3);
		$headers['http-version'] = $version;
		$headers['status-code'] = $status;
		$headers['status-message'] = $statusMessage;
		foreach ($lines as $line) {
			if (preg_match('/([^:]+):(.+)/', $line, $matches)) {
				$headers[strtolower(trim($matches[1]))] = trim($matches[2]);
			}
		}
		return $headers;
	}

	/** Send the request and initialize timeouts, etc. */
	private function processRequest(): void {
		if (!strlen($this->requestData)) {
			return;
		}
		if (!isset($this->stream) || !is_resource($this->stream)) {
			throw new Exception("Trying to write to closed stream.");
		}
		$this->logger->debug("Trying to write {count} bytes to {uri}", [
			"count" => strlen($this->requestData),
			"uri" => $this->uri,
		]);
		$written = fwrite($this->stream, $this->requestData);
		if ($written === false) {
			if ($this->retriesLeft--) {
				if (isset($this->timeoutEvent)) {
					Loop::cancel($this->timeoutEvent);
					$this->timeoutEvent = null;
				}
				throw new HttpRetryException();
			} else {
				$this->abortWithMessage("Cannot write request headers to stream");
			}
		} elseif ($written > 0) {
			$this->logger->debug("{count} bytes written to {uri}", [
				"count" => $written,
				"uri" => $this->uri,
				"data" => substr($this->requestData, 0, $written),
			]);
			$this->requestData = substr($this->requestData, $written);

			// since data was written, reset timeout
			if (isset($this->timeoutEvent)) {
				Loop::cancel($this->timeoutEvent);
				$this->timeoutEvent = Loop::delay(
					($this->timeout??10) * 1000,
					fn () => $this->timeout(),
				);
			}
		}
	}
}

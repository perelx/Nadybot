<?php declare(strict_types=1);

namespace Nadybot\Core\Socket;

use Amp\Loop;
use Exception;
use InvalidArgumentException;
use Nadybot\Core\{
	Attributes as NCA,
	LoggerWrapper,
	SocketManager,
	SocketNotifier,
	Timer,
};
use Throwable;

class AsyncSocket {
	public const DATA = 'data';
	public const ERROR = 'error';
	public const CLOSE = 'close';

	public const ERROR_WRITE = 1;
	public const ERROR_CALLBACK = 2;
	public const ERROR_WRITE_TIMEOUT = 3;
	public const ERROR_TIMEOUT = 4;

	public const STATE_READY = 1;
	public const STATE_CLOSING = 2;
	public const STATE_CLOSED = 3;

	#[NCA\Inject]
	public SocketManager $socketManager;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Logger("Core/AsyncSocket")]
	public LoggerWrapper $logger;

	/**
	 * @var ?resource
	 * @psalm-var null|resource|closed-resource
	 */
	protected $socket = null;

	/** @var array<WriteClosureInterface|ShutdownRequest|string> */
	protected array $writeQueue = [];

	/** @var array<string,callable[]> */
	protected array $callbacks = [
		self::DATA  => [],
		self::ERROR => [],
		self::CLOSE => [],
	];

	protected SocketNotifier $notifier;
	protected ?string $timeoutHandler = null;

	protected ?float $lastRead = null;
	protected ?float $lastWrite = null;
	protected int $writeTries = 0;
	protected int $timeout = 5;
	protected int $state = self::STATE_READY;

	/** @param resource $socket */
	public function __construct($socket) {
		try {
			$this->socket = $socket;
			\Safe\stream_set_blocking($this->socket, false);
		} catch (Throwable $e) {
			throw new InvalidArgumentException("Argument 1 to " . get_class() . "::__construct() must be a socket.");
		}
	}

	public function __destruct() {
		if (isset($this->logger)) {
			$this->logger->info(get_class() . ' destroyed');
		}
	}

	/**
	 * Get the low level socket
	 *
	 * @return null|resource
	 * @psalm-return null|resource|closed-resource
	 */
	public function getSocket() {
		return $this->socket;
	}

	/** @return array<WriteClosureInterface|ShutdownRequest|string> */
	public function getWriteQueue(): array {
		return $this->writeQueue;
	}

	public function setTimeout(int $timeout): self {
		$this->timeout = $timeout;
		if ($timeout > 0 && is_resource($this->socket)) {
			\Safe\stream_set_timeout($this->socket, $timeout);
		}
		return $this;
	}

	public function getState(): int {
		return $this->state;
	}

	public function getTimeout(): int {
		return $this->timeout;
	}

	/** Subscribe to the event $event, resulting into $callback being called on trigger */
	public function on(string $event, callable $callback): self {
		if (isset($this->callbacks[$event])) {
			$this->callbacks[$event] []= $callback;
		}
		if ($event === self::DATA) {
			$mayListenToReads = !count($this->writeQueue) || !($this->writeQueue[0] instanceof WriteClosureInterface) || $this->writeQueue[0]->allowReading();
			if ($mayListenToReads) {
				$this->subscribeSocketEvent(SocketNotifier::ACTIVITY_READ);
			}
		}
		return $this;
	}

	public function destroy(): void {
		$this->logger->debug('Destroying ' . get_class());
		$this->callbacks = [];
		$this->socket = null;
		if (isset($this->notifier)) {
			$this->socketManager->removeSocketNotifier($this->notifier);
			unset($this->notifier);
		}
		unset($this->socketManager);
		if (isset($this->timeoutHandler)) {
			Loop::cancel($this->timeoutHandler);
			unset($this->timeoutHandler);
		}
		unset($this->timer);
		// unset($this->logger);
		$this->writeQueue = [];
	}

	/** Async send data via the socket */
	public function write(string $data): bool {
		$this->writeQueue []= $data;
		$this->subscribeSocketEvent(SocketNotifier::ACTIVITY_WRITE);
		return true;
	}

	/** Async close the socket gracefully */
	public function close(): bool {
		if ($this->state === self::STATE_CLOSED) {
			return true;
		}
		if ($this->state === self::STATE_CLOSING) {
			$this->forceClose();
			return true;
		}
		$this->writeQueue []= new ShutdownRequest();
		$this->subscribeSocketEvent(SocketNotifier::ACTIVITY_WRITE);
		return true;
	}

	/** Async send data via the socket by calling a closure to do the job (i.e. SSL encryption) */
	public function writeClosureInterface(WriteClosureInterface $callback): bool {
		$this->writeQueue []= $callback;
		$this->subscribeSocketEvent(SocketNotifier::ACTIVITY_WRITE);
		if (!$callback->allowReading()) {
			$this->unsubscribeSocketEvent(SocketNotifier::ACTIVITY_READ);
		}
		return true;
	}

	/** Reset the timeout, because there was stream activity */
	protected function refreshTimeout(): void {
		if ($this->timeout <=0) {
			return;
		}
		if (isset($this->timeoutHandler)) {
			Loop::cancel($this->timeoutHandler);
		}
		$this->timeoutHandler = Loop::delay(
			$this->timeout * 1000,
			function () {
				unset($this->timeoutHandler);
				$this->streamTimeout();
			}
		);
	}

	protected function streamTimeout(): void {
		if ($this->state === static::STATE_CLOSED) {
			return;
		}
		$this->logger->info('Connection timeout');
		if ($this->state === static::STATE_CLOSING) {
			$this->logger->info('Forcefully closing socket');
			if (is_resource($this->socket)) {
				@fclose($this->socket);
			}
			$this->destroy();
			return;
		}
		$this->trigger(static::ERROR, static::ERROR_TIMEOUT);
		$this->close();
	}

	/**
	 * Callback for the SocketManager where we handle low-level socket events
	 *
	 * @throws Exception                on OOB data
	 * @throws InvalidArgumentException on unknown socket activity
	 */
	protected function socketCallback(int $type): void {
		if ($type === SocketNotifier::ACTIVITY_READ) {
			$this->logger->debug('Socket ready for READ');
			$this->lastRead = microtime(true);
			$this->refreshTimeout();
			if (is_resource($this->socket) && feof($this->socket)) {
				if ($this->state === static::STATE_CLOSING) {
					$this->logger->info('Endpoint confirmed close.');
				} else {
					$this->logger->info('Endpoint closed connection');
					$this->unsubscribeSocketEvent(SocketNotifier::ACTIVITY_WRITE);
				}
				$this->unsubscribeSocketEvent(SocketNotifier::ACTIVITY_READ);
				$this->trigger(static::CLOSE);
				if (isset($this->timeoutHandler)) {
					Loop::cancel($this->timeoutHandler);
				}
				$this->state = static::STATE_CLOSED;
			} else {
				$this->trigger(static::DATA);
			}
		} elseif ($type === SocketNotifier::ACTIVITY_WRITE) {
			$this->logger->debug('Socket ready for WRITE');
			$this->processQueue();
		} else {
			throw new InvalidArgumentException("Unknown socket activity {$type}");
		}
	}

	protected function initNotifier(): void {
		if (isset($this->notifier) || $this->state === static::STATE_CLOSED || !is_resource($this->socket)) {
			return;
		}
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ,
			function (int $action): void {
				$this->socketCallback($action);
			}
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	/** Trigger an event and call all registered callbacks */
	protected function trigger(string $event, mixed ...$params): void {
		foreach ($this->callbacks[$event] as $callback) {
			$callback($this, ...$params);
		}
		if ($event === self::CLOSE) {
			$this->destroy();
		}
	}

	protected function forceClose(): void {
		$this->logger->info('Force closing connection');
		if (!isset($this->socket) || !is_resource($this->socket)) {
			return;
		}
		@fclose($this->socket);
		$this->unsubscribeSocketEvent(SocketNotifier::ACTIVITY_READ);
		$this->unsubscribeSocketEvent(SocketNotifier::ACTIVITY_WRITE);
		$this->state = static::STATE_CLOSED;
		$this->trigger(static::CLOSE);
	}

	/** Subscribe to an event of the low level socket event queue */
	protected function subscribeSocketEvent(int $type): void {
		$this->initNotifier();
		if (($this->notifier->getType() & $type) === $type) {
			return;
		}
		$this->logger->info(
			'Subscribing to socket event ' . $type . ' ('.
			(($type === SocketNotifier::ACTIVITY_READ) ? 'read' : 'write').
			')'
		);
		$this->socketManager->removeSocketNotifier($this->notifier);
		$this->notifier = new SocketNotifier(
			$this->notifier->getSocket(),
			$this->notifier->getType() | $type,
			$this->notifier->getCallback()
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	/** Unsubscribe from an event of the low level socket event queue */
	protected function unsubscribeSocketEvent(int $type): void {
		$this->initNotifier();
		if (!isset($this->socketManager) || !isset($this->notifier) || (($this->notifier->getType() & $type) === 0)) {
			return;
		}
		$this->logger->info(
			'Unsubscribing from socket event ' . $type . ' ('.
			(($type === SocketNotifier::ACTIVITY_READ) ? 'read' : 'write').
			')'
		);

		$this->socketManager->removeSocketNotifier($this->notifier);
		$this->notifier = new SocketNotifier(
			$this->notifier->getSocket(),
			$this->notifier->getType() - $type,
			$this->notifier->getCallback()
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	/** Send out data from our queue (if any) */
	protected function processQueue(): void {
		if (empty($this->writeQueue)) {
			$this->logger->info('writeQueue empty');
			$this->unsubscribeSocketEvent(SocketNotifier::ACTIVITY_WRITE);
			return;
		}
		$data = array_shift($this->writeQueue);
		if (is_string($data)) {
			$this->logger->info('Writing data');
			$this->writeData($data);
		} elseif ($data instanceof WriteClosureInterface) {
			$this->logger->info('Writing closure');
			$this->writeClosure($data);
		} elseif ($data instanceof ShutdownRequest) {
			if ($this->state !== static::STATE_READY) {
				return;
			}
			$this->logger->info('Closing socket');
			if (!is_resource($this->socket) || @\stream_socket_shutdown($this->socket, STREAM_SHUT_WR) === false) {
				$this->forceClose();
				return;
			}
			$this->lastWrite = microtime(true);
			$this->state = static::STATE_CLOSING;
			$this->writeQueue = [];
			$this->unsubscribeSocketEvent(SocketNotifier::ACTIVITY_WRITE);
			$this->refreshTimeout();
		}
		if (empty($this->writeQueue)) {
			$this->logger->info('writeQueue empty');
			$this->unsubscribeSocketEvent(SocketNotifier::ACTIVITY_WRITE);
		}
	}

	/** Have some callback utilize the socket until it returns true or false */
	protected function writeClosure(WriteClosureInterface $callback): bool {
		$result = $callback->exec($this);
		if ($result === true) {
			$this->logger->info('Closure returned success');
			$this->lastWrite = microtime(true);
			$this->refreshTimeout();
			$this->subscribeSocketEvent(SocketNotifier::ACTIVITY_READ);
			return true;
		}
		if ($result === false) {
			$this->logger->warning('Writing closure failed: ' . (error_get_last()['message'] ?? "unknown error"));
			$this->trigger(
				self::ERROR,
				self::ERROR_CALLBACK,
				"Callback returned failure."
			);
			$this->forceClose();
			return false;
		}
		array_unshift($this->writeQueue, $callback);
		return true;
	}

	/**
	 * Low level write $data to the socket and take care of retries
	 *
	 * @throws Exception on wrong parameters
	 */
	protected function writeData(string $data): bool {
		if (strlen($data) === 0) {
			return true;
		}
		// This can be cost intensive to calculate, so only do it if really needed
		if ($this->logger->isEnabledFor('TRACE')) {
			$this->logger->debug(
				'Writing "'.
				preg_replace_callback(
					"/[^\32-\126]/",
					function (array $match): string {
						if ($match[0] === "\r") {
							return "\\r";
						}
						if ($match[0] === "\n") {
							return "\\n";
						}
						return "\\" . sprintf("%02X", ord($match[0]));
					},
					$data
				).
				'"'
			);
		}
		$written = is_resource($this->socket) ? \Safe\fwrite($this->socket, $data, 4096) : false;
		if ($written === false) {
			$this->forceClose();
			return false;
		}
		$data = substr($data, $written);
		if (strlen($data)) {
			array_unshift($this->writeQueue, $data);
		}
		if ($written === 0) {
			$this->writeTries++;
			if ($this->writeTries < 10) {
				return true;
			}
			$this->trigger(
				static::ERROR,
				static::ERROR_WRITE,
				"Unable to write " . strlen($data) . " bytes."
			);
			return false;
		}
		$this->lastWrite = microtime(true);
		$this->refreshTimeout();
		$this->writeTries = 0;
		return true;
	}
}

<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\{DateTimeImmutable, Logger};
use Nadybot\Core\{
	Attributes as NCA,
	Routing\RoutableMessage,
	Routing\Source,
};
use Safe\Exceptions\FilesystemException;
use Throwable;

/**
 * A wrapper class to monolog
 */
#[NCA\Instance("logger")]
class LoggerWrapper {
	#[NCA\Inject]
	public ConfigFile $config;

	protected static bool $routeErrors = true;

	protected static PsrLogMessageProcessor $logProcessor;

	/**
	 * @var array<array>
	 * @phpstan-var array<array{100|200|250|300|400|500|550|600,string,array<string,mixed>}>
	 */
	protected static array $routingQueue = [];

	protected static bool $errorGiven = false;

	/** The actual Monolog logger */
	private Logger $logger;

	/** The actual Monolog logger for tag CHAT */
	private ?Logger $chatLogger = null;

	public function __construct(string $tag) {
		$this->logger = LegacyLogger::fromConfig($tag);
		if (!isset(self::$logProcessor)) {
			self::$logProcessor = new PsrLogMessageProcessor(null, true);
		}
	}

	/**
	 * Detailed debug information, including data like traces
	 *
	 * @param array<string,mixed> $context
	 */
	public function debug(string $message, array $context=[]): void {
		$this->passthru(Logger::DEBUG, $message, $context);
	}

	/**
	 * Information that describes what's generally been done right now
	 *
	 * @param array<string,mixed> $context
	 */
	public function info(string $message, array $context=[]): void {
		$this->passthru(Logger::INFO, $message, $context);
	}

	/**
	 * Something important, like a milestone, has been reached,
	 * or generally something the bot admin should always see
	 *
	 * @param array<string,mixed> $context
	 */
	public function notice(string $message, array $context=[]): void {
		$this->passthru(Logger::NOTICE, $message, $context);
	}

	/**
	 * Exceptional occurrences that are not errors.
	 * Examples:
	 * Use of deprecated APIs,
	 * poor use of an API,
	 * undesirable things that are not necessarily wrong.
	 *
	 * @param array<string,mixed> $context
	 */
	public function warning(string $message, array $context=[]): void {
		$this->passthru(Logger::WARNING, $message, $context);
	}

	/**
	 * Runtime errors that the bot can ignore and continue
	 *
	 * @param array<string,mixed> $context
	 */
	public function error(string $message, array $context=[]): void {
		$this->passthru(Logger::ERROR, $message, $context);
	}

	/**
	 * Urgent alerts that should not be ignored
	 *
	 * @param array<string,mixed> $context
	 */
	public function critical(string $message, array $context=[]): void {
		$this->passthru(Logger::CRITICAL, $message, $context);
	}

	/**
	 * Action must be taken immediately.
	 * Examples:
	 * Bot down,
	 * database unavailable,
	 * things that should trigger an sms alert and wake you up.
	 *
	 * @param array<string,mixed> $context
	 */
	public function alert(string $message, array $context=[]): void {
		$this->passthru(Logger::ALERT, $message, $context);
	}

	/**
	 * Urgent alert
	 *
	 * @param array<string,mixed> $context
	 */
	public function emergency(string $message, array $context=[]): void {
		$this->passthru(Logger::EMERGENCY, $message, $context);
	}

	/**
	 * Log a message according to log settings
	 *
	 * @param string     $category  The log category (TRACE, DEBUG, INFO, WARN, ERROR, FATAL)
	 * @param string     $message   The message to log
	 * @param ?Throwable $throwable Optional throwable information to include in the logging event
	 */
	public function log(string $category, string $message, ?Throwable $throwable=null): void {
		$level = LegacyLogger::getLoggerLevel($category);
		$context = [];
		if (isset($throwable)) {
			$context["exception"] = $throwable;
		}
		$this->logger->log($level, $message, $context);
	}

	/**
	 * Log a chat message, stripping potential HTML code from it
	 *
	 * @param string     $channel Either "Buddy" or an org or private-channel name
	 * @param string|int $sender  The name of the sender, or a number representing the channel
	 * @param string     $message The message to log
	 */
	public function logChat(string $channel, string|int $sender, string $message): void {
		if (!$this->config->showAomlMarkup) {
			$message = preg_replace("|<font.*?>|", "", $message);
			$message = preg_replace("|</font>|", "", $message);
			$message = preg_replace("|<a\\s+href=\".+?\">|s", "[link]", $message);
			$message = preg_replace("|<a\\s+href='.+?'>|s", "[link]", $message);
			$message = preg_replace("|<a\\s+href=.+?>|s", "[link]", $message);
			$message = preg_replace("|</a>|", "[/link]", $message);
		}

		if ($channel == "Buddy") {
			$line = "[{$channel}] {$sender} {$message}";
		} elseif ($sender == '-1' || $sender == '4294967295') {
			$line = "[{$channel}] {$message}";
		} else {
			$line = "[{$channel}] {$sender}: {$message}";
		}

		$this->chatLogger ??= $this->logger->withName("CHAT");
		$this->chatLogger->notice($line);
	}

	/** Get the relative path of the directory where logs of this bot are stored */
	public static function getLoggingDirectory(): string {
		$errorLog = \Safe\ini_get('error_log');
		if (!is_string($errorLog)) {
			throw new Exception("Your php.ini error_log is misconfigured.");
		}
		$logDir = dirname($errorLog);
		if (substr($logDir, 0, 1) !== '/') {
			try {
				$logDirNew = \Safe\realpath(dirname(__DIR__, 2) . '/' . $logDir);
			} catch (FilesystemException) {
				$logDirNew = dirname(__DIR__, 2) . '/' . $logDir;
			}
			$logDir = $logDirNew;
		}
		return $logDir;
	}

	/**
	 * Check if logging is enabled for a given category
	 *
	 * @param string $category The log category (TRACE, DEBUG, INFO, WARN, ERROR, FATAL)
	 */
	public function isEnabledFor(string $category): bool {
		$level = LegacyLogger::getLoggerLevel($category);
		return $this->isHandling($level);
	}

	/**
	 * Check if logging is enabled for a given level
	 *
	 * @param int $level The log level (Logger::DEBUG, etc.)
	 * @phpstan-param 100|200|250|300|400|500|550|600 $level
	 */
	public function isHandling(int $level): bool {
		return $this->logger->isHandling($level);
	}

	/**
	 * @phpstan-param 100|200|250|300|400|500|550|600 $logLevel
	 *
	 * @param array<string,mixed> $context
	 */
	private function passthru(int $logLevel, string $message, array $context): void {
		try {
			$this->logger->log($logLevel, $message, $context);
		} catch (Exception $e) {
			if (static::$errorGiven === true) {
				return;
			}
			static::$errorGiven = true;
			$this->passthru(Logger::ERROR, "Error logging: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
		if ($logLevel < Logger::NOTICE) {
			return;
		}
		if (!static::$routeErrors) {
			return;
		}
		if (!Registry::hasInstance(MessageHub::class)) {
			self::$routingQueue []= [$logLevel, $message, $context];
			return;
		}
		$msgHub = Registry::getInstance(MessageHub::class);
		if (!isset($msgHub) || !($msgHub instanceof MessageHub)) {
			return;
		}
		if (!$msgHub->routingLoaded) {
			self::$routingQueue []= [$logLevel, $message, $context];
			return;
		}
		self::$routingQueue []= [$logLevel, $message, $context];
		while (count(self::$routingQueue) > 0) {
			[$logLevel, $message, $context] = array_shift(self::$routingQueue);
			static::$routeErrors = false;
			try {
				$loggingCategory = Logger::getLevelName($logLevel);
				$renderedMessage = (self::$logProcessor)([
					'message' => $message,
					'context' => $context,
					'level' => $logLevel,
					'level_name' => $loggingCategory,
					'channel' => $loggingCategory,
					'datetime' => new DateTimeImmutable(false),
					'extra' => [],
				]);
				$rMessage = new RoutableMessage($renderedMessage["message"]);
				$rMessage->appendPath(
					new Source(Source::LOG, $loggingCategory)
				);
				$msgHub->handle($rMessage);
			} catch (Throwable) {
			} finally {
				static::$routeErrors = true;
			}
		}
	}
}

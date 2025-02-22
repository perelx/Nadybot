<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	Channels\WebChannel,
	Event,
	EventManager,
	LoggerWrapper,
	MessageHub,
	ModuleInstance,
	PacketEvent,
	Registry,
	WebsocketBase,
	WebsocketCallback,
	WebsocketServer,
};
use Nadybot\Modules\WEBSERVER_MODULE\{
	CommandReplyEvent,
	HttpProtocolWrapper,
	JsonExporter,
	Request,
	Response,
	WebChatConverter,
};

use Throwable;
use TypeError;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\ProvidesEvent("websocket(subscribe)"),
	NCA\ProvidesEvent("websocket(request)"),
	NCA\ProvidesEvent("websocket(response)"),
	NCA\ProvidesEvent("websocket(event)")
]
class WebsocketController extends ModuleInstance {
	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public WebChatConverter $webChatConverter;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** Enable the websocket handler */
	#[NCA\Setting\Boolean]
	public bool $websocket = true;

	/** @var array<string,WebsocketServer> */
	protected array $clients = [];

	#[NCA\Setup]
	public function setup(): void {
		if ($this->websocket) {
			$this->registerWebChat();
		}
	}

	#[NCA\SettingChangeHandler('websocket')]
	public function changeWebsocketStatus(string $setting, string $oldValue, string $newValue, mixed $extraData): void {
		if ($newValue === "1") {
			$this->registerWebChat();
		} else {
			$this->unregisterWebChat();
		}
	}

	#[
		NCA\HttpGet("/events"),
	]
	public function handleWebsocketStart(Request $request, HttpProtocolWrapper $server): void {
		if (!$this->websocket) {
			return;
		}
		$response = $this->getResponseForWebsocketRequest($request);
		$server->sendResponse($response, $request);
		if ($response->code !== Response::SWITCHING_PROTOCOLS) {
			return;
		}
		$websocketHandler = new WebsocketServer($server->getAsyncSocket());
		Registry::injectDependencies($websocketHandler);
		$server->getAsyncSocket()->destroy();
		unset($server);
		$websocketHandler->serve();
		$websocketHandler->checkTimeout();
		$websocketHandler->on(WebsocketServer::ON_TEXT, [$this, "clientSentData"]);
		$websocketHandler->on(WebsocketServer::ON_CLOSE, [$this, "clientDisconnected"]);
		$websocketHandler->on(WebsocketServer::ON_ERROR, [$this, "clientError"]);

		$this->logger->info("Upgrading connection to WebSocket");
		$packet = new WebsocketCommand();
		$packet->command = "uuid";
		$packet->data = $websocketHandler->getUUID();
		$websocketHandler->send(JsonExporter::encode($packet), 'text');
	}

	public function clientConnected(WebsocketCallback $event): void {
		$this->logger->info("New Websocket connection from ".
			($event->websocket->getPeer() ?? "unknown"));
	}

	public function clientDisconnected(WebsocketCallback $event): void {
		$this->logger->info("Closed Websocket connection from ".
			($event->websocket->getPeer() ?? "unknown"));
	}

	public function clientError(WebsocketCallback $event): void {
		$this->logger->info("Websocket client error from ".
			($event->websocket->getPeer() ?? "unknown"));
		$event->websocket->close();
	}

	/** Handle the Websocket client sending data */
	public function clientSentData(WebsocketCallback $event): void {
		$this->logger->info("[Data inc.] {$event->data}");
		try {
			if (!is_string($event->data)) {
				throw new Exception();
			}
			$data = \Safe\json_decode($event->data);
			$command = new WebsocketCommand();
			$command->fromJSON($data);
			if (!in_array($command->command, $command::ALLOWED_COMMANDS)) {
				throw new Exception();
			}
		} catch (Throwable $e) {
			$event->websocket->close(4002);
			return;
		}
		if ($command->command === $command::SUBSCRIBE) {
			$newEvent = new WebsocketSubscribeEvent();
			$newEvent->type = "websocket(subscribe)";
			$newEvent->data = new NadySubscribe();
		} elseif ($command->command === $command::REQUEST) {
			$newEvent = new WebsocketRequestEvent();
			$newEvent->type = "websocket(request)";
			$newEvent->data = new NadyRequest();
		} else {
			// Unknown command received is just silently ignored in case another handler deals with it
			return;
		}
		try {
			if (!is_object($command->data)) {
				throw new Exception("Invalid data received");
			}
			$newEvent->data->fromJSON($command->data);
		} catch (Throwable $e) {
			$event->websocket->close(4002);
			return;
		}
		$this->eventManager->fireEvent($newEvent, $event->websocket);
	}

	#[NCA\Event(
		name: "websocket(subscribe)",
		description: "Handle Websocket event subscriptions",
		defaultStatus: 1
	)]
	public function handleSubscriptions(WebsocketSubscribeEvent $event, WebsocketServer $server): void {
		try {
			if (!isset($event->data->events) || !is_array($event->data->events)) {
				return;
			}
			$server->subscribe(...$event->data->events);
			$this->logger->info('Websocket subscribed to ' . join(",", $event->data->events));
		} catch (TypeError $e) {
			if (isset($event->websocket)) {
				$event->websocket->close(4002);
			}
		}
	}

	#[NCA\Event(
		name: "websocket(request)",
		description: "Handle API requests"
	)]
	public function handleRequests(WebsocketRequestEvent $event, WebsocketServer $server): void {
		// Not implemented yet
	}

	#[NCA\Event(
		name: "*",
		description: "Distribute events to Websocket clients",
		defaultStatus: 1
	)]
	public function displayEvent(Event $event): void {
		$isPrivatPacket = $event->type === 'msg'
			|| $event instanceof PacketEvent
			|| $event instanceof WebsocketEvent;
		// Packages that might contain secret or private information must never be relayed
		if ($isPrivatPacket) {
			return;
		}
		$packet = new WebsocketCommand();
		$packet->command = $packet::EVENT;
		$packet->data = $event;
		foreach ($this->clients as $uuid => $client) {
			if ($event instanceof CommandReplyEvent && $event->uuid !== $uuid) {
				continue;
			}
			foreach ($client->getSubscriptions() as $subscription) {
				if ($subscription === $event->type
					|| fnmatch($subscription, $event->type)) {
					$client->send(JsonExporter::encode($packet), 'text');
					$this->logger->info("Sending {class} to Websocket client", [
						"class" => get_class($event),
						"packet" => $packet,
					]);
				}
			}
		}
	}

	/** Register a Websocket client connection, so we can send commands/events to it */
	public function registerClient(WebsocketServer $client): void {
		$this->clients[$client->getUUID()] = $client;
	}

	/** Register a Websocket client connection, so we don't keep a reference to it */
	public function unregisterClient(WebsocketServer $client): void {
		unset($this->clients[$client->getUUID()]);
	}

	/** Check if a Websocket client connection exists */
	public function clientExists(string $uuid): bool {
		return isset($this->clients[$uuid]);
	}

	protected function registerWebChat(): void {
		$wc = new WebChannel();
		Registry::injectDependencies($wc);
		$this->messageHub
			->registerMessageEmitter($wc)
			->registerMessageReceiver($wc);
	}

	protected function unregisterWebChat(): void {
		$wc = new WebChannel();
		Registry::injectDependencies($wc);
		$this->messageHub
			->unregisterMessageEmitter($wc->getChannelName())
			->unregisterMessageReceiver($wc->getChannelName());
	}

	/** Check if the upgrade was requested correctly and return either error or upgrade response */
	protected function getResponseForWebsocketRequest(Request $request): Response {
		$errorResponse = new Response(
			Response::UPGRADE_REQUIRED,
			[
				"Connection" => "Upgrade",
				"Upgrade" => "websocket",
				"Sec-WebSocket-Version" => "13",
				// "Sec-WebSocket-Protocol" => "nadybot",
			]
		);
		$clientRequestedWebsocket = isset($request->headers["upgrade"])
			&& strtolower($request->headers["upgrade"]) === "websocket";
		if (!$clientRequestedWebsocket) {
			$this->logger->info('Client accessed WebSocket endpoint without requesting upgrade');
			return $errorResponse;
		}
		if (!isset($request->headers["sec-websocket-key"])) {
			$this->logger->info('WebSocket client did not give key');
			return new Response(Response::BAD_REQUEST);
		}
		$key = $request->headers["sec-websocket-key"];
		if (isset($request->headers["sec-websocket-protocol"])
			&& !in_array("nadybot", \Safe\preg_split("/\s*,\s*/", $request->headers["sec-websocket-protocol"]))) {
			return $errorResponse;
		}

		/** @todo Validate key length and base 64 */
		$responseKey = base64_encode(\Safe\pack('H*', sha1($key . WebsocketBase::GUID)));
		return new Response(
			Response::SWITCHING_PROTOCOLS,
			[
				"Connection" => "Upgrade",
				"Upgrade" => "websocket",
				"Sec-WebSocket-Accept" => $responseKey,
				// "Sec-WebSocket-Protocol" => "nadybot",
			]
		);
	}
}

<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\CmdContext;
use Nadybot\Core\EventManager;
use Nadybot\Core\Nadybot;

/**
 * @Instance
 *
 * @author Legendadv (RK2)
 * @author Derroylo (RK2)
 * @author Marebone (RK2)
 *
 * The ChatSayController class allows user to send messages to either org
 * channel or to private (guest) channel.
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'say',
 *		accessLevel = 'rl',
 *		description = 'Sends message to org chat or private chat',
 *		help        = 'say.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'tell',
 *		accessLevel = 'rl',
 *		description = 'Repeats a message 3 times',
 *      help        = 'tell.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'cmd',
 *		accessLevel = 'rl',
 *		description = 'Creates a highly visible message',
 *      help        = 'cmd.txt'
 *	)
 *	@ProvidesEvent("leadersay")
 *	@ProvidesEvent("leadercmd")
 */
class ChatSayController {

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public ChatLeaderController $chatLeaderController;

	/** @Inject */
	public EventManager $eventManager;

	/**
	 * This command handler sends message to org chat.
	 * @HandlesCommand("say")
	 * @Mask $channel org
	 */
	public function sayOrgCommand(CmdContext $context, string $channel, string $message): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->chatBot->sendGuild("{$context->char->name}: {$message}");
		$event = new SayEvent();
		$event->type = "leadersay";
		$event->message = $message;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * This command handler sends message to private channel.
	 * @HandlesCommand("say")
	 * @Mask $channel priv
	 */
	public function sayPrivCommand(CmdContext $context, string $channel, string $message): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->chatBot->sendPrivate("{$context->char->name}: {$message}");
		$event = new SayEvent();
		$event->type = "leadersay";
		$event->message = $message;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * This command handler creates a highly visible message.
	 * @HandlesCommand("cmd")
	 */
	public function cmdCommand(CmdContext $context, string $message): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$msg = "\n".
			"<yellow>------------------------------------------<end>\n".
			"<tab><red>{$message}<end>\n".
			"<yellow>------------------------------------------<end>";

		if ($context->isDM()) {
			$this->chatBot->sendGuild($msg, true);
			$this->chatBot->sendPrivate($msg, true);
		} else {
			$context->reply($msg);
		}
		$event = new SayEvent();
		$event->type = "leadercmd";
		$event->message = $message;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * This command handler repeats a message 3 times.
	 * @HandlesCommand("tell")
	 */
	public function tellCommand(CmdContext $context, string $message): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$message = "<yellow>{$message}<end>";
		for ($i = 0; $i < 3; $i++) {
			if ($context->isDM()) {
				$this->chatBot->sendGuild($message, true);
				$this->chatBot->sendPrivate($message, true);
			} else {
				$context->reply($message);
			}
		}
	}
}

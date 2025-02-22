<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use Exception;
use Generator;
use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	DB,
	LoggerWrapper,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PRemove,
	ParamClass\PWord,
	SQLException,
	SettingManager,
	Text,
	Util,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "comment",
		accessLevel: "member",
		description: "read/write comments about players",
		alias: 'comments',
	),
	NCA\DefineCommand(
		command: "comment categories",
		accessLevel: "mod",
		description: "Manage comment categories",
	),
]
class CommentController extends ModuleInstance {
	public const ADMIN = "admin";
	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** How long is the cooldown between leaving 2 comments for the same character */
	#[NCA\Setting\Time(options: ["1s", "1h", "6h", "24h"])]
	public int $commentCooldown = 6 * 3600;

	/** Share comments between bots on same database */
	#[NCA\Setting\Boolean]
	public bool $shareComments = false;

	/** Database table for comments */
	#[NCA\Setting\Text(mode: "noedit")]
	public string $tableNameComments = "comments_<myname>";

	/** Database table for comment categories */
	#[NCA\Setting\Text(mode: "noedit")]
	public string $tableNameCommentCategories = "comment_categories_<myname>";

	#[NCA\Setup]
	public function setup(): void {
		$sm = $this->settingManager;
		$this->db->registerTableName("comments", $this->tableNameComments);
		$this->db->registerTableName("comment_categories", $this->tableNameCommentCategories);
	}

	#[NCA\SettingChangeHandler("share_comments")]
	public function changeTableSharing(string $settingName, string $oldValue, string $newValue, mixed $data): void {
		if ($oldValue === $newValue) {
			return;
		}
		$this->logger->info("Comment sharing changed");
		$oldCommentTable = $this->tableNameComments;
		$oldCategoryTable = $this->tableNameCommentCategories;
		$this->db->beginTransaction();
		try {
			// read all current entries
			/** @var Comment[] */
			$comments = $this->db->table("<table:comments>")->asObj(Comment::class)->toArray();

			/** @var CommentCategory[] */
			$cats = $this->db->table("<table:comment_categories>")->asObj(CommentCategory::class)->toArray();
			if ($newValue === "1") {
				// save new name
				$newCommentTable = "comments";
				$newCategoryTable = "comment_categories";
				if (!$this->db->schema()->hasTable("comments")) {
					$this->logger->notice('Creating table comments');
					$this->db->schema()->create("comments", function (Blueprint $table): void {
						$table->id();
						$table->string("character", 15)->index();
						$table->string("created_by", 15);
						$table->integer("created_at");
						$table->string("category", 20)->index();
						$table->text("comment");
					});
				}
				if (!$this->db->schema()->hasTable("comment_categories")) {
					$this->db->schema()->create("comment_categories", function (Blueprint $table): void {
						$table->string("name", 20)->primary();
						$table->string("created_by", 15);
						$table->integer("created_at");
						$table->string("min_al_read", 25)->default('all');
						$table->string("min_al_write", 25)->default('all');
						$table->boolean("user_managed")->default(true);
					});
				}
			} else {
				// save new name
				$newCommentTable = "comments_<myname>";
				$newCategoryTable = "comment_categories_<myname>";
			}
			$this->db->registerTableName("comments", $newCommentTable);
			$this->db->registerTableName("comment_categories", $newCategoryTable);
			// make sure own table schema exists
			$this->logger->notice("Ensuring new tables and indexes exist");
			// copy all categories and comments to the shared table if they do not exist already
			$this->logger->notice("Copying comment categories from {$oldCategoryTable} to {$newCategoryTable}.");
			foreach ($cats as $cat) {
				$exists = $this->db->table("<table:comment_categories>")
					->where("name", $cat->name)->exists();
				if (!$exists) {
					$this->db->insert("<table:comment_categories>", $cat, null);
				}
			}
			$this->logger->notice("Copying comments from {$oldCommentTable} to {$newCommentTable}.");
			foreach ($comments as $comment) {
				$exists = $this->db->table("<table:comments>")
					->where("category", $comment->category)
					->where("character", $comment->character)
					->where("created_by", $comment->created_by)
					->where("comment", $comment->comment)
					->exists();
				if (!$exists) {
					unset($comment->id);
					$this->db->insert("<table:comments>", $comment);
				}
			}
		} catch (SQLException $e) {
			$this->logger->error("Error changing comment tables: " . $e->getMessage(), ["exception" => $e]);
			$this->db->rollback();
			$this->db->registerTableName("comments", $oldCommentTable);
			$this->db->registerTableName("comment_categories", $oldCategoryTable);
			throw new Exception("There was an error copying the comments in the database");
		}
		$this->db->commit();
		$this->settingManager->save("table_name_comments", $newCommentTable);
		$this->settingManager->save("table_name_comment_categories", $newCategoryTable);
		$this->logger->notice("All comments and categories copied successfully");
	}

	/** Read a single category by its name */
	public function getCategory(string $category): ?CommentCategory {
		return $this->db->table("<table:comment_categories>")
			->whereIlike("name", $category)
			->asObj(CommentCategory::class)->first();
	}

	/** Create a new category */
	public function saveCategory(CommentCategory $category): int {
		return $this->db->insert("<table:comment_categories>", $category, null);
	}

	/**
	 * Delete a single category by its name
	 *
	 * @return int|null Number of deleted comments or null if the category didn't exist
	 */
	public function deleteCategory(string $category): ?int {
		$deletedComments = $this->db->table("<table:comments>")
			->whereIlike("category", $category)
			->delete();
		$deletedCategories = $this->db->table("<table:comment_categories>")
			->whereIlike("name", $category)
			->delete();
		return $deletedCategories ? $deletedComments : null;
	}

	/** Get a list of all defined comment categories */
	#[NCA\HandlesCommand("comment categories")]
	public function listCategoriesCommand(
		CmdContext $context,
		#[NCA\Str("category", "categories")] string $action,
	): void {
		/** @var CommentCategory[] */
		$categories = $this->db->table("<table:comment_categories>")
			->asObj(CommentCategory::class)->toArray();
		if (count($categories) === 0) {
			$context->reply("There are currently no comment categories defined.");
			return;
		}
		$blob = "";
		foreach ($categories as $category) {
			$blob .= "<pagebreak><header2>{$category->name}<end>\n".
				"<tab>Created: <highlight>" . $this->util->date($category->created_at) . "<end>\n".
				"<tab>Creator: <highlight>{$category->created_by}<end>\n".
				"<tab>Read Access: <highlight>".
				$this->accessManager->getDisplayName($category->min_al_read).
				"<end>\n".
				"<tab>Write Access: <highlight>".
				$this->accessManager->getDisplayName($category->min_al_write).
				"<end>\n".
				"<tab>Action: ";
			if ($category->user_managed) {
				$blob .= $this->text->makeChatcmd(
					"delete",
					"/tell <myname> comment category rem {$category->name}"
				);
			} else {
				$blob .= "<i>System categories cannot be deleted.</i>";
			}
			$blob .= "\n\n";
		}
		$msg = $this->text->makeBlob("Comment categories (" . count($categories) . ")", $blob);
		$context->reply($msg);
	}

	/**
	 * Delete a category and all comments within it
	 *
	 * You can only delete categories to which you have the access level for reading and writing
	 */
	#[NCA\HandlesCommand("comment categories")]
	public function deleteCategoryCommand(
		CmdContext $context,
		#[NCA\Str("category", "categories")] string $action,
		PRemove $subAction,
		string $category
	): void {
		$cat = $this->getCategory($category);
		if (isset($cat)) {
			if ($cat->user_managed === false) {
				$context->reply("You cannot delete the built-in category <highlight>{$category}<end>.");
				return;
			}
			$senderAl = $this->accessManager->getAccessLevelForCharacter($context->char->name);
			if ($this->accessManager->compareAccessLevels($senderAl, $cat->min_al_read) <0
				|| $this->accessManager->compareAccessLevels($senderAl, $cat->min_al_write) <0) {
				$context->reply(
					"You can only delete categories to which you have read and write access."
				);
				return;
			}
		} else {
			$context->reply("The comment category <highlight>{$category}<end> does not exist.");
			return;
		}
		$deleted = $this->deleteCategory($category);
		if ($deleted === null) {
			$context->reply("The comment category <highlight>{$category}<end> does not exist.");
			return;
		}
		$msg = "Successfully deleted the comment category <highlight>{$category}<end>";
		if ($deleted === 0) {
			$msg .= ".";
		} elseif ($deleted === 1) {
			$msg .= " and <highlight>1 comment<end> in that category.";
		} else {
			$msg .= " and <highlight>{$deleted} comments<end> in that category.";
		}
		$context->reply($msg);
	}

	/**
	 * Add a new comment category with a minimum access level of
	 * &lt;al for reading&gt; and optionally a &lt;al for writing&gt;
	 */
	#[NCA\HandlesCommand("comment categories")]
	public function addCategoryCommand(
		CmdContext $context,
		#[NCA\Str("category", "categories")] string $action,
		#[NCA\Str("add", "create", "new", "edit", "change")] string $subAction,
		PWord $category,
		PWord $alForReading,
		?PWord $alForWriting
	): void {
		$alForWriting ??= $alForReading;
		try {
			$alForReading = $this->accessManager->getAccessLevel($alForReading());
			$alForWriting = $this->accessManager->getAccessLevel($alForWriting());
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}
		$cat = $this->getCategory($category());
		if ($cat === null) {
			$cat = new CommentCategory();
			$cat->created_by = $context->char->name;
			$cat->name = $category();
			$cat->min_al_read = $alForReading;
			$cat->min_al_write = $alForWriting;
			$this->saveCategory($cat);
			$context->reply("Category <highlight>{$category}<end> successfully created.");
			return;
		}
		$alOfSender = $this->accessManager->getAccessLevelForCharacter($context->char->name);
		if ($this->accessManager->compareAccessLevels($alOfSender, $cat->min_al_read) <0
			|| $this->accessManager->compareAccessLevels($alOfSender, $cat->min_al_write) <0) {
			$context->reply(
				"You can only change the required access levels of categories ".
				"to which you have read and write access."
			);
			return;
		}
		$cat->min_al_read = $alForReading;
		$cat->min_al_write = $alForWriting;
		$this->db->update("<table:comment_categories>", "name", $cat);
		$context->reply("Access levels for category <highlight>{$category}<end> successfully changes.");
	}

	/** Add a new comment &lt;comment text&lt; about &lt;char&gt; in the category &lt;category&gt; */
	#[NCA\HandlesCommand("comment")]
	#[NCA\Help\Epilogue(
		"<header2>Customization<end>\n\n".
		"In order to simulate the old kill-on-sight list (kos), you could do:\n".
		"<tab>1. <highlight><symbol>alias add kos comment list kos<end>\n".
		"<tab>2. <highlight><symbol>alias add \"kos add\" comment add {1} kos {2:Kill on sight}<end>\n".
		"<tab>3. <highlight><symbol>comment category add kos guild<end>\n"
	)]
	public function addCommentCommand(
		CmdContext $context,
		#[NCA\Str("add", "create", "new")] string $action,
		PCharacter $char,
		PWord $category,
		string $commentText
	): Generator {
		$character = $char();
		$category = $category();

		$cat = $this->getCategory($category);
		if ($cat === null) {
			$context->reply("The category <highlight>{$category}<end> does not exist.");
			return;
		}
		$uid = yield $this->chatBot->getUid2($character);
		if (!isset($uid)) {
			$context->reply("No player named <highlight>{$character}<end> found.");
			return;
		}
		if (!$this->accessManager->checkAccess($context->char->name, $cat->min_al_write)) {
			$context->reply(
				"You don't have the required access level to create comments of type ".
				"<highlight>{$category}<end>."
			);
			return;
		}
		if ($this->altsController->getMainOf($context->char->name) === $this->altsController->getMainOf($character)) {
			$context->reply("You cannot comment on yourself.");
			return;
		}
		$comment = new Comment();
		$comment->category = $cat->name;
		$comment->character = $character;
		$comment->comment = trim($commentText);
		$comment->created_by = $context->char->name;
		$cooldown = $this->saveComment($comment);
		if ($cooldown > 0) {
			$context->reply(
				"You have to wait <highlight>" . $this->util->unixtimeToReadable($cooldown) . "<end> ".
				"before posting another comment about <highlight>{$character}<end>."
			);
			return;
		}
		$context->reply("Comment about <highlight>{$character}<end> successfully saved.");
	}

	/**
	 * Save a comment and take the cooldown into consideration
	 *
	 * @return int 0 for success, otherwise the remaining time in seconds for posting
	 */
	public function saveComment(Comment $comment): int {
		$cooldown = $this->getCommentCooldown($comment);
		if ($cooldown > 0) {
			return $cooldown;
		}

		$this->db->insert("<table:comments>", $comment);
		return 0;
	}

	/**
	 * Get a list of all comments about a character and their alts.
	 * If &lt;category&gt; is given, limit the list to this category
	 */
	#[NCA\HandlesCommand("comment")]
	public function searchCommentCommand(
		CmdContext $context,
		#[NCA\Str("get", "search", "find")] string $action,
		PCharacter $char,
		?PWord $category
	): Generator {
		$character = $char();
		$uid = yield $this->chatBot->getUid2($character);
		if (!isset($uid)) {
			$context->reply("No player named <highlight>{$character}<end> found.");
			return;
		}

		if (isset($category)) {
			$categoryName = $category();
			$category = $this->getCategory($categoryName);
			if ($category === null) {
				$context->reply("The category <highlight>{$categoryName}<end> does not exist.");
				return;
			}
			if (!$this->accessManager->checkAccess($context->char->name, $category->min_al_read)) {
				$context->reply(
					"You don't have the required access level to read comments of type ".
					"<highlight>{$categoryName}<end>."
				);
				return;
			}
		}
		/** @var ?CommentCategory $category */
		/** @var Comment[] $comments */
		$comments = $this->getComments($category, $character);
		$comments = $this->filterInaccessibleComments($comments, $context->char->name);
		if (!count($comments)) {
			$msg = "No comments found for <highlight>{$character}<end>".
			(isset($category) ? " in category <highlight>{$category->name}<end>." : ".");
			$context->reply($msg);
			return;
		}
		$formatted = $this->formatComments($comments, false, !isset($category));
		$msg = "Comments about {$character}".
			(isset($category) ? " in category {$category->name}" : "").
			" (" . count($comments) . ")";
		$msg = $this->text->makeBlob($msg, $formatted->blob);
		$context->reply($msg);
	}

	/** Get a list of all comments of category &lt;category&gt; about all characters */
	#[NCA\HandlesCommand("comment")]
	public function listCommentsCommand(
		CmdContext $context,
		#[NCA\Str("list")] string $action,
		PWord $categoryName
	): void {
		$category = $this->getCategory($categoryName());
		if ($category === null) {
			$context->reply("The category <highlight>{$categoryName}<end> does not exist.");
			return;
		}
		if (!$this->accessManager->checkAccess($context->char->name, $category->min_al_read)) {
			$context->reply(
				"You don't have the required access level to read comments of type ".
				"<highlight>{$categoryName}<end>."
			);
			return;
		}

		/** @var Comment[] */
		$comments = $this->db->table("<table:comments>")
			->where("category", $categoryName)
			->orderBy("created_at")
			->asObj(Comment::class)->toArray();
		if (!count($comments)) {
			$msg = "No comments found in category <highlight>{$categoryName}<end>.";
			$context->reply($msg);
			return;
		}
		$formatted = $this->formatComments($comments, false, false);
		$msg = "Comments in {$categoryName} ".
			"(" . count($comments) . ")";
		$msg = $this->text->makeBlob($msg, $formatted->blob);
		$context->reply($msg);
	}

	/**
	 * Remove all comments from $comments that $sender does not have permission to read
	 *
	 * @param Comment[] $comments
	 *
	 * @return Comment[]
	 */
	public function filterInaccessibleComments(array $comments, string $sender): array {
		$accessCache = [];
		$senderAL = $this->accessManager->getAccessLevelForCharacter($sender);
		$readableComments = array_values(
			array_filter(
				$comments,
				function (Comment $comment) use (&$accessCache, $senderAL): bool {
					if (isset($accessCache[$comment->category])) {
						return $accessCache[$comment->category];
					}
					$cat = $this->getCategory($comment->category);
					$canRead = false;
					if (isset($cat)) {
						$canRead = $this->accessManager->compareAccessLevels($senderAL, $cat->min_al_read) >= 0;
					}
					return $accessCache[$comment->category] = $canRead;
				}
			)
		);
		return $readableComments;
	}

	/**
	 * Format the blob for a list of comments
	 *
	 * @param Comment[] $comments
	 */
	public function formatComments(array $comments, bool $groupByMain, bool $addCategory=false): FormattedComments {
		$result = new FormattedComments();
		$result->numComments = count($comments);
		$chars = [];
		foreach ($comments as $comment) {
			$chars[$comment->character] ??= [];
			$chars[$comment->character] []= $comment;
		}
		$result->numChars = count($chars);
		if ($groupByMain) {
			$grouped = [];
			foreach ($chars as $char => $comments) {
				$main = $this->altsController->getMainOf($char);
				$grouped[$main] ??= [];
				$grouped[$main] = [...$grouped[$main], ...$comments];
			}
		} else {
			$grouped = $chars;
		}
		$result->numMains = count($grouped);
		$blob = "";
		foreach ($grouped as $main => $comments) {
			$blob .= "<pagebreak><header2>{$main}<end>\n";
			$blob .= "<tab>" . join(
				"\n<tab>",
				array_map(
					[$this, "formatComment"],
					$comments,
					array_fill(0, count($comments), $addCategory)
				)
			) . "\n\n";
		}
		$result->blob = $blob;
		return $result;
	}

	/** Format a single comment */
	public function formatComment(Comment $comment, bool $addCategory=false): string {
		$line = "{$comment->comment} (<highlight>{$comment->created_by}<end>, ".
			($addCategory ? "<highlight>{$comment->category}<end>, " : "").
			$this->util->date($comment->created_at) . ") [".
			$this->text->makeChatcmd("delete", "/tell <myname> comment del {$comment->id}").
			"]";
		return $line;
	}

	/** Delete a comment about a player by its ID */
	#[NCA\HandlesCommand("comment")]
	public function deleteCommentCommand(
		CmdContext $context,
		PRemove $action,
		int $id
	): void {
		/** @var ?Comment */
		$comment = $this->db->table("<table:comments>")
			->where("id", $id)
			->asObj(Comment::class)
			->first();
		if (!isset($comment)) {
			$context->reply("The comment <highlight>#{$id}<end> does not exist.");
			return;
		}
		$cat = $this->getCategory($comment->category);
		if (!isset($cat)) {
			$context->reply("The category <highlight>{$comment->category}<end> does not exist.");
			return;
		}
		if ($this->accessManager->checkAccess($context->char->name, $cat->min_al_write) < 0) {
			$context->reply("You don't have the necessary access level to delete this comment.");
			return;
		}
		$this->db->table("<table:comments>")
			->where("id", $id)
			->delete();
		$context->reply("Comment deleted.");
	}

	/**
	 * Read all comments about a list of players or their alts/main, optionally limited to a category
	 *
	 * @return Comment[]
	 */
	public function getComments(?CommentCategory $category, string ...$characters): array {
		$query = $this->db->table("<table:comments>")->orderBy("created_at");
		$chars = [];
		foreach ($characters as $character) {
			$altInfo = $this->altsController->getAltInfo($character);
			$chars = [...$chars, $altInfo->main, ...$altInfo->getAllValidatedAlts()];
		}
		$query->whereIn("character", $chars);
		if (isset($category)) {
			$query->where("category", $category->name);
		}

		/** @var Comment[] */
		$comments = $query->asObj(Comment::class)->toArray();
		return $comments;
	}

	/** Count all comments about a list of players or their alts/main, optionally limited to a category */
	public function countComments(?CommentCategory $category, string ...$characters): int {
		$query = $this->db->table("<table:comments>");
		$chars = [];
		foreach ($characters as $character) {
			$altInfo = $this->altsController->getAltInfo($character);
			$chars = [...$chars, $altInfo->main, ...$altInfo->getAllValidatedAlts()];
		}
		$query->whereIn("character", $chars);
		if (isset($category)) {
			$query->where("category", $category->name);
		}
		return $query->count();
	}

	/**
	 * Read all comments about of a category
	 *
	 * @return Comment[]
	 */
	public function readCategoryComments(CommentCategory $category): array {
		return $this->db->table("<table:comments>")
			->whereIlike("category", $category->name)
			->orderBy("created_at")
			->asObj(Comment::class)
			->toArray();
	}

	/**
	 * Calculate how many seconds to wait before posting another comment
	 * about the same character again.
	 */
	protected function getCommentCooldown(Comment $comment): int {
		if ($comment->created_by === $this->chatBot->char->name) {
			return 0;
		}
		$cooldown = $this->commentCooldown;
		// Get all comments about that same character
		$comments = $this->getComments(null, $comment->character);
		// Only keep those that were created by the same person creating one now
		$ownComments = array_values(
			array_filter(
				$comments,
				function (Comment $com) use ($comment): bool {
					return $com->created_by === $comment->created_by;
				}
			)
		);
		// They are sorted by time, so last element is the newest
		$lastComment = end($ownComments);
		if ($lastComment === false) {
			return 0;
		}
		// If the age of the last comment is less than the cooldown, return the remaining cooldown
		if (time() - $lastComment->created_at < $cooldown) {
			return $cooldown - time() + $lastComment->created_at;
		}
		return 0;
	}
}

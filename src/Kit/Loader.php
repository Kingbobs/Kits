<?php

/**
 * @name KitLoader
 * @version 1.0.1
 * @main Kit\Loader
 * @api 4.0.0
 */

namespace Kit;

use onebone\economyapi\EconomyAPI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\level\Level;
use pocketmine\nbt\JsonNbtParser;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\utils\MainLogger;
use pocketmine\utils\TextFormat;
use Kit\inventory\CategoryInventory;
use Kit\inventory\KitInventory;
use Kit\kit\Category;
use Kit\kit\Kit;

class Loader extends PluginBase implements Listener {

    public const VERSION = "1.0.1";

    /** @var self */
    protected static $instance;

    /** @var Category[] */
    protected $categories = [];

    /** @var int[][] */
    protected $cooldowns = [];

    /**
     * @return Loader
     */
    public static function getInstance(): Loader {
        return self::$instance;
    }

    public function onLoad() {
        self::$instance = $this;

        if (!is_dir($this->getDataFolder() . "categories/")) {
            mkdir($this->getDataFolder() . "categories/");
        }
        if (!file_exists($this->getDataFolder() . "config.yml")) {
            $this->saveResource("categories/example/category.conf");
            $this->saveResource("categories/example/example.yml");
        }

        $this->saveResource("config.yml");
        if ($this->getConfig()->get("version") !== self::VERSION) {
            $this->getLogger()->warning("Unknown config version detected, resetting config...");
            $this->saveResource("config.yml", true);
        }
        if (file_exists($this->getDataFolder() . "cooldown.yml")) {
            $this->cooldowns = yaml_parse_file($this->getDataFolder() . "cooldown.yml");
        }
    }

    /**
     * @throws \Exception
     */
public function onEnable() {
    $this->getServer()->getPluginManager()->registerEvents($this, $this);

    foreach (scandir($this->getDataFolder() . "categories/") as $fileName) {
        if (in_array($fileName, [".", ".."]) || !is_dir($this->getDataFolder() . "categories/" . $fileName . "/")) {
            continue;
        }
        $dir = $this->getDataFolder() . "categories/" . $fileName . "/";

        if (file_exists($dir . "category.conf")) {
            $data = yaml_parse_file($dir . "category.conf");

            $name = TextFormat::colorize($data["name"]);
            $des = TextFormat::colorize(implode("\n", $data["description"]));

            $item = ItemFactory::fromString($data["display-item"]);
            $item->setCustomName(TextFormat::RESET . $name);
            $item->setLore([$des]);

            $category = new Category($name, $item);
            $category->setDescription($des);

            foreach (scandir($dir) as $kitFile) {
                if (in_array($kitFile, [".", ".."]) || $kitFile === "category.conf") {
                    continue;
                }

                $kitData = yaml_parse_file($dir . $kitFile);

                $kitName = TextFormat::colorize($kitData["name"]);
                $kitItem = ItemFactory::fromString($kitData["display-item"]);

                $kit = new Kit($kitName, $kitItem);

                foreach ($kitData["items"] as $itemData) {
                    $item = ItemFactory::fromString($itemData["item"]);
                    $item->setCustomName(TextFormat::RESET . $itemData["name"]);
                    $item->setLore($itemData["lore"]);

                    if (isset($itemData["enchantments"])) {
                        foreach ($itemData["enchantments"] as $enchantmentData) {
                            $enchantment = Enchantment::getEnchantmentByName($enchantmentData["name"]);
                            if ($enchantment === null) {
                                MainLogger::getLogger()->warning("Invalid enchantment: " . $enchantmentData["name"]);
                                continue;
                            }
                            $enchantmentInstance = new EnchantmentInstance($enchantment, $enchantmentData["level"]);
                            $item->addEnchantment($enchantmentInstance);
                        }
                    }

                    if (isset($itemData["nbt"])) {
                        $nbt = JsonNbtParser::parseJson($itemData["nbt"]);
                        $item->setNamedTagEntry($nbt);
                    }

                    $kit->addItem($item);
                }

                $category->addKit($kit);
            }

            $this->categories[] = $category;
        }
    }

    $this->getLogger()->info("KitLoader v" . self::VERSION . " by onebone has been enabled.");
}
public function onDisable() {
    $cooldownsYml = yaml_emit($this->cooldowns, YAML_UTF8_ENCODING);
    file_put_contents($this->getDataFolder() . "cooldown.yml", $cooldownsYml);
}

public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
    if (!$sender instanceof Player) {
        $sender->sendMessage(TextFormat::RED . "This command can only be used in-game.");
        return true;
    }

    if (strtolower($command->getName()) === "kit") {
        if (empty($args)) {
            $sender->sendMessage(TextFormat::RED . "Usage: /kit <category>");
            return true;
        }

        $categoryName = strtolower($args[0]);
        $category = $this->getCategoryByName($categoryName);

        if ($category === null) {
            $sender->sendMessage(TextFormat::RED . "Invalid kit category.");
            return true;
        }

        $inventory = new KitInventory($category, $sender);
        $sender->addWindow($inventory->getInventory());

        return true;
    }

    if (strtolower($command->getName()) === "kitadmin") {
        if (!$sender->hasPermission("kitloader.admin")) {
            $sender->sendMessage(TextFormat::RED . "You don't have permission to use this command.");
            return true;
        }

        if (empty($args)) {
            $sender->sendMessage(TextFormat::RED . "Usage: /kitadmin <subcommand> [args...]");
            return true;
        }

        $subCommand = strtolower($args[0]);

        switch ($subCommand) {
            case "reload":
                $this->reloadConfig();
                $this->loadKits();
                $sender->sendMessage(TextFormat::GREEN . "Kits reloaded.");
                break;
            case "give":
                if (count($args) < 3) {
                    $sender->sendMessage(TextFormat::RED . "Usage: /kitadmin give <player> <kit>");
                    return true;
                }

                $playerName = $args[1];
                $kitName = strtolower($args[2]);

                $player = $this->getServer()->getPlayerExact($playerName);
                if ($player === null) {
                    $sender->sendMessage(TextFormat::RED . "Invalid player.");
                    return true;
                }

                $kit = $this->getKitByName($kitName);
                if ($kit === null) {
                    $sender->sendMessage(TextFormat::RED . "Invalid kit.");
                    return true;
                }

                $player->getInventory()->addItem($kit->getItem());
                $sender->sendMessage(TextFormat::GREEN . "Given kit " . $kit->getName() . " to player " . $player->getName());
                break;
            default:
                $sender->sendMessage(TextFormat::RED . "Invalid subcommand. Available subcommands: reload, give");
                break;
        }

        return true;
    }

    return false;
}

public function onPlayerInteract(PlayerInteractEvent $event) {
    $player = $event->getPlayer();
    $item = $event->getItem();
    $action = $event->getAction();

    if ($action === PlayerInteractEvent::RIGHT_CLICK_AIR || $action === PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
        $category = $this->getCategoryByItem($item);

        if ($category !== null) {
            $inventory = new KitInventory($category, $player);
            $player->addWindow($inventory->getInventory());
        }
    }
}

public function onPlayerJoin(PlayerJoinEvent $event) {
    $player = $event->getPlayer();
    $uuid = $player->getUniqueId()->toString();
    if (!isset($this->cooldowns[$uuid])) {
        $this->cooldowns[$uuid] = [];
    }
}

public function onPlayerQuit(PlayerQuitEvent $event) {
    $player = $event->getPlayer();
    $uuid = $player->getUniqueId()->toString();
    if (isset($this->cooldowns[$uuid])) {
        unset($this->cooldowns[$uuid]);
    }
}

public function getCategoryByName(string $name): ?Category {
    foreach ($this->categories as $category) {
        if (strtolower($category->getName()) === strtolower($name)) {
            return $category;
        }
    }
    return null;
}

public function getCategoryByItem(Item $item): ?Category {
    foreach ($this->categories as $category) {
        if ($item->equals($category->getItem())) {
            return $category;
        }
    }
    return null;
}

public function getKitByName(string $name): ?Kit {
    foreach ($this->categories as $category) {
        $kit = $category->getKitByName($name);
        if ($kit !== null) {
            return $kit;
        }
    }
    return null;
}

public function getCooldowns(): array {
    return $this->cooldowns;
}

public function getCooldown(Player $player, Kit $kit): int {
    $uuid = $player->getUniqueId()->toString();
    $kitName = strtolower($kit->getName());
    if (isset($this->cooldowns[$uuid][$kitName])) {
        return $this->cooldowns[$uuid][$kitName];
    }
    return 0;
}

public function setCooldown(Player $player, Kit $kit, int $seconds) {
    $uuid = $player->getUniqueId()->toString();
    $kitName = strtolower($kit->getName());
    $this->cooldowns[$uuid][$kitName] = $seconds;
}

public function reduceCooldowns() {
    foreach ($this->cooldowns as $uuid => &$kits) {
        foreach ($kits as $kitName => &$cooldown) {
            if ($cooldown > 0) {
                $cooldown--;
            }
            if ($cooldown === 0) {
                unset($kits[$kitName]);
            }
        }
    }
    class Kit {
    private $name;
    private $item;

    public function __construct(string $name, Item $item) {
        $this->name = $name;
        $this->item = $item;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getItem(): Item {
        return $this->item;
    }
}

class Category {
    private $name;
    private $item;
    private $kits;

    public function __construct(string $name, Item $item) {
        $this->name = $name;
        $this->item = $item;
        $this->kits = [];
    }

    public function getName(): string {
        return $this->name;
    }

    public function getItem(): Item {
        return $this->item;
    }

    public function addKit(Kit $kit) {
        $this->kits[$kit->getName()] = $kit;
    }

    public function removeKit(Kit $kit) {
        unset($this->kits[$kit->getName()]);
    }

    public function getKitByName(string $name): ?Kit {
        if (isset($this->kits[$name])) {
            return $this->kits[$name];
        }
        return null;
    }

    public function getKits(): array {
        return $this->kits;
    }
}

class KitInventory extends ChestInventory {
    private $category;

    public function __construct(Category $category, Player $player) {
        parent::__construct($player, 27);
        $this->category = $category;

        $this->setContents($this->generateContents());
    }

    public function generateContents(): array {
        $contents = [];
        $kits = $this->category->getKits();
        foreach ($kits as $kit) {
            $contents[] = $kit->getItem();
        }
        return $contents;
    }
}

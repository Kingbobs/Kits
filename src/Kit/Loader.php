<?php
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

        if(is_dir($this->getDataFolder() . "categories/") == false){
            mkdir($this->getDataFolder() . "categories/");
        }
        if(file_exists($this->getDataFolder() . "config.yml") == false){
            $this->saveResource("categories/example/category.conf");
            $this->saveResource("categories/example/example.yml");
        }

        $this->saveResource("config.yml");
        if($this->getConfig()->get("version") !== self::VERSION){
            $this->getLogger()->warning("Unknown config version detected, resetting config...");

            $this->saveResource("config.yml", true);
        }
        if(file_exists($this->getDataFolder() . "cooldown.yml")){
            $this->cooldowns = yaml_parse_file($this->getDataFolder() . "cooldown.yml");
        }
    }

    /**
     * @throws \Exception
     */
    public function onEnable() {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        foreach(scandir($this->getDataFolder() . "categories/") as $fileName){
            if(in_array($fileName, [
                    ".",
                    ".."
                ]) or is_dir($this->getDataFolder() . "categories/" . $fileName . "/") == false){
                continue;
            }
            $dir = $this->getDataFolder() . "categories/" . $fileName . "/";

            if(file_exists($dir . "category.conf")){
                $data = yaml_parse_file($dir . "category.conf");

                $name = TextFormat::colorize($data["name"]);
                $des = TextFormat::colorize(implode("\n", $data["description"]));

                $item = ItemFactory::fromString($data["display-item"]);
                $item->setCustomName(TextFormat::RESET . $name . "\n" . TextFormat::RESET . $des);
                $item->setNamedTagEntry(new ListTag("ench", []));
                $item->setNamedTagEntry(new StringTag("category", $name));

                $category = new Category($name, $des, $item);

                foreach(glob($dir . "*.yml") as $kitConf){
                    $category->addKit(new Kit(yaml_parse_file($kitConf), $category));
                }

                $this->categories[$category->getName()] = $category;
            }
        }

        $this->getScheduler()->scheduleRepeatingTask(new class() extends Task {
            /**
             * @param int $currentTick
             */
            public function onRun(int $currentTick) {
                Loader::getInstance()->tickCD();
            }
        }, 20);
    }

    public function onDisable() {
        file_put_contents($this->getDataFolder() . "cooldown.yml", yaml_emit($this->cooldowns));
    }

    public function tickCD(): void {
        foreach($this->cooldowns as $name => $cds){
            foreach($cds as $kit => $cd){
                if($cd > 0){
                    $this->cooldowns[$name][$kit]--;
                }
            }
        }
    }

    /**
     * @param int $seconds
     * @return string
     */
    public static function secondToTime(int $seconds): string {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds - $hours * 3600) / 60);
        $seconds = floor($seconds - ($hours * 3600) - ($minutes * 60));

        return "$hours hours, $minutes minutes and $seconds seconds";
    }

    /**
     * @param array $data
     * @return Item
     * @throws \Exception
     */
    public static function buildItem(array $data): Item {
        $item = null;

        try{
            $item = ItemFactory::fromString($data["item"]);
            $item->setCount(intval($data["count"] ?? 1));
        }catch(\Exception $exception){
            MainLogger::getLogger()->warning("Item: " . ($data["item"] ?? "null") . " is not valid");
        }finally{
            if($item == null){
                return ItemFactory::get(ItemIds::AIR);
            }
        }
        if(isset($data["nbt"])){
            $nbt = JsonNbtParser::parseJson($data["nbt"]);
            $item->setNamedTag($nbt);
        }
        if(isset($data["customName"])){
            $item->setCustomName(TextFormat::colorize(str_replace("\n", "\n", $data["customName"])));
        }
        if(isset($data["enchants"])){
            $enchants = $data["enchants"];

            foreach($enchants as $e){
                $v = explode(" ", $e);
                $id = $v[0];
                $level = $v[1] ?? 1;

                $ench = Enchantment::getEnchantmentByName($id) ?? Enchantment::getEnchantment(intval($id));
                if($ench !== null){
                    $enchant = new EnchantmentInstance($ench, intval($level));

                    $item->addEnchantment($enchant);
                }
            }
        }

        return $item;
    }

    /**
     * @param string $id
     * @return null|Kit
     */
    public function getKit(string $id): ?Kit {
        foreach($this->categories as $category){
            $k = $category->getKit($id);

            if($k !== null){
                return $k;
            }
        }

        return null;
    }

    /**
     * @param string $name
     * @return null|Category
     */
    public function getCategory(string $name): ?Category {
        return $this->categories[$name] ?? null;
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function onInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $item = $event->getItem();

        if($item->getNamedTag()->hasTag("kitId")){
            $kit = $this->getKit($item->getNamedTagEntry("kitId")->getValue());

            if($kit !== null){
                $event->setCancelled();

                $c = count($kit->getItems());
                $cc = $player->getInventory()->getSize() - count($player->getInventory()->getContents());

                if($c > $cc){
                    $player->sendMessage(TextFormat::RED . "Your inventory doesn't have enough space! Need $c free slots.");
                }else{
                    $kit->grant($player);

                    $item->setCount(1);
                    $player->getInventory()->removeItem($item);
                }
            }
        }
    }

    /**
     * @param PlayerItemHeldEvent $event
     */
    public function onHeld(PlayerItemHeldEvent $event): void {
        $player = $event->getPlayer();

        foreach($player->getInventory()->getContents() as $slot => $content){
            if($content->getNamedTag()->hasTag("kitId")){
                $kit = $this->getKit($content->getNamedTagEntry("kitId")->getValue());

                if($kit !== null){
                    if($kit->getDisplayItem()->equals($content) == false){
                        $i = clone $kit->getDisplayItem();
                        $i->setCount($content->getCount());

                        $player->getInventory()->setItem($slot, $i);
                    }
                }
            }
        }
    }

    /**
     * @param InventoryTransactionEvent $event
     * @priority HIGHEST
     */
    public function onTransaction(InventoryTransactionEvent $event): void {
        $tran = $event->getTransaction();
        $player = $tran->getSource();

        foreach($tran->getActions() as $action){
            if($action instanceof SlotChangeAction){
                $inv = $action->getInventory();
                $item = $inv->getItem($action->getSlot());

                if($inv instanceof CategoryInventory){
                    $event->setCancelled();

                    if($item->isNull() == false){
                        $category = $this->getCategory($item->getNamedTagEntry("category")->getValue());

                        if($category !== null){
                            $player->removeWindow($inv);

                            $newInv = new KitInventory($player->asPosition(), $category);
                            $this->getScheduler()->scheduleDelayedTask(new class($newInv, $player) extends Task {
                                /** @var Player */
                                protected $player;

                                /** @var KitInventory */
                                protected $inv;

                                /**
                                 *  constructor.
                                 * @param KitInventory $inv
                                 * @param Player $player
                                 */
                                public function __construct(KitInventory $inv, Player $player) {
                                    $this->player = $player;
                                    $this->inv = $inv;
                                }

                                /**
                                 * @param int $currentTick
                                 */
                                public function onRun(int $currentTick) {
                                    $this->player->addWindow($this->inv);
                                }
                            }, 20);
                        }
                    }
                }elseif($inv instanceof KitInventory){
                    $event->setCancelled();

                    if($item->isNull() == false){
                        $kit = $this->getKit($item->getNamedTagEntry("kitId")->getValue());

                        if($kit !== null){
                            if($player->hasPermission("kit.bypass") == false){
                                if($kit->getCost() == -1 and $player->hasPermission($kit->getPermission()) == false){
                                    $player->sendMessage(TextFormat::RED . "You don't have permission to use that kit");

                                    return;
                                }
                                if($kit->getCost() > 0 and EconomyAPI::getInstance()->myMoney($player) < $kit->getCost()){
                                    if($player->hasPermission($kit->getPermission()) == false){
                                        $player->sendMessage(TextFormat::RED . "You can't afford kit: " . $kit->getName());

                                        return;
                                    }
                                }
                                if(($player->getInventory()->getSize() - count($player->getInventory()->getContents())) == 0){
                                    $player->sendMessage(TextFormat::RED . "Your inventory is full!");

                                    return;
                                }
                                $cd = ($this->cooldowns[$player->getName()][$kit->getId()] ?? 0);
                                if($cd > 0){
                                    $player->sendMessage(TextFormat::RED . "Kit: " . $kit->getName() . TextFormat::RESET . TextFormat::RED . " is still in cooldown for " . self::secondToTime($cd));

                                    return;
                                }elseif($cd == -1){
                                    $player->sendMessage(TextFormat::RED . "That kit is one time use only!");

                                    return;
                                }

                                $player->getInventory()->addItem(clone $kit->getDisplayItem());
                                $this->cooldowns[$player->getName()][$kit->getId()] = $kit->getCooldown();
                                if($kit->getCost() > 0 and $player->hasPermission($kit->getPermission()) == false){
                                    EconomyAPI::getInstance()->reduceMoney($player, $kit->getCost());
                                }

                            }else{
                                $player->getInventory()->addItem(clone $kit->getDisplayItem());
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param CommandSender $sender
     * @param Command $command
     * @param string $label
     * @param array $args
     * @return bool
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if($sender instanceof Player){
            if($sender->y < 0 or $sender->y > Level::Y_MAX){
                $sender->sendMessage(TextFormat::RED . "Your Y level must be between 0-256 in order to open kits menu");
            }else{
                $sender->addWindow(new CategoryInventory($sender->asPosition(), $this->categories));
            }
        }else{
            $this->getLogger()->info("Working fine, you should check in-game");
        }

        return true;
    }
}

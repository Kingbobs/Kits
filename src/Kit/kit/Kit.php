<?php
namespace Kit\kit;

use pocketmine\command\ConsoleCommandSender;

use pocketmine\entity\Effect;
use pocketmine\entity\EffectInstance;

use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;

use pocketmine\nbt\tag\ListTag;

use pocketmine\nbt\tag\StringTag;
use pocketmine\Player;
use pocketmine\Server;

use pocketmine\utils\TextFormat;

use Kit\Loader;

class Kit {

    /** @var string */
    protected $id;

    /** @var string */
    protected $name;

    /** @var string */
    protected $description;

    /** @var string */
    protected $permission = "";

    /** @var int */
    protected $cooldown = 0;

    /** @var float */
    protected $cost = 0;

    /** @var Item */
    protected $displayItem;

    /** @var Item[] */
    protected $items = [];

    /** @var string[] */
    protected $commands = [];

    /** @var EffectInstance[] */
    protected $effects = [];

    /** @var Category */
    protected $category;

    /** @var array  */
    private $data = [];

    /**
     * Kit constructor.
     * @param array $data
     * @param Category $category
     * @throws \Exception
     */
    public function __construct(array $data, Category $category) {
        $this->data = $data;
        $this->category = $category;
        $this->id = $data["id"];
        $this->name = TextFormat::colorize($data["name"]);
        $this->description = TextFormat::colorize(implode("\n", $data["description"]));
        $this->permission = $data["permission"];
        $this->cooldown = $data["cooldown"];
        $this->cost = $data["cost"];

        $rule = str_repeat("-", 28) . "\n";
        $rule .= TextFormat::GREEN . "CD: " . TextFormat::WHITE . ($this->cooldown == - 1? "One time use" : Loader::secondToTime($this->cooldown)) . "\n";
        $rule .= $this->cost == -1 ? TextFormat::GOLD . "Requires special permission to use" : TextFormat::GREEN . "Price: " . TextFormat::WHITE . "$" . $this->cost;

        $item = ItemFactory::fromString($data["display-item"]);
        $item->setCustomName(TextFormat::RESET . $this->name . "\n" . TextFormat::RESET . $this->description . TextFormat::RESET . "\n\n" . $rule);
        $item->setNamedTagEntry(new ListTag("ench", []));
        $item->setNamedTagEntry(new StringTag("kitId", $this->id));

        $this->displayItem = $item;
        $this->commands = $data["commands"];

        foreach($data["items"] as $ida){
            $this->items[] = Loader::buildItem($ida);
        }
        foreach($data["effects"] as $datum){
            $dat = explode(" ", $datum);

            $effect = Effect::getEffectByName($dat[0]) ?? Effect::getEffect(intval($dat[0]));
            if($effect !== null){
                $this->effects[] = new EffectInstance($effect, ($dat[1] ?? 60) * 20, $dat[2] ?? 0);
            }
        }
    }

    /**
     * @return Category
     */
    public function getCategory(): Category {
        return $this->category;
    }

    /**
     * @return Item
     */
    public function getDisplayItem(): Item {
        return $this->displayItem;
    }

    /**
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getDescription(): string {
        return $this->description;
    }

    /**
     * @return string
     */
    public function getPermission(): string {
        return $this->permission;
    }

    /**
     * @return EffectInstance[]
     */
    public function getEffects(): array {
        return $this->effects;
    }

    /**
     * @return Item[]
     */
    public function getItems(): array {
        return $this->items;
    }

    /**
     * @return string[]
     */
    public function getCommands(): array {
        return $this->commands;
    }

    /**
     * @return int
     */
    public function getCooldown(): int {
        return $this->cooldown;
    }

    /**
     * @return float
     */
    public function getCost(): float {
        return $this->cost;
    }

    /**
     * @param Player $player
     */
    public function grant(Player $player): void {
        $items = [];
        foreach($this->data["items"] as $key => $ida){
            $i = Loader::buildItem($ida);
            if(isset($ida["ce"])){
                foreach ($ida["ce"] as $ceData){
                    $array = explode(":", $ceData);
                    $id = (int) $array[0];
                    $level = mt_rand((int) $array[1], (int) $array[2]);
                    $chance = (int) $array[3];
                    if(mt_rand(1, 100) <= $chance){
                        if($e = Enchantment::getEnchantment($id)){
                            var_dump("added " . $e->getName());
                            $i->addEnchantment(new EnchantmentInstance($e, $level));
                        }
                    }
                }
            }
            $items[] = $i;
        }

        foreach($items as $item){
            $player->getInventory()->addItem(clone $item);
        }
        foreach($this->effects as $effect){
            $player->addEffect(clone $effect);
        }
        foreach($this->commands as $command){
            $cmdLine = str_replace([
                ".player.",
                ".display-name.",
                ".x.",
                ".y.",
                ".z.",
                ".world."
            ], [
                $player->getName(),
                $player->getDisplayName(),
                $player->x,
                $player->y,
                $player->z,
                $player->getLevel()->getName()
            ], $command);

            Server::getInstance()->dispatchCommand(new ConsoleCommandSender(), $cmdLine);
        }
    }
}

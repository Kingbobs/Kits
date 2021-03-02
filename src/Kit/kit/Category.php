<?php
namespace Kit\kit;

use pocketmine\item\Item;
use pocketmine\utils\TextFormat;

class Category {

    /** @var string */
    protected $name;

    /** @var string */
    protected $description = "";

    /** @var Kit[] */
    protected $kits = [];

    /** @var Item */
    protected $displayItem;

    /**
     * Category constructor.
     * @param string $name
     * @param string $description
     * @param Item $displayItem
     */
    public function __construct(string $name, string $description, Item $displayItem) {
        $this->name = TextFormat::colorize($name);
        $this->description = TextFormat::colorize(str_replace("\n", "\n", $description));
        $this->displayItem = $displayItem;
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
     * @return Kit[]
     */
    public function getKits(): array {
        return $this->kits;
    }

    /**
     * @param Kit $kit
     */
    public function addKit(Kit $kit): void {
        $this->kits[$kit->getId()] = $kit;
    }

    /**
     * @param Kit $kit
     */
    public function removeKit(Kit $kit): void {
        unset($this->kits[$kit->getId()]);
    }

    /**
     * @param string $id
     * @return null|Kit
     */
    public function getKit(string $id): ?Kit {
        return $this->kits[$id] ?? null;
    }
}

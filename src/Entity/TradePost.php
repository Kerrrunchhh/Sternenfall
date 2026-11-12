<?php

namespace App\Entity;

use App\Game\Resources;
use App\Game\BuyerAccess;
use App\Game\LifeForm;
use App\Game\ResourceType;
use App\Game\TradeDeal;
use App\Repository\TradePostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A trader's station on a free slot. Sells (and buys) resources from its
 * stock at prices (credits per unit) set by the trader.
 */
#[ORM\Entity(repositoryClass: TradePostRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_trade_post_slot', columns: ['galaxy', 'solar_system', 'position'])]
class TradePost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 40)]
    private string $name;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $galaxy;

    #[ORM\Column(name: 'solar_system', type: Types::SMALLINT)]
    private int $system;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    /** @var array<string, float> goods => credits per unit */
    #[ORM\Column(type: Types::JSON)]
    private array $prices = [];

    /** @var array<string, float> resource => credits the trader pays per unit when buying */
    #[ORM\Column(type: Types::JSON)]
    private array $buyPrices = [];

    /** @var array<string, float> resource => amount the trader still buys (0 or missing: buys nothing) */
    #[ORM\Column(type: Types::JSON)]
    private array $buyLimits = [];

    /** @var array<string, float> goods => amount in stock */
    #[ORM\Column(type: Types::JSON)]
    private array $stock = [];

    /** @var list<string> life form values allowed to trade here (empty and no alliances: everybody) */
    #[ORM\Column(type: Types::JSON)]
    private array $allowedLifeForms = [];

    /** @var Collection<int, Alliance> */
    #[ORM\ManyToMany(targetEntity: Alliance::class)]
    #[ORM\JoinTable(name: 'trade_post_alliance')]
    private Collection $allowedAlliances;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * The trader's colony the post is set up on: its resources are the stock, and it is
     * attacked and invaded like any planet. Null only for the trade guild's central exchanges.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Planet $planet = null;

    /** Abandoned posts (owner stopped being a trader) sell nothing; the owner can still collect the stock. */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(User $owner, string $name, int $galaxy, int $system, int $position, \DateTimeImmutable $createdAt)
    {
        $this->owner = $owner;
        $this->name = $name;
        $this->galaxy = $galaxy;
        $this->system = $system;
        $this->position = $position;
        $this->createdAt = $createdAt;
        $this->allowedAlliances = new ArrayCollection();
    }

    /** A post on one of the trader's planets (the planet's resources are its stock). */
    public static function onPlanet(Planet $planet, string $name, \DateTimeImmutable $createdAt): self
    {
        $post = new self($planet->getOwner(), $name, $planet->getGalaxy(), $planet->getSystem(), $planet->getPosition(), $createdAt);
        $post->planet = $planet;

        return $post;
    }

    public function getPlanet(): ?Planet
    {
        return $this->planet;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    /** The owner's account is deleted: the post is left behind, abandoned. */
    public function handOverTo(User $nobody): void
    {
        $this->owner = $nobody;
        $this->abandon();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getGalaxy(): int
    {
        return $this->galaxy;
    }

    public function getSystem(): int
    {
        return $this->system;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCoordinates(): string
    {
        return \sprintf('%d:%d:%d', $this->galaxy, $this->system, $this->position);
    }

    public function getPrice(string $good): float
    {
        return (float) ($this->prices[$good] ?? 0);
    }

    /** @return array<string, float> */
    public function getPrices(): array
    {
        return $this->prices;
    }

    /** @param array<string, float> $prices goods => credits per unit; 0 means not for sale */
    public function setPrices(array $prices): void
    {
        $this->prices = [];
        foreach (TradeDeal::goods() as $good) {
            $price = round(max(0, (float) ($prices[$good] ?? 0)), 2);
            if ($price > 0) {
                $this->prices[$good] = $price;
            }
        }
    }

    public function getBuyPrice(string $resource): float
    {
        return (float) ($this->buyPrices[$resource] ?? 0);
    }

    /** How much of a resource the trader still buys. */
    public function getBuyLimit(string $resource): float
    {
        return (float) ($this->buyLimits[$resource] ?? 0);
    }

    public function buysAnything(): bool
    {
        foreach ($this->buyLimits as $resource => $limit) {
            if ($limit > 0 && $this->getBuyPrice($resource) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the trader buys: price per unit and the amount he still takes (0 = nothing).
     *
     * @param array<string, float> $prices
     * @param array<string, float> $limits
     */
    public function setPurchase(array $prices, array $limits): void
    {
        $this->buyPrices = [];
        $this->buyLimits = [];
        foreach (ResourceType::cases() as $type) {
            $price = round(max(0, (float) ($prices[$type->value] ?? 0)), 2);
            $limit = floor(max(0, (float) ($limits[$type->value] ?? 0)));
            if ($price > 0) {
                $this->buyPrices[$type->value] = $price;
            }
            if ($limit > 0) {
                $this->buyLimits[$type->value] = $limit;
            }
        }
    }

    /** Lowers the remaining purchase amount after the trader bought something. */
    public function bought(string $resource, float $amount): void
    {
        $left = max(0, $this->getBuyLimit($resource) - $amount);
        if ($left > 0) {
            $this->buyLimits[$resource] = $left;
        } else {
            unset($this->buyLimits[$resource]);
        }
    }

    /** Stock of a good: on a planet its resources, otherwise (the guild) the post's own stock. */
    public function getStock(string $good): float
    {
        if (null !== $this->planet) {
            return null === ResourceType::tryFrom($good) ? 0.0 : $this->planet->getResources()->get(ResourceType::from($good));
        }

        return (float) ($this->stock[$good] ?? 0);
    }

    /** @return array<string, float> */
    public function getStockList(): array
    {
        if (null !== $this->planet) {
            return array_filter($this->planet->getResources()->toArray(), static fn (float $v): bool => $v > 0);
        }

        return $this->stock;
    }

    /** Changes the stock; on a planet the caller holds the owner's planet lock. */
    public function addStock(string $good, float $amount): void
    {
        if (null !== $this->planet) {
            $this->planet->setResources($this->planet->getResources()->add(Resources::fromArray([$good => $amount])));

            return;
        }
        $this->stock[$good] = $this->getStock($good) + $amount;
        $this->stock = array_filter($this->stock, static fn (float|int $v): bool => $v > 0);
    }

    /** Nothing left to collect (a post on a planet keeps nothing of its own). */
    public function isEmpty(): bool
    {
        return null !== $this->planet || [] === $this->stock;
    }

    /** @return list<LifeForm> */
    public function getAllowedLifeForms(): array
    {
        return array_map(static fn (string $v): LifeForm => LifeForm::from($v), $this->allowedLifeForms);
    }

    /** @param list<LifeForm> $lifeForms */
    public function setAllowedLifeForms(array $lifeForms): void
    {
        $this->allowedLifeForms = array_values(array_unique(array_map(static fn (LifeForm $l): string => $l->value, $lifeForms)));
    }

    /** @return Collection<int, Alliance> */
    public function getAllowedAlliances(): Collection
    {
        return $this->allowedAlliances;
    }

    /** @param list<Alliance> $alliances */
    public function setAllowedAlliances(array $alliances): void
    {
        $this->allowedAlliances->clear();
        foreach ($alliances as $alliance) {
            $this->allowedAlliances->add($alliance);
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** Stops all sales; the stock stays where it is. */
    public function abandon(): void
    {
        $this->active = false;
        $this->prices = [];
        $this->buyPrices = [];
        $this->buyLimits = [];
    }

    public function isRestricted(): bool
    {
        return [] !== $this->allowedLifeForms || !$this->allowedAlliances->isEmpty();
    }

    /** The owner always; others only at active posts, if unrestricted or if their life form or alliance is allowed. */
    public function isAccessibleTo(User $user): bool
    {
        if (!$this->active && !$this->owner->isSameAs($user)) {
            return false;
        }

        return BuyerAccess::allows($this->owner, $user, $this->allowedLifeForms, $this->allowedAlliances);
    }
}

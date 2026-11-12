<?php

namespace App\Entity;

use App\Game\BuyerAccess;
use App\Game\LifeForm;
use App\Repository\FusionatorOfferRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fusionators a trader offers on the fusionator market. They are taken out of a
 * planet hangar when offered; bought ones fly from that place to the buyer's main planet.
 */
#[ORM\Entity(repositoryClass: FusionatorOfferRepository::class)]
class FusionatorOffer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $seller;

    /** Where the fusionators wait (the planet they were offered from). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $galaxy;

    #[ORM\Column(name: 'solar_system', type: Types::SMALLINT)]
    private int $system;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column]
    private int $quantity;

    /** Credits per fusionator. */
    #[ORM\Column]
    private float $price;

    /** @var list<string> life form values that may buy (empty and no alliances: everybody) */
    #[ORM\Column(type: Types::JSON)]
    private array $allowedLifeForms = [];

    /** @var Collection<int, Alliance> */
    #[ORM\ManyToMany(targetEntity: Alliance::class)]
    #[ORM\JoinTable(name: 'fusionator_offer_alliance')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $allowedAlliances;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<LifeForm> $lifeForms
     * @param list<Alliance> $alliances
     */
    /** @param Planet|TradePost $origin a planet hangar, or the trade guild's trade post */
    public function __construct(User $seller, Planet|TradePost $origin, int $quantity, float $price, array $lifeForms, array $alliances, \DateTimeImmutable $createdAt)
    {
        $this->seller = $seller;
        $this->galaxy = $origin->getGalaxy();
        $this->system = $origin->getSystem();
        $this->position = $origin->getPosition();
        $this->quantity = $quantity;
        $this->price = $price;
        $this->allowedLifeForms = array_values(array_unique(array_map(static fn (LifeForm $l): string => $l->value, $lifeForms)));
        $this->allowedAlliances = new ArrayCollection($alliances);
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeller(): User
    {
        return $this->seller;
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

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /** Takes fusionators out of the offer (sold or withdrawn). */
    public function setPrice(float $price): void
    {
        $this->price = round($price, 2);
    }

    /** The trade guild's offer never runs out: it is filled up again. */
    public function restock(int $quantity): void
    {
        $this->quantity = max($this->quantity, $quantity);
    }

    public function take(int $count): void
    {
        if ($count < 1 || $count > $this->quantity) {
            throw new \LogicException('Not that many fusionators on offer.');
        }
        $this->quantity -= $count;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    /** @return list<LifeForm> */
    public function getAllowedLifeForms(): array
    {
        return array_map(static fn (string $v): LifeForm => LifeForm::from($v), $this->allowedLifeForms);
    }

    /** @return Collection<int, Alliance> */
    public function getAllowedAlliances(): Collection
    {
        return $this->allowedAlliances;
    }

    public function isRestricted(): bool
    {
        return [] !== $this->allowedLifeForms || !$this->allowedAlliances->isEmpty();
    }

    public function isAvailableTo(User $buyer): bool
    {
        return BuyerAccess::allows($this->seller, $buyer, $this->allowedLifeForms, $this->allowedAlliances);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

<?php

namespace App\Entity;

use App\Game\LifeForm;
use App\Repository\AllianceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An alliance. Only players of one life form belong to it, and its kind (Orden, Syndikat, Handelsgilde, Allianz) follows
 * the life form of its founder at the time of founding; members may have any life form.
 */
#[ORM\Entity(repositoryClass: AllianceRepository::class)]
class Alliance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, unique: true)]
    private string $name;

    #[ORM\Column(length: 6, unique: true)]
    private string $tag;

    #[ORM\Column(enumType: LifeForm::class)]
    private LifeForm $lifeForm;

    /** Shown to everybody on the public alliance page. */
    #[ORM\Column(type: Types::TEXT)]
    private string $externalText = '';

    /** Shown to members of every rank except prospects. */
    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $internalText = '';

    /** File name of the alliance picture (see ImageStore), if any. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $image = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, User> */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'alliance')]
    #[ORM\OrderBy(['username' => 'ASC'])]
    private Collection $members;

    public function __construct(string $name, string $tag, LifeForm $lifeForm, \DateTimeImmutable $createdAt)
    {
        $this->name = $name;
        $this->tag = $tag;
        $this->lifeForm = $lifeForm;
        $this->createdAt = $createdAt;
        $this->members = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getLifeForm(): LifeForm
    {
        return $this->lifeForm;
    }

    /** "Orden", "Syndikat", "Handelsgilde" or "Allianz". */
    /** Only players of the alliance's life form may join. */
    public function admits(User $user): bool
    {
        return $user->getLifeForm() === $this->lifeForm;
    }

    public const int MAX_TAX = 10;

    /** Tax on the members' credit income, 0–10 %, set by the founder. */
    #[ORM\Column(options: ['default' => 0])]
    private int $taxRate = 0;

    /** The alliance treasury; the founder pays out of it. */
    #[ORM\Column(options: ['default' => 0])]
    private float $treasury = 0.0;

    public function getTaxRate(): int
    {
        return $this->taxRate;
    }

    public function setTaxRate(int $rate): void
    {
        $this->taxRate = max(0, min(self::MAX_TAX, $rate));
    }

    public function getTreasury(): float
    {
        return $this->treasury;
    }

    /** Tax collected in this request, written to the treasury atomically (see PlanetService). */
    private float $pendingTax = 0.0;

    /** Takes the tax share of a member's income; returns it. */
    public function collectTax(float $income): float
    {
        $tax = $income * $this->taxRate / 100;
        $this->pendingTax += $tax;

        return $tax;
    }

    public function takePendingTax(): float
    {
        [$tax, $this->pendingTax] = [$this->pendingTax, 0.0];

        return $tax;
    }

    public function getType(): string
    {
        return $this->lifeForm->allianceType();
    }

    public function getExternalText(): string
    {
        return $this->externalText;
    }

    public function setExternalText(string $text): void
    {
        $this->externalText = $text;
    }

    public function getInternalText(): string
    {
        return $this->internalText;
    }

    public function setInternalText(string $text): void
    {
        $this->internalText = $text;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): void
    {
        $this->image = $image;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, User> */
    public function getMembers(): Collection
    {
        return $this->members;
    }
}

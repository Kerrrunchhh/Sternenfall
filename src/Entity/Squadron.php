<?php

namespace App\Entity;

use App\Repository\SquadronRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A player's squadron: any number of fleets – own ones and fleets released to him –
 * grouped to be selected and steered together.
 */
#[ORM\Entity(repositoryClass: SquadronRepository::class)]
class Squadron
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

    /** @var Collection<int, Fleet> */
    #[ORM\ManyToMany(targetEntity: Fleet::class)]
    #[ORM\JoinTable(name: 'squadron_fleet')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $fleets;

    public function __construct(User $owner, string $name)
    {
        $this->owner = $owner;
        $this->name = $name;
        $this->fleets = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /** @return Collection<int, Fleet> */
    public function getFleets(): Collection
    {
        return $this->fleets;
    }

    /** Fleets of the squadron its owner may (still) steer. @return list<Fleet> */
    public function getControllableFleets(): array
    {
        return array_values(array_filter($this->fleets->toArray(), fn (Fleet $f): bool => $f->isControllableBy($this->owner)));
    }

    public function add(Fleet $fleet): void
    {
        if (!$this->fleets->contains($fleet)) {
            $this->fleets->add($fleet);
        }
    }

    public function remove(Fleet $fleet): void
    {
        $this->fleets->removeElement($fleet);
    }
}

<?php

namespace App\Entity;

use App\Repository\WatcheeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Die überwachte Person (z. B. Papa). Bewusst als eigene Entität, damit das
 * System später mehrere Personen überwachen könnte.
 */
#[ORM\Entity(repositoryClass: WatcheeRepository::class)]
#[ORM\Table(name: 'watchee')]
class Watchee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private ?string $name = null;

    /**
     * Geheimes Token, das im Homescreen-Link steckt und den Zugang ersetzt.
     */
    #[ORM\Column(length: 64, unique: true)]
    private ?string $checkinToken = null;

    #[ORM\Column(length: 64, options: ['default' => 'Europe/Berlin'])]
    private string $timezone = 'Europe/Berlin';

    /**
     * @var Collection<int, CheckIn>
     */
    #[ORM\OneToMany(mappedBy: 'watchee', targetEntity: CheckIn::class, cascade: ['remove'])]
    #[ORM\OrderBy(['occurredAt' => 'DESC'])]
    private Collection $checkIns;

    #[ORM\OneToOne(mappedBy: 'watchee', targetEntity: AlertState::class, cascade: ['persist', 'remove'])]
    private ?AlertState $alertState = null;

    public function __construct()
    {
        $this->checkIns = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCheckinToken(): ?string
    {
        return $this->checkinToken;
    }

    public function setCheckinToken(string $checkinToken): static
    {
        $this->checkinToken = $checkinToken;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    /**
     * @return Collection<int, CheckIn>
     */
    public function getCheckIns(): Collection
    {
        return $this->checkIns;
    }

    public function addCheckIn(CheckIn $checkIn): static
    {
        if (!$this->checkIns->contains($checkIn)) {
            $this->checkIns->add($checkIn);
            $checkIn->setWatchee($this);
        }

        return $this;
    }

    /**
     * Der jüngste Check-in oder null, falls es noch keinen gibt.
     */
    public function getLastCheckIn(): ?CheckIn
    {
        return $this->checkIns->first() ?: null;
    }

    public function getAlertState(): ?AlertState
    {
        return $this->alertState;
    }

    public function setAlertState(AlertState $alertState): static
    {
        if ($alertState->getWatchee() !== $this) {
            $alertState->setWatchee($this);
        }
        $this->alertState = $alertState;

        return $this;
    }
}

<?php

namespace App\Entity;

use App\Repository\CheckInRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein einzelnes Lebenszeichen ("Mir geht's gut"-Tipp).
 * Zeitpunkte werden in UTC gespeichert und erst bei der Anzeige umgerechnet.
 */
#[ORM\Entity(repositoryClass: CheckInRepository::class)]
#[ORM\Table(name: 'check_in')]
#[ORM\Index(name: 'idx_checkin_occurred', columns: ['occurred_at'])]
class CheckIn
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Watchee::class, inversedBy: 'checkIns')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Watchee $watchee = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $occurredAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    /**
     * Was der Browser über sich selbst verrät – weil der User-Agent es nicht tut.
     *
     * Safari auf dem iPad meldet sich seit iPadOS 13 als "Macintosh", Zeichen
     * für Zeichen dieselbe Kennung wie ein echter Mac. Aus dem User-Agent sind
     * die beiden deshalb grundsätzlich nicht zu trennen; im Browser schon.
     *
     * Gespeichert werden die Messwerte ("touch=5;standalone=1"), nicht das
     * fertige Etikett: die Deutung kann sich ändern, die Messung nicht.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $deviceHint = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWatchee(): ?Watchee
    {
        return $this->watchee;
    }

    public function setWatchee(?Watchee $watchee): static
    {
        $this->watchee = $watchee;

        return $this;
    }

    public function getOccurredAt(): ?\DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getDeviceHint(): ?string
    {
        return $this->deviceHint;
    }

    public function setDeviceHint(?string $deviceHint): static
    {
        $this->deviceHint = $deviceHint !== null && $deviceHint !== ''
            ? mb_substr($deviceHint, 0, 64)
            : null;

        return $this;
    }

    public function setUserAgent(?string $userAgent): static
    {
        // Sicherheitshalber begrenzen.
        $this->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 255) : null;

        return $this;
    }
}

<?php

namespace App\Entity;

use App\Repository\AlertStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Merkt sich pro überwachter Person, ob gerade ein Alarm aktiv ist und wann
 * zuletzt eine Mail rausging – so wird nicht bei jedem Cron-Lauf gespammt.
 */
#[ORM\Entity(repositoryClass: AlertStateRepository::class)]
#[ORM\Table(name: 'alert_state')]
class AlertState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'alertState', targetEntity: Watchee::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Watchee $watchee = null;

    /**
     * true, solange die Alarmschwelle (ALERT_THRESHOLD_HOURS) überschritten
     * ist und noch kein neues Lebenszeichen kam.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $active = false;

    /**
     * Wann der Alarm erstmals ausgelöst wurde.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstTriggeredAt = null;

    /**
     * Wann zuletzt eine Alarm-Mail versendet wurde.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastNotifiedAt = null;

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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getFirstTriggeredAt(): ?\DateTimeImmutable
    {
        return $this->firstTriggeredAt;
    }

    public function setFirstTriggeredAt(?\DateTimeImmutable $firstTriggeredAt): static
    {
        $this->firstTriggeredAt = $firstTriggeredAt;

        return $this;
    }

    public function getLastNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->lastNotifiedAt;
    }

    public function setLastNotifiedAt(?\DateTimeImmutable $lastNotifiedAt): static
    {
        $this->lastNotifiedAt = $lastNotifiedAt;

        return $this;
    }
}

<?php

namespace App\Entity;

use App\Repository\GuardianRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ein "Wächter" – jemand, der das Dashboard einsehen und Alarme erhalten darf
 * (z. B. die Kinder).
 */
#[ORM\Entity(repositoryClass: GuardianRepository::class)]
#[ORM\Table(name: 'guardian')]
#[UniqueEntity(fields: ['email'], message: 'Diese E-Mail ist bereits vergeben.')]
class Guardian implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private ?string $name = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    /**
     * Ob diese Person die Alarm-Mails erhalten soll.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $receivesAlerts = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
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

    /**
     * Eindeutige Kennung für die Security-Schicht.
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function isReceivesAlerts(): bool
    {
        return $this->receivesAlerts;
    }

    public function setReceivesAlerts(bool $receivesAlerts): static
    {
        $this->receivesAlerts = $receivesAlerts;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // Keine temporären, sensiblen Daten gespeichert.
    }
}

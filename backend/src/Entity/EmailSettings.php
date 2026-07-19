<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmailSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailSettingsRepository::class)]
#[ORM\Table(name: 'email_settings')]
class EmailSettings
{
    public const PROVIDERS = ['SMTP2GO', 'GOOGLE_WORKSPACE', 'MICROSOFT_365', 'CUSTOM'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 30)] private string $provider = 'SMTP2GO';
    #[ORM\Column(length: 255)] private string $host = 'mail.smtp2go.com';
    #[ORM\Column] private int $port = 587;
    #[ORM\Column(length: 20)] private string $encryption = 'tls';
    #[ORM\Column(length: 255)] private string $username = '';
    #[ORM\Column(type: 'text', nullable: true)] private ?string $encryptedPassword = null;
    #[ORM\Column(length: 180)] private string $senderEmail = '';
    #[ORM\Column(length: 180)] private string $senderName = 'RiskPilot';
    #[ORM\Column(length: 180, nullable: true)] private ?string $replyTo = null;
    #[ORM\Column] private bool $enabled = false;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(Organization $organization)
    {
        $this->organization = $organization;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getEncryption(): string
    {
        return $this->encryption;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEncryptedPassword(): ?string
    {
        return $this->encryptedPassword;
    }

    public function getSenderEmail(): string
    {
        return $this->senderEmail;
    }

    public function getSenderName(): string
    {
        return $this->senderName;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function configure(string $provider, string $host, int $port, string $encryption, string $username, string $senderEmail, string $senderName, ?string $replyTo, bool $enabled): void
    {
        $this->provider = $provider;
        $this->host = $host;
        $this->port = $port;
        $this->encryption = $encryption;
        $this->username = $username;
        $this->senderEmail = mb_strtolower($senderEmail);
        $this->senderName = $senderName;
        $this->replyTo = null === $replyTo || '' === trim($replyTo) ? null : mb_strtolower(trim($replyTo));
        $this->enabled = $enabled;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setEncryptedPassword(string $password): void
    {
        $this->encryptedPassword = $password;
    }
}

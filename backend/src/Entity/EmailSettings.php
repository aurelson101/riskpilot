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
    #[ORM\Column(length: 255, nullable: true)] private ?string $oauthClientId = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $encryptedOauthClientSecret = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $encryptedAccessToken = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $encryptedRefreshToken = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $accessTokenExpiresAt = null;
    #[ORM\Column(length: 180, nullable: true)] private ?string $connectedEmail = null;
    #[ORM\Column(length: 100, nullable: true)] private ?string $oauthTenant = null;
    #[ORM\Column(length: 64, nullable: true)] private ?string $oauthStateHash = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $oauthStateExpiresAt = null;
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

    public function getOauthClientId(): ?string
    {
        return $this->oauthClientId;
    }

    public function getEncryptedOauthClientSecret(): ?string
    {
        return $this->encryptedOauthClientSecret;
    }

    public function getEncryptedAccessToken(): ?string
    {
        return $this->encryptedAccessToken;
    }

    public function getEncryptedRefreshToken(): ?string
    {
        return $this->encryptedRefreshToken;
    }

    public function getAccessTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->accessTokenExpiresAt;
    }

    public function getConnectedEmail(): ?string
    {
        return $this->connectedEmail;
    }

    public function getOauthTenant(): ?string
    {
        return $this->oauthTenant;
    }

    public function configureOauth(string $provider, string $clientId, ?string $encryptedClientSecret, ?string $tenant, string $senderName, ?string $replyTo): void
    {
        $providerChanged = $this->provider !== $provider;
        $this->disconnectOauth();
        if ($providerChanged) {
            $this->encryptedOauthClientSecret = null;
        }
        $this->provider = $provider;
        $this->oauthClientId = $clientId;
        if (null !== $encryptedClientSecret) {
            $this->encryptedOauthClientSecret = $encryptedClientSecret;
        }
        $this->oauthTenant = null === $tenant || '' === trim($tenant) ? 'common' : trim($tenant);
        $this->senderName = $senderName;
        $this->replyTo = null === $replyTo || '' === trim($replyTo) ? null : mb_strtolower(trim($replyTo));
        $this->username = '';
        $this->encryptedPassword = null;
        $this->enabled = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function beginOauth(string $state, \DateTimeImmutable $expiresAt): void
    {
        $this->oauthStateHash = hash('sha256', $state);
        $this->oauthStateExpiresAt = $expiresAt;
    }

    public function consumeOauthState(string $state): bool
    {
        $valid = null !== $this->oauthStateHash && null !== $this->oauthStateExpiresAt && $this->oauthStateExpiresAt > new \DateTimeImmutable() && hash_equals($this->oauthStateHash, hash('sha256', $state));
        $this->oauthStateHash = null;
        $this->oauthStateExpiresAt = null;

        return $valid;
    }

    public function connectOauth(string $encryptedAccessToken, ?string $encryptedRefreshToken, \DateTimeImmutable $expiresAt, string $email): void
    {
        $this->encryptedAccessToken = $encryptedAccessToken;
        if (null !== $encryptedRefreshToken) {
            $this->encryptedRefreshToken = $encryptedRefreshToken;
        }
        $this->accessTokenExpiresAt = $expiresAt;
        $this->connectedEmail = mb_strtolower($email);
        $this->senderEmail = mb_strtolower($email);
        $this->enabled = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function disconnectOauth(): void
    {
        $this->encryptedAccessToken = null;
        $this->encryptedRefreshToken = null;
        $this->accessTokenExpiresAt = null;
        $this->connectedEmail = null;
        $this->enabled = false;
    }

    public function configure(string $provider, string $host, int $port, string $encryption, string $username, string $senderEmail, string $senderName, ?string $replyTo, bool $enabled): void
    {
        $this->disconnectOauth();
        $this->oauthClientId = null;
        $this->encryptedOauthClientSecret = null;
        $this->oauthTenant = null;
        $this->oauthStateHash = null;
        $this->oauthStateExpiresAt = null;
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

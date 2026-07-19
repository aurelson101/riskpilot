<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IsmsDocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IsmsDocumentRepository::class)]
#[ORM\Table(name: 'isms_documents')]
#[ORM\Index(columns: ['organization_id', 'status'], name: 'idx_isms_document_org_status')]
class IsmsDocument
{
    public const VISIBILITY_ORGANIZATION = 'ORGANIZATION';
    public const VISIBILITY_RESTRICTED = 'RESTRICTED';
    public const VISIBILITIES = [self::VISIBILITY_ORGANIZATION, self::VISIBILITY_RESTRICTED];
    public const STATUSES = ['DRAFT', 'IN_REVIEW', 'APPROVED', 'ARCHIVED'];
    public const CLASSIFICATIONS = ['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'];

    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $owner;
    #[ORM\Column(length: 200)] private string $title;
    #[ORM\Column(length: 80)] private string $category;
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    #[ORM\Column(length: 20)] private string $classification = 'INTERNAL';
    #[ORM\Column(length: 20)] private string $visibility = self::VISIBILITY_ORGANIZATION;
    #[ORM\Column(type: 'text')] private string $content = '';
    #[ORM\Column] private int $currentVersion = 1;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    /** @var Collection<int, IsmsDocumentVersion> */
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: IsmsDocumentVersion::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['versionNumber' => 'DESC'])]
    private Collection $versions;
    /** @var Collection<int, IsmsDocumentAcl> */
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: IsmsDocumentAcl::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $aclEntries;
    /** @var Collection<int, IsmsDocumentShare> */
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: IsmsDocumentShare::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $shares;

    public function __construct(Organization $organization, User $owner, string $title, string $category, string $content)
    {
        $this->organization = $organization;
        $this->owner = $owner;
        $this->title = trim($title);
        $this->category = trim($category);
        $this->content = $content;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->versions = new ArrayCollection();
        $this->aclEntries = new ArrayCollection();
        $this->shares = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): self
    {
        $this->owner = $owner;
        $this->touch();

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getClassification(): string
    {
        return $this->classification;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getCurrentVersion(): int
    {
        return $this->currentVersion;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, IsmsDocumentVersion> */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    /** @return Collection<int, IsmsDocumentAcl> */
    public function getAclEntries(): Collection
    {
        return $this->aclEntries;
    }

    /** @return Collection<int, IsmsDocumentShare> */
    public function getShares(): Collection
    {
        return $this->shares;
    }

    public function updateMetadata(string $title, string $category, string $status, string $classification, string $visibility, User $owner): void
    {
        $this->title = trim($title);
        $this->category = trim($category);
        $this->status = $status;
        $this->classification = $classification;
        $this->visibility = $visibility;
        $this->owner = $owner;
        $this->touch();
    }

    public function revise(string $content, User $author, ?string $comment): void
    {
        if ($content === $this->content) {
            return;
        }
        ++$this->currentVersion;
        $this->content = $content;
        $this->versions->add(new IsmsDocumentVersion($this, $author, $this->currentVersion, $content, $comment));
        $this->touch();
    }

    public function initializeVersion(User $author, ?string $comment = null): void
    {
        if ($this->versions->isEmpty()) {
            $this->versions->add(new IsmsDocumentVersion($this, $author, 1, $this->content, $comment));
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

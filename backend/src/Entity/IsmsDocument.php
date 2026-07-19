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
    #[ORM\Column(length: 255, nullable: true)] private ?string $fileName = null;
    #[ORM\Column(length: 255, nullable: true)] private ?string $fileStorageName = null;
    #[ORM\Column(length: 150, nullable: true)] private ?string $fileMimeType = null;
    #[ORM\Column(nullable: true)] private ?int $fileSize = null;
    #[ORM\Column(length: 64, nullable: true)] private ?string $fileChecksum = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $approvedAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $nextReviewAt = null;
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

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function getFileStorageName(): ?string
    {
        return $this->fileStorageName;
    }

    public function getFileMimeType(): ?string
    {
        return $this->fileMimeType;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function getFileChecksum(): ?string
    {
        return $this->fileChecksum;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function getNextReviewAt(): ?\DateTimeImmutable
    {
        return $this->nextReviewAt;
    }

    public function isReviewOverdue(): bool
    {
        return 'APPROVED' === $this->status && null !== $this->nextReviewAt && $this->nextReviewAt < new \DateTimeImmutable('today');
    }

    public function hasFile(): bool
    {
        return null !== $this->fileStorageName;
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

    public function updateMetadata(string $title, string $category, string $status, string $classification, string $visibility, User $owner): bool
    {
        $title = trim($title);
        $category = trim($category);
        $changed = $title !== $this->title || $category !== $this->category || $status !== $this->status || $classification !== $this->classification || $visibility !== $this->visibility || $owner !== $this->owner;
        $this->title = $title;
        $this->category = $category;
        $this->status = $status;
        $this->classification = $classification;
        $this->visibility = $visibility;
        $this->owner = $owner;
        if ($changed) {
            $this->touch();
        }

        return $changed;
    }

    public function revise(string $content, User $author, ?string $comment): void
    {
        if ($content === $this->content) {
            return;
        }
        ++$this->currentVersion;
        $this->content = $content;
        $this->versions->add(new IsmsDocumentVersion($this, $author, $this->currentVersion, $content, $comment));
        $this->invalidateApproval();
        $this->touch();
    }

    public function recordRevision(User $author, ?string $comment): void
    {
        ++$this->currentVersion;
        $this->versions->add(new IsmsDocumentVersion($this, $author, $this->currentVersion, $this->content, $comment));
        $this->invalidateApproval();
        $this->touch();
    }

    public function initializeVersion(User $author, ?string $comment = null): void
    {
        if ($this->versions->isEmpty()) {
            $this->versions->add(new IsmsDocumentVersion($this, $author, 1, $this->content, $comment));
        }
    }

    public function attachFile(string $fileName, string $storageName, string $mimeType, int $size, string $checksum): void
    {
        $this->fileName = $fileName;
        $this->fileStorageName = $storageName;
        $this->fileMimeType = $mimeType;
        $this->fileSize = $size;
        $this->fileChecksum = $checksum;
        $this->touch();
    }

    public function detachFile(): void
    {
        $this->fileName = $this->fileStorageName = $this->fileMimeType = null;
        $this->fileSize = null;
        $this->fileChecksum = null;
        $this->touch();
    }

    public function approve(User $approver, \DateTimeImmutable $nextReviewAt): void
    {
        $this->status = 'APPROVED';
        $this->approvedBy = $approver;
        $this->approvedAt = new \DateTimeImmutable();
        $this->nextReviewAt = $nextReviewAt;
        $this->touch();
    }

    public function invalidateApproval(): void
    {
        if ('APPROVED' === $this->status) {
            $this->status = 'DRAFT';
        }
        $this->approvedBy = null;
        $this->approvedAt = null;
        $this->nextReviewAt = null;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

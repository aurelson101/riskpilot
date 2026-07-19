<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'isms_document_versions')]
#[ORM\UniqueConstraint(name: 'uniq_isms_document_version', columns: ['document_id', 'version_number'])]
class IsmsDocumentVersion
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'versions')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private IsmsDocument $document;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $author;
    #[ORM\Column] private int $versionNumber;
    #[ORM\Column(type: 'text')] private string $content;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $comment;
    #[ORM\Column(length: 255, nullable: true)] private ?string $fileName;
    #[ORM\Column(length: 255, nullable: true)] private ?string $fileStorageName;
    #[ORM\Column(length: 150, nullable: true)] private ?string $fileMimeType;
    #[ORM\Column(nullable: true)] private ?int $fileSize;
    #[ORM\Column(length: 64, nullable: true)] private ?string $fileChecksum;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(IsmsDocument $document, User $author, int $versionNumber, string $content, ?string $comment)
    {
        $this->document = $document;
        $this->author = $author;
        $this->versionNumber = $versionNumber;
        $this->content = $content;
        $this->comment = null === $comment || '' === trim($comment) ? null : trim($comment);
        $this->fileName = $document->getFileName();
        $this->fileStorageName = $document->getFileStorageName();
        $this->fileMimeType = $document->getFileMimeType();
        $this->fileSize = $document->getFileSize();
        $this->fileChecksum = $document->getFileChecksum();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function getFileChecksum(): ?string
    {
        return $this->fileChecksum;
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

    public function hasFile(): bool
    {
        return null !== $this->fileStorageName;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

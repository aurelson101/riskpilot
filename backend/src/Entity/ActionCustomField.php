<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'action_custom_fields')]
#[ORM\UniqueConstraint(name: 'uniq_action_field_org_key', columns: ['organization_id', 'field_key'])]
class ActionCustomField
{
    public const TYPES = ['TEXT', 'NUMBER', 'DATE', 'BOOLEAN', 'SELECT', 'URL'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\Column(length: 80, name: 'field_key')] private string $key;
    #[ORM\Column(length: 160)] private string $label;
    #[ORM\Column(length: 20)] private string $type;
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $options = [];
    #[ORM\Column(name: 'display_order')] private int $order = 0;
    #[ORM\Column] private bool $visible = true;
    #[ORM\Column] private bool $required = false;
    public function __construct(Organization $organization, string $key, string $label, string $type)
    {
        $this->organization = $organization;
        $this->key = $key;
        $this->label = $label;
        $this->type = $type;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /** @return list<string> */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /** @param list<string> $options */
    public function configure(string $label, string $type, array $options, int $order, bool $visible, bool $required): self
    {
        $this->label = $label;
        $this->type = $type;
        $this->options = $options;
        $this->order = $order;
        $this->visible = $visible;
        $this->required = $required;

        return $this;
    }
}

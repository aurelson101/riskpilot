<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Entity\Framework;
use App\Entity\Requirement;

/**
 * Packs de démarrage : identifiants officiels et intitulés reformulés, sans texte normatif protégé.
 * Les données sont dans Catalog/<clé>.php ; une exigence peut déclarer une référence parente en 4e position.
 */
final class StarterFrameworkCatalog
{
    /** L'ordre est stable : les jeux de démonstration et les index de packs en dépendent. */
    private const KEYS = ['rgpd', 'nis2', 'iso27001', 'ebios-rm', 'iso42001', 'tisax'];

    /** @var array<string, array{name: string, version: string, publisher: string, description: string, requirements: list<array{0: string, 1: string, 2: string, 3?: string}>}> */
    private array $definitions = [];

    /** @return list<string> */
    public function keys(): array
    {
        return self::KEYS;
    }

    /** @return array{name: string, version: string, publisher: string, description: string, requirements: list<array{0: string, 1: string, 2: string, 3?: string}>} */
    public function definition(string $key): array
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException(sprintf('Pack de conformité inconnu : %s.', $key));
        }

        return $this->definitions[$key] ??= require __DIR__.'/Catalog/'.$key.'.php';
    }

    /** @return array{Framework, list<Requirement>} */
    public function instantiate(string $key): array
    {
        $definition = $this->definition($key);
        $framework = (new Framework($definition['name'], $definition['version']))
            ->setPublisher($definition['publisher'])
            ->setDescription($definition['description']);
        $requirements = $this->requirementsFor($framework, $definition['requirements']);

        return [$framework, $requirements];
    }

    /**
     * Ajoute à un référentiel déjà installé les exigences du pack qui lui manquent, sans modifier celles qui existent.
     *
     * @param array<string, Requirement> $existing exigences actuelles indexées par référence
     *
     * @return list<Requirement> exigences nouvellement créées (à persister)
     */
    public function missingRequirements(string $key, Framework $framework, array $existing): array
    {
        $definition = $this->definition($key);
        $missing = array_values(array_filter($definition['requirements'], static fn (array $item): bool => !isset($existing[$item[0]])));

        return $this->requirementsFor($framework, $missing, $existing);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3?: string}> $items
     * @param array<string, Requirement>                               $known exigences déjà présentes, utilisables comme parents
     *
     * @return list<Requirement>
     */
    private function requirementsFor(Framework $framework, array $items, array $known = []): array
    {
        $created = [];
        foreach ($items as $item) {
            $created[$item[0]] = new Requirement($framework, $item[0], $item[1], $item[2]);
        }
        foreach ($items as $item) {
            if (isset($item[3])) {
                $created[$item[0]]->setParentRequirement($created[$item[3]] ?? $known[$item[3]] ?? null);
            }
        }

        return array_values($created);
    }
}

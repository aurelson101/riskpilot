<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Entity\Framework;
use App\Entity\Requirement;

final class StarterFrameworkCatalog
{
    /** @var array<string, array{name: string, version: string, publisher: string, description: string, requirements: list<array{string, string, string}>}> */
    private const PACKS = [
        'rgpd' => [
            'name' => 'RGPD', 'version' => '2016/679', 'publisher' => 'Union européenne',
            'description' => 'Pack de pilotage fondé sur le règlement public. Il ne constitue pas un avis juridique.',
            'requirements' => [
                ['ART-5', 'Principes relatifs au traitement des données', 'Principes'],
                ['ART-6', 'Licéité et base juridique des traitements', 'Licéité'],
                ['ART-12-14', 'Information transparente des personnes', 'Transparence'],
                ['ART-15-22', 'Gestion des droits des personnes', 'Droits'],
                ['ART-24', 'Responsabilité du responsable de traitement', 'Gouvernance'],
                ['ART-25', 'Protection des données dès la conception et par défaut', 'Conception'],
                ['ART-28', 'Encadrement des sous-traitants', 'Tiers'],
                ['ART-30', 'Registre des activités de traitement', 'Registre'],
                ['ART-32', 'Sécurité du traitement', 'Sécurité'],
                ['ART-33-34', 'Gestion et notification des violations', 'Incidents'],
                ['ART-35', 'Analyse d’impact relative à la protection des données', 'AIPD'],
            ],
        ],
        'nis2' => [
            'name' => 'NIS2', 'version' => '2022/2555', 'publisher' => 'Union européenne',
            'description' => 'Pack de préparation fondé sur la directive publique, à adapter à sa transposition nationale et à son périmètre d’assujettissement.',
            'requirements' => [
                ['ART-20', 'Responsabilité des organes de direction', 'Gouvernance'],
                ['ART-21.2-A', 'Politiques d’analyse des risques et de sécurité', 'Risques'],
                ['ART-21.2-B', 'Gestion des incidents', 'Incidents'],
                ['ART-21.2-C', 'Continuité, sauvegardes et gestion de crise', 'Résilience'],
                ['ART-21.2-D', 'Sécurité de la chaîne d’approvisionnement', 'Tiers'],
                ['ART-21.2-E', 'Sécurité des acquisitions, développements et vulnérabilités', 'Cycle de vie'],
                ['ART-21.2-F', 'Évaluation de l’efficacité des mesures', 'Assurance'],
                ['ART-21.2-G', 'Hygiène cyber et formation', 'Sensibilisation'],
                ['ART-21.2-H-J', 'Cryptographie, ressources humaines, accès et MFA', 'Protection'],
                ['ART-23', 'Notification des incidents significatifs', 'Notification'],
            ],
        ],
        'iso27001' => [
            'name' => 'ISO/IEC 27001', 'version' => '2022', 'publisher' => 'ISO/IEC',
            'description' => 'Pack métadonnées de préparation au SMSI. Aucun texte protégé de la norme n’est reproduit ; une copie sous licence reste nécessaire.',
            'requirements' => [
                ['CLAUSE-4', 'Contexte et périmètre du SMSI', 'SMSI'],
                ['CLAUSE-5', 'Leadership et responsabilités', 'SMSI'],
                ['CLAUSE-6', 'Planification et traitement des risques', 'SMSI'],
                ['CLAUSE-7', 'Ressources, compétences et documentation', 'SMSI'],
                ['CLAUSE-8', 'Fonctionnement opérationnel du SMSI', 'SMSI'],
                ['CLAUSE-9', 'Évaluation des performances', 'SMSI'],
                ['CLAUSE-10', 'Amélioration continue', 'SMSI'],
                ['ANNEX-A', 'Catalogue des mesures à sélectionner sous licence', 'Mesures'],
            ],
        ],
        'ebios-rm' => [
            'name' => 'EBIOS Risk Manager', 'version' => '2018', 'publisher' => 'ANSSI',
            'description' => 'Pack méthodologique de démarrage basé sur les ateliers du guide public EBIOS Risk Manager.',
            'requirements' => [
                ['ATELIER-1', 'Socle de sécurité et événements redoutés', 'Ateliers'],
                ['ATELIER-2', 'Sources de risque', 'Ateliers'],
                ['ATELIER-3', 'Scénarios stratégiques', 'Ateliers'],
                ['ATELIER-4', 'Scénarios opérationnels', 'Ateliers'],
                ['ATELIER-5', 'Traitement du risque', 'Ateliers'],
            ],
        ],
    ];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::PACKS);
    }

    /** @return array{name: string, version: string, publisher: string, description: string, requirements: list<array{string, string, string}>} */
    public function definition(string $key): array
    {
        return self::PACKS[$key] ?? throw new \InvalidArgumentException(sprintf('Pack de conformité inconnu : %s.', $key));
    }

    /** @return array{Framework, list<Requirement>} */
    public function instantiate(string $key): array
    {
        $definition = $this->definition($key);
        $framework = (new Framework($definition['name'], $definition['version']))
            ->setPublisher($definition['publisher'])
            ->setDescription($definition['description']);
        $requirements = array_map(
            static fn (array $item): Requirement => new Requirement($framework, $item[0], $item[1], $item[2]),
            $definition['requirements'],
        );

        return [$framework, $requirements];
    }
}

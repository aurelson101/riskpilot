<?php

declare(strict_types=1);

namespace App\Domain\Regulatory;

use App\Entity\AiSystem;

/**
 * Obligations du règlement sur l'IA (2024/1689) que le registre associe à un système, par références du pack `ai-act`.
 * Il s'agit d'une aide au cadrage fondée sur les qualifications saisies : elle ne remplace ni l'analyse juridique
 * ni la qualification du système, et les dates d'application restent à confirmer sur le texte officiel.
 */
final class AiActObligations
{
    private const PROVIDER_REQUIREMENTS = [
        'ART-6' => 'Classification et lien avec l’annexe III.',
        'ART-9' => 'Système de gestion des risques.',
        'ART-10' => 'Gouvernance des données.',
        'ART-11' => 'Documentation technique.',
        'ART-12' => 'Journalisation automatique.',
        'ART-13' => 'Transparence envers les déployeurs.',
        'ART-14' => 'Contrôle humain effectif.',
        'ART-15' => 'Exactitude, robustesse et cybersécurité.',
        'ART-16' => 'Obligations des fournisseurs de systèmes à haut risque.',
        'ART-17' => 'Système de gestion de la qualité.',
        'ART-43' => 'Évaluation de la conformité.',
        'ART-47-48' => 'Déclaration UE de conformité et marquage CE.',
        'ART-49' => 'Enregistrement dans la base de données de l’UE.',
        'ART-72' => 'Surveillance après commercialisation.',
    ];

    private const DEPLOYER_REQUIREMENTS = [
        'ART-26' => 'Obligations des déployeurs : usage conforme, contrôle humain, surveillance et journaux.',
        'ART-27' => 'Analyse d’impact sur les droits fondamentaux, selon la nature du déployeur et l’usage.',
    ];

    /** @return list<array{reference: string, reason: string}> */
    public function for(AiSystem $system): array
    {
        $role = $system->getAiActRole();
        if ('NOT_APPLICABLE' === $role) {
            return [];
        }
        if ('PROHIBITED' === $system->getRiskLevel()) {
            return [['reference' => 'ART-5', 'reason' => 'Pratique à risque inacceptable : le système ne doit être ni mis sur le marché ni utilisé.']];
        }
        $map = ['ART-4' => 'Maîtrise de l’IA : compétences des personnes qui exploitent le système.'];
        if (in_array($system->getRiskLevel(), ['LIMITED', 'HIGH'], true)) {
            $map['ART-50'] = 'Transparence envers les personnes en interaction avec le système ou exposées à ses contenus.';
        }
        if ('HIGH' === $system->getRiskLevel()) {
            // Sans rôle renseigné, les deux familles d'obligations sont présentées : à l'utilisateur de trancher.
            if ('DEPLOYER' !== $role) {
                $map += self::PROVIDER_REQUIREMENTS;
            }
            if (in_array($role, ['DEPLOYER', null], true)) {
                $map += self::DEPLOYER_REQUIREMENTS;
            }
            $map['ART-73'] = 'Signalement des incidents graves aux autorités de surveillance du marché.';
        }
        if ($system->isGeneralPurpose()) {
            $map['ART-53'] = 'Obligations des fournisseurs de modèles à usage général.';
            if ($system->hasSystemicRisk()) {
                $map['ART-51'] = 'Modèle classé à risque systémique.';
                $map['ART-55'] = 'Obligations renforcées : évaluation, atténuation et cybersécurité.';
            }
        }

        $items = [];
        foreach ($map as $reference => $reason) {
            $items[] = ['reference' => $reference, 'reason' => $reason];
        }

        return $items;
    }
}

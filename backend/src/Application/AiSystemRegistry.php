<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\AiSettings;
use App\Entity\AiSystem;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Tient à jour l'entrée du registre IA qui décrit le copilote RiskPilot lui-même. */
final readonly class AiSystemRegistry
{
    public const COPILOT_NAME = 'Copilote RiskPilot';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** À appeler lorsqu'un administrateur enregistre la configuration IA ; ne persiste pas (flush par l'appelant). */
    public function syncCopilot(AiSettings $settings, User $actor): void
    {
        $organization = $settings->getOrganization();
        $existing = $this->entityManager->getRepository(AiSystem::class)->findOneBy(['organization' => $organization, 'builtIn' => true]);
        $categories = 'CONTEXTUAL' === $settings->getDataPolicy()
            ? ['Synthèse de conformité', 'Risques et actions (titres, responsables)', 'Commentaires et preuves d’évaluation']
            : ['Synthèse de conformité (compteurs et exigences)'];
        $purpose = 'Assistance à la rédaction et au pilotage GRC : réponses guidées, brouillons de risques et d’actions relus et confirmés par un humain. Aucune écriture automatique.';
        $oversight = 'Toute création passe par un brouillon éditable et une confirmation humaine ; la décision est tracée dans le journal des interactions IA.';
        $status = $settings->isEnabled() ? 'ACTIVE' : 'SUSPENDED';
        $review = new \DateTimeImmutable('+12 months');
        if ($existing instanceof AiSystem) {
            $existing->update($existing->getOwner(), $existing->getName(), $existing->getPurpose(), $settings->getProvider(), $settings->getModel(), $categories, $existing->getRiskLevel(), $status, $existing->getHumanOversight() ?? $oversight, $existing->getReviewAt() ?? $review);

            return;
        }
        $this->entityManager->persist(new AiSystem($organization, $actor, self::COPILOT_NAME, $purpose, $settings->getProvider(), $settings->getModel(), $categories, 'LIMITED', $status, $oversight, $review, true));
    }
}

<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Regulatory\CraProductReadiness;
use App\Domain\Regulatory\DoraRegisterCompleteness;
use App\Domain\Regulatory\RegulatoryDeadlines;
use App\Entity\IctArrangement;
use App\Entity\User;
use App\Repository\AiSystemRepository;
use App\Repository\DigitalProductRepository;
use App\Repository\IctArrangementRepository;
use App\Repository\RegulatoryIncidentRepository;

/**
 * Synthèse des volets réglementaires (DORA, CRA, AI Act) transmise au copilote. Elle suit la politique de données de
 * l'organisation : des compteurs seulement en mode MINIMAL, quelques échéances nommées en mode CONTEXTUAL. Aucune
 * description libre d'incident ni de produit n'est transmise.
 */
final readonly class RegulatoryAiContext
{
    public function __construct(
        private RegulatoryIncidentRepository $incidents,
        private DigitalProductRepository $products,
        private IctArrangementRepository $arrangements,
        private AiSystemRepository $aiSystems,
        private RegulatoryDeadlines $deadlines,
        private CraProductReadiness $craReadiness,
        private DoraRegisterCompleteness $doraCompleteness,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(User $actor, string $dataPolicy, \DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $open = [];
        $overdue = 0;
        $dueSoon = 0;
        $urgent = [];
        foreach ($this->incidents->findVisibleTo($actor) as $incident) {
            if (in_array($incident->getStatus(), ['CLOSED'], true)) {
                continue;
            }
            $steps = $this->deadlines->steps($incident->getRegime(), $incident->getAwareAt(), $incident->isApplicable(), $incident->deadlineFacts(), $incident->completedDates(), $now);
            $worst = $this->deadlines->worstStatus($steps);
            $open[] = $incident;
            $overdue += 'OVERDUE' === $worst ? 1 : 0;
            $dueSoon += 'DUE_SOON' === $worst ? 1 : 0;
            foreach ($steps as $step) {
                if (in_array($step['status'], ['OVERDUE', 'DUE_SOON'], true)) {
                    $urgent[] = ['regime' => $incident->getRegime(), 'title' => mb_substr($incident->getTitle(), 0, 120), 'step' => $step['key'], 'dueAt' => $step['dueAt'], 'status' => $step['status']];
                }
            }
        }
        $systems = $this->aiSystems->findVisibleTo($actor);
        $products = array_filter($this->products->findVisibleTo($actor), static fn ($product): bool => !in_array($product->getStatus(), ['WITHDRAWN', 'SUPPORT_ENDED'], true));
        $arrangements = $this->arrangements->findVisibleTo($actor);
        $context = [
            'regulatoryIncidents' => ['open' => count($open), 'overdue' => $overdue, 'dueSoon' => $dueSoon],
            'aiAct' => [
                'systems' => count($systems),
                'highRisk' => count(array_filter($systems, static fn ($system): bool => 'HIGH' === $system->getRiskLevel())),
                'prohibited' => count(array_filter($systems, static fn ($system): bool => 'PROHIBITED' === $system->getRiskLevel())),
            ],
            'cra' => ['products' => count($products), 'withGaps' => count(array_filter($products, fn ($product): bool => [] !== $this->craReadiness->gaps($product, $now)))],
            'dora' => [
                'ictArrangements' => count($arrangements),
                'supportingCriticalFunctions' => count(array_filter($arrangements, static fn (IctArrangement $item): bool => $item->supportsCriticalFunction())),
                'withRegisterGaps' => count(array_filter($arrangements, fn (IctArrangement $item): bool => [] !== $this->doraCompleteness->gaps($item, $now))),
            ],
        ];
        if ('CONTEXTUAL' === $dataPolicy) {
            usort($urgent, static fn (array $left, array $right): int => $left['dueAt'] <=> $right['dueAt']);
            $context['urgentRegulatoryDeadlines'] = array_slice($urgent, 0, 5);
        }

        return $context;
    }
}

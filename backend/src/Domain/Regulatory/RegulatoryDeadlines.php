<?php

declare(strict_types=1);

namespace App\Domain\Regulatory;

/**
 * Calendriers de notification des incidents et vulnérabilités selon trois règlements européens.
 * Ils complètent {@see \App\Domain\Resilience\Nis2Deadlines} (directive NIS2). Les délais sont des repères de pilotage :
 * l'autorité compétente, les actes délégués et les normes techniques applicables restent déterminants.
 *
 * - DORA (règlement 2022/2554, art. 19) : notification initiale (4 h après la qualification « majeur », au plus tard 24 h
 *   après la prise de connaissance), rapport intermédiaire (72 h après la notification initiale), rapport final (un mois
 *   après le dernier rapport intermédiaire).
 * - CRA (règlement 2024/2847, art. 14) : alerte précoce sous 24 h, notification sous 72 h, rapport final 14 jours après
 *   la disponibilité d'un correctif (vulnérabilité) ou un mois après la notification (incident grave).
 * - AI Act (règlement 2024/1689, art. 73) : signalement d'un incident grave sous 15 jours, 10 jours en cas de décès,
 *   2 jours en cas d'infraction généralisée ou d'incident grave touchant une infrastructure critique.
 */
final class RegulatoryDeadlines
{
    public const DORA = 'DORA_MAJOR_INCIDENT';
    public const CRA_VULNERABILITY = 'CRA_VULNERABILITY';
    public const CRA_INCIDENT = 'CRA_INCIDENT';
    public const AI_ACT = 'AI_ACT_SERIOUS_INCIDENT';
    public const REGIMES = [self::DORA, self::CRA_VULNERABILITY, self::CRA_INCIDENT, self::AI_ACT];
    public const DUE_SOON_SECONDS = 14400;

    /**
     * @param array{classifiedAt?: ?\DateTimeImmutable, correctiveAvailableAt?: ?\DateTimeImmutable, widespread?: bool, criticalInfrastructure?: bool, death?: bool} $facts
     * @param array<string, \DateTimeImmutable>                                                                                                                      $done  dates de dépôt par clé d'étape
     *
     * @return list<array{key: string, article: string, dueAt: string, doneAt: ?string, status: string, late: bool, provisional: bool}>
     */
    public function steps(string $regime, \DateTimeImmutable $awareAt, bool $applicable, array $facts, array $done, \DateTimeImmutable $now): array
    {
        $step = fn (string $key, string $article, \DateTimeImmutable $dueAt, bool $provisional = false): array => $this->step($key, $article, $dueAt, $done[$key] ?? null, $applicable, $provisional, $now);

        return match ($regime) {
            self::DORA => $this->dora($awareAt, $facts, $done, $step),
            self::CRA_VULNERABILITY => $this->craVulnerability($awareAt, $facts, $done, $step),
            self::CRA_INCIDENT => $this->craIncident($awareAt, $done, $step),
            self::AI_ACT => $this->aiAct($awareAt, $facts, $step),
            default => throw new \InvalidArgumentException('Régime de notification inconnu.'),
        };
    }

    /** @return list<string> clés d'étapes valides pour le régime */
    public function stepKeys(string $regime): array
    {
        return match ($regime) {
            self::DORA => ['INITIAL', 'INTERMEDIATE', 'FINAL'],
            self::CRA_VULNERABILITY, self::CRA_INCIDENT => ['EARLY_WARNING', 'NOTIFICATION', 'FINAL'],
            self::AI_ACT => ['REPORT'],
            default => throw new \InvalidArgumentException('Régime de notification inconnu.'),
        };
    }

    /** @param list<array{status: string}> $steps */
    public function worstStatus(array $steps): string
    {
        $statuses = array_column($steps, 'status');
        foreach (['OVERDUE', 'DUE_SOON', 'PENDING'] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return in_array('DONE', $statuses, true) ? 'DONE' : 'NOT_APPLICABLE';
    }

    /**
     * @param array<string, mixed>              $facts
     * @param array<string, \DateTimeImmutable> $done
     *
     * @return list<array{key: string, article: string, dueAt: string, doneAt: ?string, status: string, late: bool, provisional: bool}>
     */
    private function dora(\DateTimeImmutable $awareAt, array $facts, array $done, callable $step): array
    {
        $classifiedAt = $facts['classifiedAt'] ?? null;
        $initialDue = $classifiedAt instanceof \DateTimeImmutable ? min($classifiedAt->modify('+4 hours'), $awareAt->modify('+24 hours')) : $awareAt->modify('+24 hours');
        $initialBase = $done['INITIAL'] ?? $initialDue;
        $intermediateDue = $initialBase->modify('+72 hours');
        $finalBase = $done['INTERMEDIATE'] ?? $intermediateDue;

        return [
            // Sans date de qualification, 24 h après la prise de connaissance est une borne haute : l'échéance réelle peut être plus proche.
            $step('INITIAL', 'ART-19.4-A', $initialDue, !$classifiedAt instanceof \DateTimeImmutable),
            $step('INTERMEDIATE', 'ART-19.4-B', $intermediateDue, !isset($done['INITIAL'])),
            $step('FINAL', 'ART-19.4-C', $finalBase->modify('+1 month'), !isset($done['INTERMEDIATE'])),
        ];
    }

    /**
     * @param array<string, mixed>              $facts
     * @param array<string, \DateTimeImmutable> $done
     *
     * @return list<array{key: string, article: string, dueAt: string, doneAt: ?string, status: string, late: bool, provisional: bool}>
     */
    private function craVulnerability(\DateTimeImmutable $awareAt, array $facts, array $done, callable $step): array
    {
        $corrective = $facts['correctiveAvailableAt'] ?? null;
        $finalBase = $corrective instanceof \DateTimeImmutable ? $corrective : ($done['NOTIFICATION'] ?? $awareAt->modify('+72 hours'));

        return [
            $step('EARLY_WARNING', 'ART-14.2-A', $awareAt->modify('+24 hours')),
            $step('NOTIFICATION', 'ART-14.2-B', $awareAt->modify('+72 hours')),
            // Tant qu'aucun correctif n'est disponible, l'échéance est une estimation fondée sur la notification.
            $step('FINAL', 'ART-14.2-C', $finalBase->modify('+14 days'), !$corrective instanceof \DateTimeImmutable),
        ];
    }

    /**
     * @param array<string, \DateTimeImmutable> $done
     *
     * @return list<array{key: string, article: string, dueAt: string, doneAt: ?string, status: string, late: bool, provisional: bool}>
     */
    private function craIncident(\DateTimeImmutable $awareAt, array $done, callable $step): array
    {
        return [
            $step('EARLY_WARNING', 'ART-14.4-A', $awareAt->modify('+24 hours')),
            $step('NOTIFICATION', 'ART-14.4-B', $awareAt->modify('+72 hours')),
            $step('FINAL', 'ART-14.4-C', ($done['NOTIFICATION'] ?? $awareAt->modify('+72 hours'))->modify('+1 month'), !isset($done['NOTIFICATION'])),
        ];
    }

    /**
     * @param array<string, mixed> $facts
     *
     * @return list<array{key: string, article: string, dueAt: string, doneAt: ?string, status: string, late: bool, provisional: bool}>
     */
    private function aiAct(\DateTimeImmutable $awareAt, array $facts, callable $step): array
    {
        // Le délai le plus court applicable l'emporte : 2 jours, puis 10 jours (décès), puis 15 jours.
        if (true === ($facts['widespread'] ?? false) || true === ($facts['criticalInfrastructure'] ?? false)) {
            return [$step('REPORT', 'ART-73.3', $awareAt->modify('+2 days'))];
        }
        if (true === ($facts['death'] ?? false)) {
            return [$step('REPORT', 'ART-73.4', $awareAt->modify('+10 days'))];
        }

        return [$step('REPORT', 'ART-73.2', $awareAt->modify('+15 days'))];
    }

    /** @return array{key: string, article: string, dueAt: string, doneAt: ?string, status: string, late: bool, provisional: bool} */
    private function step(string $key, string $article, \DateTimeImmutable $dueAt, ?\DateTimeImmutable $doneAt, bool $applicable, bool $provisional, \DateTimeImmutable $now): array
    {
        if (!$applicable && null === $doneAt) {
            $status = 'NOT_APPLICABLE';
        } elseif (null !== $doneAt) {
            $status = 'DONE';
        } elseif ($now > $dueAt) {
            $status = 'OVERDUE';
        } elseif ($dueAt->getTimestamp() - $now->getTimestamp() <= self::DUE_SOON_SECONDS) {
            $status = 'DUE_SOON';
        } else {
            $status = 'PENDING';
        }

        return [
            'key' => $key, 'article' => $article, 'dueAt' => $dueAt->format(DATE_ATOM), 'doneAt' => $doneAt?->format(DATE_ATOM),
            'status' => $status, 'late' => null !== $doneAt && $doneAt > $dueAt, 'provisional' => $provisional && null === $doneAt,
        ];
    }
}

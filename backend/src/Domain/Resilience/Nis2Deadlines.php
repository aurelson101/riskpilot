<?php

declare(strict_types=1);

namespace App\Domain\Resilience;

/**
 * Calendrier de notification d'un incident significatif (directive NIS2, art. 23 §4) :
 * alerte précoce sous 24 h, notification sous 72 h, rapport final un mois après la notification.
 * Le point de départ est la prise de connaissance de l'incident ; l'autorité nationale peut fixer des règles plus strictes.
 */
final class Nis2Deadlines
{
    public const DUE_SOON_SECONDS = 14400;

    /**
     * @return list<array{key: string, article: string, dueAt: string, doneAt: ?string, status: string, late: bool, provisional: bool}>
     */
    public function steps(\DateTimeImmutable $awareAt, bool $significant, ?\DateTimeImmutable $earlyWarningAt, ?\DateTimeImmutable $notifiedAt, ?\DateTimeImmutable $finalReportAt, \DateTimeImmutable $now): array
    {
        $finalBase = $notifiedAt ?? $awareAt->modify('+72 hours');

        return [
            $this->step('EARLY_WARNING', 'ART-23.4-A', $awareAt->modify('+24 hours'), $earlyWarningAt, $significant, false, $now),
            $this->step('NOTIFICATION', 'ART-23.4-B', $awareAt->modify('+72 hours'), $notifiedAt, $significant, false, $now),
            // Sans notification déposée, l'échéance est une borne haute calculée sur la dernière date de notification possible.
            $this->step('FINAL_REPORT', 'ART-23.4-D', $finalBase->modify('+1 month'), $finalReportAt, $significant, null === $notifiedAt, $now),
        ];
    }

    /**
     * @param list<array{status: string}> $steps
     */
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

    /** @return array{key: string, article: string, dueAt: string, doneAt: ?string, status: string, late: bool, provisional: bool} */
    private function step(string $key, string $article, \DateTimeImmutable $dueAt, ?\DateTimeImmutable $doneAt, bool $significant, bool $provisional, \DateTimeImmutable $now): array
    {
        if (!$significant && null === $doneAt) {
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

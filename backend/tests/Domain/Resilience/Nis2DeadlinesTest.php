<?php

declare(strict_types=1);

namespace App\Tests\Domain\Resilience;

use App\Domain\Resilience\Nis2Deadlines;
use PHPUnit\Framework\TestCase;

final class Nis2DeadlinesTest extends TestCase
{
    private function steps(string $now, bool $significant = true, ?string $early = null, ?string $notified = null, ?string $final = null): array
    {
        $date = static fn (?string $value): ?\DateTimeImmutable => null === $value ? null : new \DateTimeImmutable($value);

        return (new Nis2Deadlines())->steps(new \DateTimeImmutable('2026-11-01T08:00:00+00:00'), $significant, $date($early), $date($notified), $date($final), new \DateTimeImmutable($now));
    }

    public function testDeadlinesAreCountedFromAwarenessOfTheIncident(): void
    {
        $steps = $this->steps('2026-11-01T09:00:00+00:00');

        self::assertSame(['EARLY_WARNING', 'NOTIFICATION', 'FINAL_REPORT'], array_column($steps, 'key'));
        self::assertSame('2026-11-02T08:00:00+00:00', $steps[0]['dueAt']);
        self::assertSame('2026-11-04T08:00:00+00:00', $steps[1]['dueAt']);
        self::assertSame('PENDING', $steps[0]['status']);
    }

    public function testFinalReportIsDueOneMonthAfterTheNotificationAndProvisionalBeforeIt(): void
    {
        $before = $this->steps('2026-11-01T09:00:00+00:00')[2];
        self::assertSame('2026-12-04T08:00:00+00:00', $before['dueAt'], 'Upper bound: the latest possible notification (72 h) plus one month.');
        self::assertTrue($before['provisional']);

        $after = $this->steps('2026-11-01T20:00:00+00:00', true, '2026-11-01T10:00:00+00:00', '2026-11-02T12:00:00+00:00')[2];
        self::assertSame('2026-12-02T12:00:00+00:00', $after['dueAt']);
        self::assertFalse($after['provisional']);
    }

    public function testStatusTurnsDueSoonThenOverdueAndDoneStepsStayDone(): void
    {
        self::assertSame('DUE_SOON', $this->steps('2026-11-02T05:00:00+00:00')[0]['status'], 'Less than four hours left.');
        self::assertSame('OVERDUE', $this->steps('2026-11-02T08:00:01+00:00')[0]['status']);

        $late = $this->steps('2026-11-05T00:00:00+00:00', true, '2026-11-02T09:00:00+00:00')[0];
        self::assertSame('DONE', $late['status']);
        self::assertTrue($late['late'], 'A report filed after its deadline is flagged as late.');

        $onTime = $this->steps('2026-11-05T00:00:00+00:00', true, '2026-11-01T10:00:00+00:00')[0];
        self::assertFalse($onTime['late']);
    }

    public function testNonSignificantIncidentHasNoObligationUnlessAReportWasFiled(): void
    {
        $steps = $this->steps('2026-11-09T00:00:00+00:00', false);
        self::assertSame(['NOT_APPLICABLE', 'NOT_APPLICABLE', 'NOT_APPLICABLE'], array_column($steps, 'status'));
        self::assertSame('NOT_APPLICABLE', (new Nis2Deadlines())->worstStatus($steps));
    }

    public function testWorstStatusPrioritisesTheMostUrgentStep(): void
    {
        $deadlines = new Nis2Deadlines();
        self::assertSame('OVERDUE', $deadlines->worstStatus([['status' => 'DONE'], ['status' => 'PENDING'], ['status' => 'OVERDUE']]));
        self::assertSame('DUE_SOON', $deadlines->worstStatus([['status' => 'DONE'], ['status' => 'DUE_SOON'], ['status' => 'PENDING']]));
        self::assertSame('DONE', $deadlines->worstStatus([['status' => 'DONE'], ['status' => 'DONE']]));
    }
}

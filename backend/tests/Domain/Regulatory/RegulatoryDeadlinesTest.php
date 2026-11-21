<?php

declare(strict_types=1);

namespace App\Tests\Domain\Regulatory;

use App\Domain\Regulatory\RegulatoryDeadlines;
use PHPUnit\Framework\TestCase;

final class RegulatoryDeadlinesTest extends TestCase
{
    private function at(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    public function testDoraInitialNotificationIsFourHoursAfterClassificationAndCappedAtTwentyFourHours(): void
    {
        $aware = $this->at('2026-11-02T08:00:00+00:00');
        $early = (new RegulatoryDeadlines())->steps(RegulatoryDeadlines::DORA, $aware, true, ['classifiedAt' => $this->at('2026-11-02T10:00:00+00:00')], [], $this->at('2026-11-02T09:00:00+00:00'));
        self::assertSame('2026-11-02T14:00:00+00:00', $early[0]['dueAt']);
        self::assertFalse($early[0]['provisional']);

        $late = (new RegulatoryDeadlines())->steps(RegulatoryDeadlines::DORA, $aware, true, ['classifiedAt' => $this->at('2026-11-05T10:00:00+00:00')], [], $this->at('2026-11-02T09:00:00+00:00'));
        self::assertSame('2026-11-03T08:00:00+00:00', $late[0]['dueAt'], 'Never later than 24 hours after awareness.');
    }

    public function testDoraIntermediateAndFinalFollowTheDatesActuallyFiled(): void
    {
        $aware = $this->at('2026-11-02T08:00:00+00:00');
        $steps = (new RegulatoryDeadlines())->steps(
            RegulatoryDeadlines::DORA,
            $aware,
            true,
            ['classifiedAt' => $this->at('2026-11-02T09:00:00+00:00')],
            ['INITIAL' => $this->at('2026-11-02T10:00:00+00:00'), 'INTERMEDIATE' => $this->at('2026-11-04T09:00:00+00:00')],
            $this->at('2026-11-05T00:00:00+00:00'),
        );
        self::assertSame('DONE', $steps[0]['status']);
        self::assertSame('2026-11-05T10:00:00+00:00', $steps[1]['dueAt'], '72 hours after the initial notification.');
        self::assertFalse($steps[1]['late']);
        self::assertSame('2026-12-04T09:00:00+00:00', $steps[2]['dueAt'], 'One month after the latest intermediate report.');
        self::assertFalse($steps[2]['provisional']);
    }

    public function testDoraWithoutClassificationIsProvisional(): void
    {
        $steps = (new RegulatoryDeadlines())->steps(RegulatoryDeadlines::DORA, $this->at('2026-11-02T08:00:00+00:00'), true, [], [], $this->at('2026-11-02T09:00:00+00:00'));
        self::assertTrue($steps[0]['provisional']);
        self::assertTrue($steps[1]['provisional']);
    }

    public function testCraVulnerabilityFinalReportRunsFourteenDaysFromTheCorrectiveMeasure(): void
    {
        $aware = $this->at('2026-11-02T08:00:00+00:00');
        $deadlines = new RegulatoryDeadlines();
        $before = $deadlines->steps(RegulatoryDeadlines::CRA_VULNERABILITY, $aware, true, [], [], $this->at('2026-11-02T09:00:00+00:00'));
        self::assertSame('2026-11-03T08:00:00+00:00', $before[0]['dueAt']);
        self::assertSame('2026-11-05T08:00:00+00:00', $before[1]['dueAt']);
        self::assertTrue($before[2]['provisional']);

        $after = $deadlines->steps(RegulatoryDeadlines::CRA_VULNERABILITY, $aware, true, ['correctiveAvailableAt' => $this->at('2026-11-10T00:00:00+00:00')], [], $this->at('2026-11-02T09:00:00+00:00'));
        self::assertSame('2026-11-24T00:00:00+00:00', $after[2]['dueAt']);
        self::assertFalse($after[2]['provisional']);
    }

    public function testCraIncidentFinalReportIsOneMonthAfterTheNotification(): void
    {
        $steps = (new RegulatoryDeadlines())->steps(RegulatoryDeadlines::CRA_INCIDENT, $this->at('2026-11-02T08:00:00+00:00'), true, [], ['NOTIFICATION' => $this->at('2026-11-04T08:00:00+00:00')], $this->at('2026-11-05T00:00:00+00:00'));
        self::assertSame('2026-12-04T08:00:00+00:00', $steps[2]['dueAt']);
        self::assertSame('DONE', $steps[1]['status']);
    }

    public function testAiActUsesTheShortestApplicableDeadline(): void
    {
        $aware = $this->at('2026-11-02T08:00:00+00:00');
        $now = $this->at('2026-11-02T09:00:00+00:00');
        $deadlines = new RegulatoryDeadlines();
        $default = $deadlines->steps(RegulatoryDeadlines::AI_ACT, $aware, true, [], [], $now)[0];
        self::assertSame(['ART-73.2', '2026-11-17T08:00:00+00:00'], [$default['article'], $default['dueAt']]);
        $death = $deadlines->steps(RegulatoryDeadlines::AI_ACT, $aware, true, ['death' => true], [], $now)[0];
        self::assertSame(['ART-73.4', '2026-11-12T08:00:00+00:00'], [$death['article'], $death['dueAt']]);
        $widespread = $deadlines->steps(RegulatoryDeadlines::AI_ACT, $aware, true, ['death' => true, 'widespread' => true], [], $now)[0];
        self::assertSame(['ART-73.3', '2026-11-04T08:00:00+00:00'], [$widespread['article'], $widespread['dueAt']]);
        $critical = $deadlines->steps(RegulatoryDeadlines::AI_ACT, $aware, true, ['criticalInfrastructure' => true], [], $now)[0];
        self::assertSame('ART-73.3', $critical['article']);
    }

    public function testStatusesFollowTheClock(): void
    {
        $aware = $this->at('2026-11-02T08:00:00+00:00');
        $deadlines = new RegulatoryDeadlines();
        self::assertSame('PENDING', $deadlines->steps(RegulatoryDeadlines::AI_ACT, $aware, true, [], [], $this->at('2026-11-03T08:00:00+00:00'))[0]['status']);
        self::assertSame('DUE_SOON', $deadlines->steps(RegulatoryDeadlines::AI_ACT, $aware, true, [], [], $this->at('2026-11-17T05:00:00+00:00'))[0]['status']);
        self::assertSame('OVERDUE', $deadlines->steps(RegulatoryDeadlines::AI_ACT, $aware, true, [], [], $this->at('2026-11-17T09:00:00+00:00'))[0]['status']);
        $late = $deadlines->steps(RegulatoryDeadlines::AI_ACT, $aware, true, [], ['REPORT' => $this->at('2026-11-18T00:00:00+00:00')], $this->at('2026-11-19T00:00:00+00:00'))[0];
        self::assertSame('DONE', $late['status']);
        self::assertTrue($late['late']);
        self::assertSame('NOT_APPLICABLE', $deadlines->steps(RegulatoryDeadlines::AI_ACT, $aware, false, [], [], $this->at('2026-11-19T00:00:00+00:00'))[0]['status']);
    }

    public function testWorstStatusAndStepKeys(): void
    {
        $deadlines = new RegulatoryDeadlines();
        self::assertSame('OVERDUE', $deadlines->worstStatus([['status' => 'DONE'], ['status' => 'OVERDUE'], ['status' => 'PENDING']]));
        self::assertSame('DONE', $deadlines->worstStatus([['status' => 'DONE'], ['status' => 'NOT_APPLICABLE']]));
        self::assertSame(['REPORT'], $deadlines->stepKeys(RegulatoryDeadlines::AI_ACT));
        self::assertSame(['INITIAL', 'INTERMEDIATE', 'FINAL'], $deadlines->stepKeys(RegulatoryDeadlines::DORA));
        $this->expectException(\InvalidArgumentException::class);
        $deadlines->steps('UNKNOWN', $this->at('2026-11-02T08:00:00+00:00'), true, [], [], $this->at('2026-11-02T08:00:00+00:00'));
    }
}

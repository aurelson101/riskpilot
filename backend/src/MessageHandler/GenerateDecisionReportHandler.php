<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\OperationalRecord;
use App\Message\GenerateDecisionReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenerateDecisionReportHandler
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(GenerateDecisionReport $message): void
    {
        $run = $this->entityManager->getRepository(OperationalRecord::class)->find($message->runId);
        if (!$run instanceof OperationalRecord || 'REPORT_RUN' !== $run->getType() || 'IN_PROGRESS' !== $run->getStatus()) {
            return;
        }

        // The immutable tenant snapshot is prepared before dispatch. The worker owns
        // the potentially expensive rendering-ready finalisation and audit timestamp.
        $details = $run->getDetails();
        $details['completedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $run->update($run->getTitle(), 'COMPLETED', $details, $run->getOwner(), null);
        $this->entityManager->flush();
    }
}

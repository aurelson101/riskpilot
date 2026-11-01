<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\NotificationService;
use App\Domain\Resilience\Nis2Deadlines;
use App\Repository\SecurityIncidentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:nis2:remind-deadlines', description: 'Alerte les responsables d’incidents significatifs dont une échéance NIS2 approche ou est dépassée.')]
final class RemindNis2DeadlinesCommand extends Command
{
    public function __construct(
        private readonly SecurityIncidentRepository $incidents,
        private readonly Nis2Deadlines $deadlines,
        private readonly NotificationService $notifications,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $count = 0;
        foreach ($this->incidents->findBy(['significant' => true]) as $incident) {
            if ('CLOSED' === $incident->getStatus()) {
                continue;
            }
            $owner = $incident->getOwner();
            $english = 'en' === $owner->getLocale();
            foreach ($this->deadlines->steps($incident->getDetectedAt(), true, $incident->getEarlyWarningAt(), $incident->getNotifiedAt(), $incident->getFinalReportAt(), $now) as $step) {
                if (!in_array($step['status'], ['DUE_SOON', 'OVERDUE'], true)) {
                    continue;
                }
                $label = ['EARLY_WARNING' => $english ? 'early warning (24 h)' : 'alerte précoce (24 h)', 'NOTIFICATION' => $english ? 'notification (72 h)' : 'notification (72 h)', 'FINAL_REPORT' => $english ? 'final report (1 month)' : 'rapport final (1 mois)'][$step['key']];
                $overdue = 'OVERDUE' === $step['status'];
                $title = $english
                    ? sprintf('NIS2: %s %s', $label, $overdue ? 'overdue' : 'due soon')
                    : sprintf('NIS2 : %s %s', $label, $overdue ? 'en retard' : 'bientôt due');
                $message = $english
                    ? sprintf('Incident "%s": deadline %s.', $incident->getTitle(), $step['dueAt'])
                    : sprintf('Incident « %s » : échéance %s.', $incident->getTitle(), $step['dueAt']);
                $this->notifications->notify($owner, 'NIS2_DEADLINE', $title, $message, '/nis2', implode('|', ['nis2', $incident->getId(), $step['key'], $step['status'], $now->format('Y-m-d')]));
                ++$count;
            }
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('%d alerte(s) NIS2 émise(s).', $count));

        return Command::SUCCESS;
    }
}

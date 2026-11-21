<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\NotificationService;
use App\Domain\Regulatory\RegulatoryDeadlines;
use App\Entity\RegulatoryIncident;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:regulatory:remind-deadlines', description: 'Alerte les responsables de dossiers DORA, CRA et AI Act dont une échéance de notification approche ou est dépassée.')]
final class RemindRegulatoryDeadlinesCommand extends Command
{
    private const REGIME_LABELS = [
        RegulatoryDeadlines::DORA => ['DORA', 'DORA'],
        RegulatoryDeadlines::CRA_VULNERABILITY => ['CRA (vulnérabilité)', 'CRA (vulnerability)'],
        RegulatoryDeadlines::CRA_INCIDENT => ['CRA (incident)', 'CRA (incident)'],
        RegulatoryDeadlines::AI_ACT => ['AI Act', 'AI Act'],
    ];
    private const STEP_LABELS = [
        'INITIAL' => ['notification initiale', 'initial notification'],
        'INTERMEDIATE' => ['rapport intermédiaire', 'intermediate report'],
        'FINAL' => ['rapport final', 'final report'],
        'EARLY_WARNING' => ['alerte précoce', 'early warning'],
        'NOTIFICATION' => ['notification', 'notification'],
        'REPORT' => ['signalement', 'report'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RegulatoryDeadlines $deadlines,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $count = 0;
        foreach ($this->entityManager->getRepository(RegulatoryIncident::class)->findBy(['status' => 'OPEN', 'applicable' => true]) as $incident) {
            $owner = $incident->getOwner();
            $english = 'en' === $owner->getLocale();
            $steps = $this->deadlines->steps($incident->getRegime(), $incident->getAwareAt(), true, $incident->deadlineFacts(), $incident->completedDates(), $now);
            foreach ($steps as $step) {
                if (!in_array($step['status'], ['DUE_SOON', 'OVERDUE'], true)) {
                    continue;
                }
                $regime = self::REGIME_LABELS[$incident->getRegime()][$english ? 1 : 0];
                $label = self::STEP_LABELS[$step['key']][$english ? 1 : 0];
                $overdue = 'OVERDUE' === $step['status'];
                $title = $english
                    ? sprintf('%s: %s %s', $regime, $label, $overdue ? 'overdue' : 'due soon')
                    : sprintf('%s : %s %s', $regime, $label, $overdue ? 'en retard' : 'bientôt due');
                $message = $english
                    ? sprintf('Case "%s": deadline %s%s.', $incident->getTitle(), $step['dueAt'], $step['provisional'] ? ' (estimated)' : '')
                    : sprintf('Dossier « %s » : échéance %s%s.', $incident->getTitle(), $step['dueAt'], $step['provisional'] ? ' (estimée)' : '');
                $this->notifications->notify($owner, 'REGULATORY_DEADLINE', $title, $message, '/regulatory-notifications', implode('|', ['regulatory', $incident->getId(), $step['key'], $step['status'], $now->format('Y-m-d')]));
                ++$count;
            }
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('%d alerte(s) réglementaire(s) émise(s).', $count));

        return Command::SUCCESS;
    }
}

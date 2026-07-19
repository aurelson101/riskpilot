<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\NotificationService;
use App\Repository\RiskAcceptanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:risk:expire-acceptances', description: 'Expire les acceptations de risques échues et relance leur revue')]
final class ExpireRiskAcceptancesCommand extends Command
{
    public function __construct(private readonly RiskAcceptanceRepository $acceptances, private readonly NotificationService $notifications, private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expired = $this->acceptances->findExpiredApprovals();
        foreach ($expired as $acceptance) {
            $acceptance->expire();
            $risk = $acceptance->getRisk();
            $this->notifications->notify($risk->getRiskOwner(), 'RISK_ACCEPTANCE_EXPIRED', 'Acceptation de risque expirée', sprintf('Le risque « %s » doit être réévalué.', $risk->getTitle()), '/risks');
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('%d acceptation(s) expirée(s).', count($expired)));

        return Command::SUCCESS;
    }
}

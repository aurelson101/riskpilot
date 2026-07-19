<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\NotificationService;
use App\Repository\RiskReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:risk:remind-reviews', description: 'Relance les revues de risques proches de leur échéance')]
final class RemindRiskReviewsCommand extends Command
{
    public function __construct(private readonly RiskReviewRepository $reviews, private readonly NotificationService $notifications, private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reviews = $this->reviews->findDueForReminder();
        foreach ($reviews as $review) {
            $campaign = $review->getCampaign();
            $this->notifications->notify($review->getReviewer(), 'RISK_REVIEW_REMINDER', 'Revue de risque à finaliser', sprintf('« %s » doit être revu avant le %s dans la campagne « %s ».', $review->getRisk()->getTitle(), $campaign->getDueAt()->format('d/m/Y'), $campaign->getTitle()), '/risks');
            $review->markReminded();
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('%d revue(s) relancée(s).', count($reviews)));

        return Command::SUCCESS;
    }
}

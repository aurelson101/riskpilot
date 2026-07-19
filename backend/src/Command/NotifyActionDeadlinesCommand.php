<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\NotificationService;
use App\Entity\ActionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:actions:notify-deadlines', description: 'Crée les notifications pour les actions proches ou en retard')]
final class NotifyActionDeadlinesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly NotificationService $notifications)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $today = new \DateTimeImmutable('today');
        $soon = $today->modify('+7 days');
        $count = 0;
        /** @var list<ActionPlan> $actions */
        $actions = $this->entityManager->getRepository(ActionPlan::class)->createQueryBuilder('a')->andWhere('a.status NOT IN (:closed)')->andWhere('a.dueDate <= :soon')->setParameter('closed', ['COMPLETED', 'CANCELLED'])->setParameter('soon', $soon)->getQuery()->getResult();
        foreach ($actions as $action) {
            $overdue = $action->getDueDate() < $today;
            $this->notifications->notify($action->getOwner(), $overdue ? 'ACTION_OVERDUE' : 'ACTION_DUE_SOON', $overdue ? 'Action en retard' : 'Échéance proche', sprintf('L’action « %s » est attendue pour le %s.', $action->getTitle(), $action->getDueDate()->format('d/m/Y')), '/actions');
            ++$count;
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('%d notification(s) créée(s).', $count));

        return Command::SUCCESS;
    }
}

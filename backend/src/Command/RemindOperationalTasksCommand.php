<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\NotificationService;
use App\Repository\OperationalRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:operations:remind', description: 'Send idempotent reminders for due operational tasks')]
final class RemindOperationalTasksCommand extends Command
{
    public function __construct(private readonly OperationalRecordRepository $records, private readonly NotificationService $notifications, private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        foreach ($this->records->findDueForReminder() as $record) {
            $owner = $record->getOwner();
            if (null === $owner) {
                continue;
            }
            $this->notifications->notify($owner, 'OPERATIONAL_REMINDER', 'Échéance à traiter', sprintf('« %s » arrive à échéance le %s.', $record->getTitle(), $record->getDueAt()?->format('d/m/Y')), '/operations');
            $record->markReminded();
            ++$count;
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('%d reminder(s) queued.', $count));

        return Command::SUCCESS;
    }
}

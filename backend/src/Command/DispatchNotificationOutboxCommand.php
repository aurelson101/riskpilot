<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\DispatchNotificationOutbox;
use App\Repository\NotificationOutboxRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:notifications:dispatch-outbox', description: 'Dispatch pending transactional notification outbox entries')]
final class DispatchNotificationOutboxCommand extends Command
{
    public function __construct(private readonly NotificationOutboxRepository $outbox, private readonly MessageBusInterface $bus) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The repository claims rows atomically with FOR UPDATE SKIP LOCKED so
        // parallel scheduler instances cannot publish the same message.
        $ids = $this->outbox->claimDispatchableIds();
        foreach ($ids as $id) {
            $this->bus->dispatch(new DispatchNotificationOutbox($id));
        }

        $output->writeln(sprintf('%d outbox message(s) dispatched.', count($ids)));

        return Command::SUCCESS;
    }
}

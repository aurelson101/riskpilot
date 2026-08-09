<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Compliance\StarterFrameworkCatalog;
use App\Entity\Framework;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:compliance:install-starter-packs', description: 'Installe les packs RGPD, NIS2, ISO 27001 et EBIOS RM sans reproduire de contenu protégé.')]
final class InstallComplianceStarterPacksCommand extends Command
{
    public function __construct(private readonly StarterFrameworkCatalog $catalog, private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $installed = 0;
        foreach ($this->catalog->keys() as $key) {
            $definition = $this->catalog->definition($key);
            if ($this->entityManager->getRepository(Framework::class)->findOneBy(['name' => $definition['name'], 'version' => $definition['version']]) instanceof Framework) {
                $output->writeln(sprintf('<comment>%s %s déjà installé.</comment>', $definition['name'], $definition['version']));
                continue;
            }
            [$framework, $requirements] = $this->catalog->instantiate($key);
            $this->entityManager->persist($framework);
            foreach ($requirements as $requirement) {
                $this->entityManager->persist($requirement);
            }
            ++$installed;
            $output->writeln(sprintf('<info>%s %s installé (%d exigences).</info>', $definition['name'], $definition['version'], count($requirements)));
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('<info>%d pack(s) installé(s).</info>', $installed));

        return Command::SUCCESS;
    }
}

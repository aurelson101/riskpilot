<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create-admin', description: 'Crée le premier administrateur RiskPilot.')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('organization', InputArgument::REQUIRED, 'Nom de l’organisation')
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse email')
            ->addArgument('password', InputArgument::REQUIRED, 'Mot de passe (12 caractères minimum)')
            ->addArgument('first-name', InputArgument::OPTIONAL, 'Prénom', 'Admin')
            ->addArgument('last-name', InputArgument::OPTIONAL, 'Nom', 'RiskPilot');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');
        if (mb_strlen($password) < 12) {
            $output->writeln('<error>Le mot de passe doit contenir au moins 12 caractères.</error>');

            return Command::INVALID;
        }
        if (null !== $this->users->findOneBy(['email' => mb_strtolower($email)])) {
            $output->writeln('<error>Cette adresse email existe déjà.</error>');

            return Command::FAILURE;
        }

        $organizationName = (string) $input->getArgument('organization');
        $organization = $this->organizations->findOneBy(['name' => $organizationName]) ?? new Organization($organizationName);
        $user = new User(
            $email,
            (string) $input->getArgument('first-name'),
            (string) $input->getArgument('last-name'),
            $organization,
            [User::ROLE_SUPER_ADMIN],
        );
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($organization);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $output->writeln(sprintf('<info>Administrateur %s créé.</info>', $email));

        return Command::SUCCESS;
    }
}

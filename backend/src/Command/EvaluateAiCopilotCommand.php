<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\AiCopilotClient;
use App\Application\AiCopilotRuntime;
use App\Application\AiEvaluation;
use App\Entity\AiSettings;
use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ai:evaluate', description: 'Mesure la qualité du copilote IA en français et en anglais sur un jeu de questions fixe (consomme des appels du fournisseur IA).')]
final class EvaluateAiCopilotCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AiCopilotClient $client,
        private readonly AiCopilotRuntime $runtime,
        private readonly AiEvaluation $evaluation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('organization-id', InputArgument::REQUIRED, 'Organisation dont la configuration IA est utilisée.')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Confirme l’envoi de 18 questions de test au fournisseur IA configuré.')
            ->addOption('min-score', null, InputOption::VALUE_REQUIRED, 'Note minimale (0 à 1) sous laquelle la commande échoue.', '0.7')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Affiche le rapport au format JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = $this->entityManager->getRepository(Organization::class)->find((int) $input->getArgument('organization-id'));
        $settings = $organization instanceof Organization ? $this->entityManager->getRepository(AiSettings::class)->findOneBy(['organization' => $organization]) : null;
        if (!$settings instanceof AiSettings || !$this->runtime->isUsable($settings)) {
            $output->writeln('<error>Le copilote IA de cette organisation est absent, désactivé ou sans clé validée.</error>');

            return Command::FAILURE;
        }
        $minimum = (float) $input->getOption('min-score');
        if ($minimum < 0.0 || $minimum > 1.0) {
            $output->writeln('<error>La note minimale doit être comprise entre 0 et 1.</error>');

            return Command::INVALID;
        }
        if (!$input->getOption('yes')) {
            $output->writeln(sprintf('<comment>Cette évaluation envoie %d questions de test à %s. Relancez avec --yes pour confirmer.</comment>', count($this->evaluation->cases()), $settings->getProvider()));

            return Command::INVALID;
        }
        $report = $this->evaluation->run(fn (string $question, string $locale): string => $this->client->askGlobal($settings, $question, [], $locale, hash('sha256', 'riskpilot-evaluation')));
        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($report['results'] as $result) {
                $output->writeln(sprintf('%-26s %.2f  langue=%s contenu=%.2f garde-fous=%s%s', $result['id'], $result['score'], $result['language'] ? 'ok' : 'KO', $result['content'], $result['safe'] ? 'ok' : 'KO', null === $result['error'] ? '' : ' ('.$result['error'].')'));
            }
            $output->writeln(sprintf('Note globale : %.2f ; FR %.2f ; EN %.2f', $report['score'], $report['byLocale']['fr'] ?? 0.0, $report['byLocale']['en'] ?? 0.0));
        }

        return $report['score'] >= $minimum ? Command::SUCCESS : Command::FAILURE;
    }
}

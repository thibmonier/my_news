<?php

declare(strict_types=1);

namespace App\Presentation\Command;

use App\Application\Brief\GenerateDailyBrief\GenerateDailyBriefMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Commande de secours — Déclenche manuellement la génération du Daily Brief.
 *
 * Dispatche GenerateDailyBriefMessage dans la queue Messenger `async`.
 * Le même GenerateDailyBriefHandler que le batch automatique est utilisé.
 *
 * Usage :
 *   bin/console briefly:generate-daily-brief
 *   bin/console briefly:generate-daily-brief --date=2026-07-28
 *
 * Options :
 *   --date=YYYY-MM-DD  Date cible (défaut : date du jour en UTC)
 *
 * Sécurité :
 *   - Commande console uniquement (accès SSH/container, pas d'exposition HTTP)
 *   - Aucune donnée personnelle dans les messages ou les logs (US-003 constitution §6)
 *   - Log "brief.manual_trigger" sans identifiant utilisateur (RGPD)
 *
 * Deptrac Presentation:[Domain, Application] — dépend de GenerateDailyBriefMessage (Application).
 */
#[AsCommand(
    name: 'briefly:generate-daily-brief',
    description: 'Déclenche manuellement la génération du Daily Brief via Symfony Messenger.',
)]
final class GenerateDailyBriefCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'date',
                null,
                InputOption::VALUE_OPTIONAL,
                'Date cible au format YYYY-MM-DD (défaut : date du jour UTC)',
            );
    }

    /**
     * Dispatche GenerateDailyBriefMessage et log l'événement manuel.
     *
     * @return int Command::SUCCESS (0) si le message est dispatché sans exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Briefly AI — Génération manuelle du Daily Brief');

        // Résolution de la date cible (option --date ou date du jour UTC)
        $dateOption = $input->getOption('date');

        if (\is_string($dateOption) && '' !== $dateOption) {
            // Validation du format (la validation complète est dans le message)
            $dateTarget = $dateOption;
        } else {
            $dateTarget = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        }

        // Création et dispatch du message (validation du format dans le constructeur)
        $message = new GenerateDailyBriefMessage($dateTarget);
        $this->messageBus->dispatch($message);

        // Log structuré sans identifiant utilisateur (RGPD, constitution §6)
        $this->logger->info('brief.manual_trigger', [
            'event' => 'brief.manual_trigger',
            'operator' => 'console',
            'date' => $dateTarget,
            // Pas d'UUID utilisateur — commande console uniquement (pas d'authentification)
        ]);

        $io->success(\sprintf(
            'GenerateDailyBriefMessage dispatché pour la date %s. '
            . 'Consultez les logs pour le résultat de la génération.',
            $dateTarget,
        ));

        return Command::SUCCESS;
    }
}

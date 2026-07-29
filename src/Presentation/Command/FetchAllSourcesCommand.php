<?php

declare(strict_types=1);

namespace App\Presentation\Command;

use App\Application\Feed\FetchAllSourcesTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande console — Déclenche manuellement le pipeline d'ingestion RSS.
 *
 * Délègue à FetchAllSourcesTask (Application layer) pour rester dans le rôle
 * Presentation : pas de logique métier ici.
 *
 * Usage : `bin/console briefly:fetch-all-sources`
 * Scheduler : FetchAllSourcesTask est aussi déclenché automatiquement toutes les 15 min
 *             via #[AsPeriodicTask] lorsqu'un worker `messenger:consume scheduler_default` tourne.
 *
 * Couche Presentation — dépend de Application.
 * Deptrac : Presentation:[Domain, Application].
 */
#[AsCommand(
    name: 'briefly:fetch-all-sources',
    description: 'Publie un FetchSourceMessage par source active dans la queue Messenger async.',
)]
final class FetchAllSourcesCommand extends Command
{
    public function __construct(
        private readonly FetchAllSourcesTask $task,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Briefly AI — Ingestion RSS (fetch-all-sources)');

        ($this->task)();

        $io->success('Messages FetchSourceMessage publiés dans la queue async.');

        return Command::SUCCESS;
    }
}

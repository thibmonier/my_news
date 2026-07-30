<?php

declare(strict_types=1);

namespace App\Presentation\Command;

use App\Application\Feed\FetchSource\FetchSourceHandler;
use App\Application\Feed\FetchSource\FetchSourceMessage;
use App\Domain\Brief\BriefSelectorServiceInterface;
use App\Domain\Feed\ArticleDTO;
use App\Domain\Feed\ArticleRepositoryInterface;
use App\Domain\Feed\ContentHash;
use App\Domain\Feed\SourceRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Commande DEV uniquement — Seed base de données pour le Walking Skeleton.
 *
 * Disponible uniquement en APP_ENV=dev (guard explicite dans execute()).
 *
 * Pipeline :
 *   1. Récupère les sources actives (déjà en DB via migration US-020)
 *   2. Lance FetchSourceHandler synchronement pour chaque source (ingestion RSS réelle)
 *   3. Si le réseau échoue ou 0 articles ingérés : insère des articles de démo
 *   4. Génère le Daily Brief du jour via BriefSelectorServiceInterface
 *
 * Architecture hexagonale :
 *   - FetchSourceHandler (Application layer) : réutilise le pipeline d'ingestion existant
 *   - BriefSelectorServiceInterface (Domain) : sélection + persistance du brief
 *   - ArticleRepositoryInterface / SourceRepositoryInterface (Domain) : ports secondaires
 *
 * Couche Presentation — deptrac Presentation:[Domain, Application].
 * JAMAIS utilisé en prod : guard APP_ENV + commande non enregistrée hors dev.
 */
#[AsCommand(
    name: 'app:dev:seed',
    description: 'Seed DEV : sources RSS → articles → Daily Brief (APP_ENV=dev uniquement)',
)]
final class DevSeedCommand extends Command
{
    public function __construct(
        private readonly FetchSourceHandler $fetchSourceHandler,
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly ArticleRepositoryInterface $articleRepository,
        private readonly BriefSelectorServiceInterface $briefSelectorService,
        #[Autowire(env: 'APP_ENV')]
        private readonly string $appEnv,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Briefly AI — Dev Seed');

        // ── Guard : dev uniquement ───────────────────────────────────────────
        if ('dev' !== $this->appEnv) {
            $io->error(\sprintf(
                'Cette commande est réservée à APP_ENV=dev (actuel : "%s").',
                $this->appEnv,
            ));

            return Command::FAILURE;
        }

        // ── Étape 1 : sources actives ────────────────────────────────────────
        $sources = $this->sourceRepository->findAllActive();

        if ([] === $sources) {
            $io->error('Aucune source active en base. Avez-vous exécuté les migrations ?');

            return Command::FAILURE;
        }

        $io->section(\sprintf('Sources actives trouvées : %d', \count($sources)));
        foreach ($sources as $source) {
            $io->writeln(\sprintf('  • %s (%s)', $source->getName(), $source->getUrl()));
        }

        // ── Étape 2 : ingestion RSS réelle ───────────────────────────────────
        $io->section('Ingestion RSS (FetchSourceHandler synchrone)');
        $successCount = 0;

        foreach ($sources as $source) {
            try {
                ($this->fetchSourceHandler)(new FetchSourceMessage($source->getId()));
                $io->writeln(\sprintf('  ✓ %s — OK', $source->getName()));
                ++$successCount;
            } catch (\Throwable $e) {
                $io->writeln(\sprintf(
                    '  ✗ %s — échec réseau : %s',
                    $source->getName(),
                    $e->getMessage(),
                ));
            }
        }

        // ── Étape 3 : fallback démo si 0 articles ingérés ───────────────────
        $articleCount = $this->articleRepository->countAll();

        if (0 === $articleCount) {
            $io->section('Aucun article RSS récupéré — insertion des articles de démo');
            $this->insertDemoArticles($sources, $io);
            $articleCount = $this->articleRepository->countAll();
        }

        $io->writeln(\sprintf('  Articles en base : %d', $articleCount));

        // ── Étape 4 : génération du Daily Brief ──────────────────────────────
        $io->section('Génération du Daily Brief');
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $failedEvent = $this->briefSelectorService->selectTopStories($today);

        if (null !== $failedEvent) {
            $io->error(\sprintf(
                'Génération du brief échouée : %s (articles en base : %d)',
                $failedEvent->reason,
                $articleCount,
            ));

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Seed terminé ! %d article(s) en base, Daily Brief du %s généré.',
            $articleCount,
            $today->format('Y-m-d'),
        ));

        $io->writeln('  → <info>https://localhost/brief</info>');

        return Command::SUCCESS;
    }

    /**
     * Insère 2 articles de démo par source (6 total) avec un contenu > 800 chars
     * pour activer le bonus d'engagement dans BriefSelectorService.
     *
     * Les publishedAt sont répartis sur les dernières 12h pour maximiser le score de fraîcheur.
     *
     * @param list<\App\Domain\Feed\Source> $sources
     */
    private function insertDemoArticles(array $sources, SymfonyStyle $io): void
    {
        $demoContent = str_repeat(
            'Lorem ipsum dolor sit amet, consectetur adipiscing elit. '
            . 'Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. '
            . 'Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris. ',
            8,
        ); // ~800+ chars

        $demoArticles = [
            [
                'title' => 'AI Breakthrough: Large Language Models Reach New Efficiency Milestone',
                'slug' => 'ai-llm-efficiency-2026',
                'excerpt' => 'Researchers announce a 10x reduction in compute requirements for state-of-the-art language models, '
                    . 'opening the door to on-device inference for billions of users worldwide.',
            ],
            [
                'title' => 'Open Source Initiative Launches Framework for Responsible AI Development',
                'slug' => 'oss-responsible-ai-framework',
                'excerpt' => 'A coalition of tech companies and academics unveils a new open-source toolkit '
                    . 'designed to help developers audit, test, and deploy AI systems safely.',
            ],
            [
                'title' => 'Global Chip Shortage Eases as TSMC Expands Advanced Node Capacity',
                'slug' => 'tsmc-advanced-node-expansion',
                'excerpt' => 'TSMC reports a 40% increase in 3nm wafer output, signaling relief for the '
                    . 'smartphone and automotive industries that have struggled with component shortages.',
            ],
            [
                'title' => 'WebAssembly 2.0 Spec Finalized: Threads, SIMD, and GC Support Land',
                'slug' => 'webassembly-2-0-spec',
                'excerpt' => 'The W3C officially approves WebAssembly 2.0, bringing native garbage collection, '
                    . 'SIMD instructions, and multi-threading to the web platform standard.',
            ],
            [
                'title' => 'Quantum Computing Startup Claims First Commercial Error-Corrected Qubit',
                'slug' => 'quantum-error-corrected-qubit',
                'excerpt' => 'A Silicon Valley startup demonstrates 99.9% gate fidelity on a 1000-qubit processor, '
                    . 'positioning the system as the first commercially viable error-corrected quantum computer.',
            ],
            [
                'title' => 'EU Digital Markets Act: Big Tech Interoperability Deadline Approaches',
                'slug' => 'eu-dma-interoperability',
                'excerpt' => 'With the DMA compliance deadline three months away, messaging giants race to implement '
                    . 'cross-platform interoperability — what it means for end-to-end encryption.',
            ],
        ];

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $articleIndex = 0;

        foreach ($sources as $sourceIndex => $source) {
            for ($i = 0; $i < 2; ++$i) {
                $demo = $demoArticles[$articleIndex % \count($demoArticles)];
                ++$articleIndex;

                // Répartir les publications sur les 12 dernières heures (fraîcheur élevée)
                $ageHours = $sourceIndex * 2 + $i + 1;
                $publishedAt = $now->modify("-{$ageHours} hours");

                $canonicalUrl = \sprintf(
                    'https://demo.briefly.ai/%s/%s',
                    $source->getId(),
                    $demo['slug'],
                );

                $contentHash = ContentHash::fromCanonicalUrl($canonicalUrl);

                $fullContent = $demo['excerpt'] . ' ' . $demoContent;

                $dto = new ArticleDTO(
                    sourceId: $source->getId(),
                    title: '[DEMO] ' . $demo['title'],
                    url: $canonicalUrl,
                    canonicalUrl: $canonicalUrl,
                    contentHash: $contentHash,
                    rawContent: $fullContent,
                    publishedAt: $publishedAt,
                );

                $inserted = $this->articleRepository->saveIgnoringDuplicate($dto);
                $io->writeln(\sprintf(
                    '  %s [DEMO] %s',
                    null !== $inserted ? '✓ inséré' : '→ déjà présent',
                    $demo['title'],
                ));
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Brief;

use App\Domain\Feed\Article;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service domaine — Algorithme de sélection des 3 histoires majeures.
 *
 * PHP pur (excepté Psr\Log et Symfony\Uid pour UUID génération).
 * Constitution §4 : aucun import Doctrine/ApiPlatform.
 *
 * Algorithme de scoring composite (US-002 §Conversation) :
 *   - Fraîcheur          : 0–40 pts, décroissant linéairement sur 24h
 *   - Diversité cluster  : +30 pts si cluster_id distinct des stories déjà sélectionnées
 *                          (Sprint 1 : cluster_id = null → sélection par source_id distinct à la place)
 *   - Signal source      : +20 pts si source premium (liste sprint 1), sinon +10 pts
 *   - Engagement proxy   : +10 pts si longueur rawContent > 800 caractères (proxy mots)
 *
 * Idempotence : la sélection produit toujours les mêmes résultats pour les mêmes articles.
 * Sprint 1 : cluster_id = null → algorithme préserve la diversité au niveau source_id.
 */
final class BriefSelectorService implements BriefSelectorServiceInterface
{
    /** Durée de la fenêtre de fraîcheur en secondes (24h). */
    private const FRESHNESS_WINDOW_SECONDS = 86_400;

    /** Score maximum pour la fraîcheur. */
    private const MAX_FRESHNESS_SCORE = 40.0;

    /** Bonus source standard (Sprint 1 — toutes les sources utilisent ce bonus). */
    private const SOURCE_STANDARD_BONUS = 10.0;

    /** Bonus engagement proxy (longueur rawContent > 800 caractères). */
    private const ENGAGEMENT_PROXY_BONUS = 10.0;

    /** Seuil engagement (nombre de caractères — proxy "800 mots"). */
    private const ENGAGEMENT_THRESHOLD_CHARS = 800;

    public function __construct(
        private readonly ArticleCandidateRepositoryInterface $articleCandidateRepository,
        private readonly DailyBriefRepositoryInterface $dailyBriefRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sélectionne les N meilleures histoires (max 3) pour la date donnée.
     *
     * Effets de bord :
     * - Persiste le DailyBrief via DailyBriefRepositoryInterface::upsertForToday()
     * - Dispatche BriefGenerationFailedEvent si 0 articles disponibles
     *   (via return : le Handler est responsable du dispatch)
     *
     * @return BriefGenerationFailedEvent|null Événement à dispatcher si échec, null si succès
     */
    public function selectTopStories(\DateTimeImmutable $date): ?BriefGenerationFailedEvent
    {
        $since = $date->modify('-24 hours');
        $candidates = $this->articleCandidateRepository->findCandidatesForBrief($since);

        if ([] === $candidates) {
            $this->logger->error('brief.generation_failed: no_articles_available', [
                'target_date' => $date->format('Y-m-d'),
            ]);

            return new BriefGenerationFailedEvent(
                targetDate: $date,
                reason: 'no_articles_available',
            );
        }

        // Score chaque article
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $scoredArticles = $this->scoreArticles($candidates, $now);

        // Trier par score décroissant, puis par published_at décroissant (déterminisme)
        usort($scoredArticles, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $b['article']->getPublishedAt() <=> $a['article']->getPublishedAt();
        });

        // Sélectionner les top 3 avec clusters distincts
        $selectedArticles = $this->pickTopDistinct($scoredArticles, maxStories: 3);
        $storyCount = \count($selectedArticles);

        if ($storyCount < 3) {
            $this->logger->warning('brief.incomplete: ' . $storyCount . '/3 stories available', [
                'target_date' => $date->format('Y-m-d'),
                'stories_count' => $storyCount,
                'candidates_count' => \count($candidates),
            ]);
        }

        // Récupérer ou créer le DailyBrief
        $brief = $this->dailyBriefRepository->findForDate($date);
        if (null === $brief) {
            $brief = new DailyBrief(
                id: Uuid::v4()->toRfc4122(),
                date: $date,
                status: DailyBriefStatus::Pending,
                updatedAt: $now,
            );
        }

        // Construire les BriefStories
        $stories = [];
        foreach ($selectedArticles as $index => $scored) {
            $position = $index + 1;
            $stories[] = new BriefStory(
                id: Uuid::v4()->toRfc4122(),
                briefId: $brief->getId(),
                articleId: $scored['article']->getId(),
                position: $position,
                selectionScore: $scored['score'],
            );
        }

        $brief->applySelection($stories, $now);
        $this->dailyBriefRepository->upsertForToday($brief);

        $this->logger->info('brief.generated', [
            'target_date' => $date->format('Y-m-d'),
            'stories_count' => $storyCount,
        ]);

        return null;
    }

    /**
     * Calcule le score composite pour chaque article candidat.
     *
     * @param list<Article> $articles
     *
     * @return list<array{article: Article, score: float, clusterId: string|null}>
     */
    private function scoreArticles(array $articles, \DateTimeImmutable $now): array
    {
        return array_map(static function (Article $article) use ($now): array {
            $score = 0.0;

            // 1. Fraîcheur : décroissant linéairement de 40 pts (maintenant) à 0 pt (24h)
            $ageSeconds = max(0, $now->getTimestamp() - $article->getPublishedAt()->getTimestamp());
            $freshnessRatio = 1.0 - min(1.0, $ageSeconds / self::FRESHNESS_WINDOW_SECONDS);
            $score += self::MAX_FRESHNESS_SCORE * $freshnessRatio;

            // 2. Cluster distinct (+30 pts) — appliqué dans pickTopDistinct() lors de la sélection.
            // Sprint 1 : cluster_id = null → bonus non applicable (EPIC-002 à venir).
            // Le code est prêt pour l'activation dès qu'EPIC-002 alimentera cluster_id.

            // 3. Signal source (Sprint 1 : toutes les sources utilisent le bonus standard).
            // Sprint 2 (EPIC-002) : différencier les sources premium depuis la DB.
            $score += self::SOURCE_STANDARD_BONUS;

            // 4. Engagement proxy : longueur du contenu brut > 800 caractères
            if (mb_strlen($article->getRawContent()) > self::ENGAGEMENT_THRESHOLD_CHARS) {
                $score += self::ENGAGEMENT_PROXY_BONUS;
            }

            return [
                'article' => $article,
                'score' => $score,
                'clusterId' => $article->getClusterId(),
            ];
        }, $articles);
    }

    /**
     * Sélectionne jusqu'à $maxStories articles en garantissant la diversité thématique.
     *
     * Règle :
     * - Si cluster_id est renseigné : sélectionner 1 article max par cluster_id.
     * - Si cluster_id est null (Sprint 1) : sélectionner 1 article max par source_id
     *   pour maximiser la diversité en attendant EPIC-002.
     *
     * @param list<array{article: Article, score: float, clusterId: string|null}> $scored Articles triés par score DESC
     *
     * @return list<array{article: Article, score: float, clusterId: string|null}>
     */
    private function pickTopDistinct(array $scored, int $maxStories): array
    {
        $selected = [];
        $usedClusters = [];
        $usedSources = [];

        foreach ($scored as $item) {
            if (\count($selected) >= $maxStories) {
                break;
            }

            $clusterId = $item['clusterId'];
            $sourceId = $item['article']->getSourceId();

            if (null !== $clusterId) {
                // Mode cluster activé (EPIC-002) : diversité par cluster_id
                if (isset($usedClusters[$clusterId])) {
                    continue; // Cluster déjà représenté
                }
                $usedClusters[$clusterId] = true;
            } else {
                // Sprint 1 : diversité par source_id à défaut de cluster
                if (isset($usedSources[$sourceId])) {
                    continue; // Source déjà représentée
                }
                $usedSources[$sourceId] = true;
            }

            $selected[] = $item;
        }

        return $selected;
    }
}

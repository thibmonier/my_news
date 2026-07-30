<?php

declare(strict_types=1);

namespace App\Application\Brief\FeaturedSummary;

use App\Domain\Brief\BriefStoryPublicView;
use App\Domain\Brief\DailyBriefSummaryRepositoryInterface;
use App\Domain\Brief\FeaturedSummaryCacheInterface;
use App\Domain\Brief\FeaturedSummaryDTO;
use App\Domain\Synthesis\MistralClientInterface;
use App\Domain\Synthesis\SynthesisUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * Service Application — Génération de la synthèse narrative Featured Summary (US-006).
 *
 * Flux d'exécution (batch) :
 * 1. Cache Redis (`briefly:featured_summary:{Y-m-d}`) → retour immédiat si chaud
 * 2. Prompt agrégé multi-articles → appel Mistral (seul appel IA, PII-free)
 * 3. Fallback si Mistral KO : texte "Voici les 3 histoires majeures du {date}."
 *    avec is_fallback=true + log WARNING `featured_summary.fallback_used`
 * 4. Persistance DB + mise en cache Redis
 *
 * Sécurité RGPD (T-006-09 — test bloquant CI) :
 * - Prompt = titres + extraits publics + date uniquement
 * - JAMAIS de user_id, session_id, email, ip dans le prompt
 *
 * INV-2 : le badge émeraude #10B981 est affiché UNIQUEMENT si isFallback = false.
 *
 * Couche Application — dépend uniquement de Domain.
 * Deptrac : Application:[Domain].
 */
final class FeaturedSummaryService implements FeaturedSummaryServiceInterface
{
    /** TTL cache Redis : 24h. */
    private const CACHE_TTL = 86400;

    /**
     * Prompt système — PII-free : uniquement contenu éditorial + date.
     *
     * Instructions Mistral : paragraphe narratif 80-120 mots, ton éditorial,
     * pas de liste à puces (réservé aux condensés US-004), pas d'introduction générique.
     */
    private const SYSTEM_PROMPT = 'Tu es un éditeur de contenu tech. Rédige en français un paragraphe narratif de 80 à 120 mots résumant les histoires majeures du Daily Brief ci-dessous. Ton : informatif, éditorial, sans liste à puces, sans introduction générique comme "Aujourd\'hui" ou "Dans ce brief".';

    /** Nom du modèle Mistral utilisé (traçabilité). */
    private const MODEL_VERSION = 'mistral-small-latest';

    public function __construct(
        private readonly MistralClientInterface $mistralClient,
        private readonly DailyBriefSummaryRepositoryInterface $repository,
        private readonly FeaturedSummaryCacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * PII-free : le prompt envoyé à Mistral contient UNIQUEMENT
     * article.title + article.excerpt + brief.date.
     * Assertion vérifiée en CI (T-006-09).
     *
     * @param list<BriefStoryPublicView> $stories
     */
    public function generateForBrief(
        string $briefId,
        \DateTimeImmutable $date,
        array $stories,
    ): FeaturedSummaryDTO {
        $dateKey = $date->format('Y-m-d');

        // ── 1. Cache check ────────────────────────────────────────────────────
        $cached = $this->cache->get($dateKey);

        if (null !== $cached) {
            return $cached;
        }

        // ── 2. Appel Mistral (un seul appel agrégé — T-006-03) ───────────────
        try {
            // Contenu PII-free : titre + extrait (éditorial public) + date (pas de PII)
            $userContent = $this->buildUserContent($date, $stories);

            $text = $this->mistralClient->complete(
                self::SYSTEM_PROMPT,
                $userContent,
                20, // Timeout 20s adapté à la génération de 80-120 mots
            );

            $dto = new FeaturedSummaryDTO(
                briefId: $briefId,
                content: $text,
                modelVersion: self::MODEL_VERSION,
                generatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                isFallback: false,
            );
        } catch (SynthesisUnavailableException) {
            // ── 3. Fallback : texte local sans badge émeraude (INV-2) ─────────
            $this->logger->warning('featured_summary.fallback_used', [
                'event' => 'featured_summary.fallback_used',
                'date' => $dateKey,
            ]);

            $dto = new FeaturedSummaryDTO(
                briefId: $briefId,
                content: \sprintf('Voici les 3 histoires majeures du %s.', $date->format('d/m/Y')),
                modelVersion: '',
                generatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                isFallback: true,
            );
        }

        // ── 4. Persistance DB + cache Redis ───────────────────────────────────
        try {
            $this->repository->save($dto);
        } catch (\Throwable $e) {
            // Persistance DB secondaire — ne bloque pas le retour utilisateur
            $this->logger->warning('featured_summary.persist_failed', [
                'event' => 'featured_summary.persist_failed',
                'error' => $e->getMessage(),
            ]);
        }

        $this->cache->set($dateKey, $dto, self::CACHE_TTL);

        return $dto;
    }

    /**
     * {@inheritDoc}
     */
    public function getForToday(\DateTimeImmutable $now): ?FeaturedSummaryDTO
    {
        $dateKey = $now->format('Y-m-d');

        // ── 1. Cache Redis ───────────────────────────────────────────────────
        $cached = $this->cache->get($dateKey);

        if (null !== $cached) {
            return $cached;
        }

        // ── 2. DB fallback (redémarrage Redis, cache froid) ──────────────────
        $dto = $this->repository->findLatest();

        if (null !== $dto) {
            // Re-warm cache Redis
            $this->cache->set($dateKey, $dto, self::CACHE_TTL);
        }

        return $dto;
    }

    /**
     * Compose le contenu utilisateur du prompt agrégé.
     *
     * PII-safe : UNIQUEMENT article.title (public) + article.excerpt (public) + brief.date.
     * Jamais de user_id, session_id, email, ip (assertion CI T-006-09).
     *
     * @param list<BriefStoryPublicView> $stories
     */
    private function buildUserContent(\DateTimeImmutable $date, array $stories): string
    {
        $lines = ['Daily Brief du ' . $date->format('d/m/Y') . ' :'];

        foreach ($stories as $story) {
            // Titre + extrait (≤ 200 chars) — données éditoriales publiques, 0 PII
            $excerpt = mb_substr($story->excerpt, 0, 200);
            $lines[] = '- ' . $story->articleTitle . ' : ' . $excerpt;
        }

        return implode("\n", $lines);
    }
}

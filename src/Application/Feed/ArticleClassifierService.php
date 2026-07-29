<?php

declare(strict_types=1);

namespace App\Application\Feed;

use App\Domain\Feed\ArticleCategory;
use Psr\Log\LoggerInterface;

/**
 * Service de classification éditoriale d'un article (US-005/T-005-03).
 *
 * Stratégie v1 : règles-métier par mots-clés.
 * La classification Mistral zéro-shot sera intégrée dans EPIC-002.
 *
 * Ordre de priorité des catégories : AI INSIGHT > GEOPOLITICS > RESEARCH >
 * SUSTAINABILITY > PRODUCTIVITY. La première correspondance de mots-clés gagne.
 *
 * Si aucun mot-clé ne correspond → catégorie par défaut PRODUCTIVITY + log INFO.
 *
 * La catégorie est destinée à être persistée sur l'article à l'ingestion.
 * Ce service est appelé à l'ingestion — PAS à l'affichage (US-005 conversation §1).
 *
 * SÉCURITÉ OWASP #9 :
 * - Seul l'article_id (UUID) est loggué — aucun PII ni contenu éditorial sensible.
 *
 * Couche Application — dépend du Domain uniquement (ArticleCategory).
 * Deptrac : Application:[Domain].
 */
final class ArticleClassifierService
{
    /**
     * Mots-clés par catégorie, ordre de priorité décroissante.
     * La première catégorie dont un mot-clé est détecté dans le texte est retenue.
     *
     * @var array<string, list<string>>
     */
    private const KEYWORD_RULES = [
        'ai_insight' => [
            'artificial intelligence', 'machine learning', 'deep learning', 'neural network',
            'large language model', 'llm', 'gpt', 'mistral', 'openai', 'anthropic',
            'chatgpt', 'claude', 'gemini', 'intelligence artificielle', 'generative ai',
            'generative', 'transformer model', 'foundation model',
        ],
        'geopolitics' => [
            'geopolitics', 'sanctions', 'nato', 'war ', 'conflict', 'diplomacy',
            'election', 'president', 'government policy', 'trade war',
            'géopolitique', 'guerre', 'élection', 'united nations', 'treaty',
            'foreign minister', 'ambassador',
        ],
        'research' => [
            'peer-reviewed', 'study finds', 'new study', 'researchers found',
            'published in', 'journal of', 'university researchers', 'scientists',
            'experimental', 'étude publiée', 'recherche publiée', 'arxiv',
            'preprint', 'laboratory study',
        ],
        'sustainability' => [
            'climate change', 'carbon emissions', 'renewable energy', 'sustainability',
            'green energy', 'net zero', 'biodiversity', 'solar power', 'wind energy',
            'co2 emissions', 'fossil fuel', 'climat', 'environnement', 'énergie renouvelable',
            'transition énergétique',
        ],
        'productivity' => [
            'productivity', 'workflow', 'developer tools', 'software development',
            'remote work', 'team management', 'automation', 'efficiency',
            'startup', 'saas', 'business software', 'project management',
        ],
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Classifie un article par son contenu textuel.
     *
     * @param string $articleId UUID de l'article (loggué sans PII — OWASP #9)
     * @param string $text Titre + contenu brut de l'article à classifier
     *
     * @return ArticleCategory Catégorie assignée (jamais null — défaut PRODUCTIVITY)
     */
    public function classify(string $articleId, string $text): ArticleCategory
    {
        $normalizedText = mb_strtolower($text);

        foreach (self::KEYWORD_RULES as $categoryValue => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalizedText, $keyword)) {
                    return ArticleCategory::from($categoryValue);
                }
            }
        }

        // Aucune règle ne correspond → fallback par défaut PRODUCTIVITY
        // Log INFO sans PII (article_id = UUID, pas de contenu personnel — OWASP #9)
        $this->logger->info('category.fallback_applied', [
            'event' => 'category.fallback_applied',
            'article_id' => $articleId,
            'category' => ArticleCategory::Productivity->value,
        ]);

        return ArticleCategory::Productivity;
    }
}

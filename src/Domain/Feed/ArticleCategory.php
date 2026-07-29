<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Catégorie éditoriale d'un article (US-005).
 *
 * 5 catégories closes — liste fermée v1.
 * Extensible v2 via EPIC-005 (back-office éditorial).
 *
 * Persistée en base comme VARCHAR(50) : 'ai_insight', 'geopolitics', etc.
 *
 * PHP pur — aucun import Symfony/Doctrine.
 * Constitution §4 : entités Domain = classes PHP pures.
 * WCAG 2.1 AA : label() fournit toujours un texte lisible — la couleur seule
 * n'est JAMAIS l'unique vecteur d'information (INV-5).
 */
enum ArticleCategory: string
{
    case AiInsight = 'ai_insight';
    case Geopolitics = 'geopolitics';
    case Productivity = 'productivity';
    case Research = 'research';
    case Sustainability = 'sustainability';

    /**
     * Libellé affiché dans le badge (WCAG 2.1 AA : couleur + texte, pas couleur seule).
     */
    public function label(): string
    {
        return match ($this) {
            self::AiInsight => 'AI INSIGHT',
            self::Geopolitics => 'GEOPOLITICS',
            self::Productivity => 'PRODUCTIVITY',
            self::Research => 'RESEARCH',
            self::Sustainability => 'SUSTAINABILITY',
        };
    }

    /**
     * Nom du token CSS de couleur du badge (ex : 'violet' → --color-badge-violet).
     *
     * JAMAIS '#10B981' / émeraude (réservé au badge IA — INV-2).
     * Couleurs définies dans design/design-tokens.md + BriefController::badgeCss().
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::AiInsight => 'violet',
            self::Geopolitics => 'red',
            self::Productivity => 'blue',
            self::Research => 'orange',
            self::Sustainability => 'green-dark',
        };
    }

    /**
     * Construit depuis une valeur persistée en base.
     *
     * @param string $value Valeur lue dans articles.category
     * @param string $articleId UUID de l'article (pour le log d'erreur sans PII)
     *
     * @throws InvalidCategoryException si la valeur n'est pas dans l'enum
     */
    public static function fromDatabaseValue(string $value, string $articleId): self
    {
        $category = self::tryFrom($value);

        if (null === $category) {
            throw new InvalidCategoryException($articleId, $value);
        }

        return $category;
    }
}

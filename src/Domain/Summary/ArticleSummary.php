<?php

declare(strict_types=1);

namespace App\Domain\Summary;

/**
 * Value Object Domaine — Condensé IA d'un article (US-004).
 *
 * PHP pur — AUCUN import Symfony/Doctrine/ApiPlatform.
 * Constitution §4 : entités Domain = classes PHP pures.
 *
 * Invariants validés dans le constructeur :
 * - NON dégradé : 3 ≤ count(keyPoints) ≤ 4, chacune ≤ 120 caractères
 * - Dégradé     : keyPoints vide, degradedContent ≤ 280 caractères
 *
 * Sécurité RGPD :
 * - Jamais de PII (email, UUID utilisateur, IP) dans les keyPoints ou degradedContent
 * - modelVersion enregistrée pour la traçabilité RGPD (qui a généré ce condensé ?)
 *
 * Couche Domain — PHP pur, aucun import framework.
 */
final readonly class ArticleSummary
{
    public const MIN_KEY_POINTS = 3;
    public const MAX_KEY_POINTS = 4;
    public const MAX_KEY_POINT_LENGTH = 120;
    public const MAX_DEGRADED_CONTENT_LENGTH = 280;

    /**
     * @param list<string> $keyPoints 3-4 puces ≤ 120 chars (vide quand isDegraded = true)
     */
    public function __construct(
        /** UUID de l'article source (non-séquentiel — RGPD-safe pour cache key). */
        public readonly string $articleId,
        /** @var list<string> */
        public readonly array $keyPoints,
        /** Version du modèle IA utilisé (ex : "mistral-small-latest") — traçabilité RGPD. */
        public readonly string $modelVersion,
        /** Horodatage UTC de génération. */
        public readonly \DateTimeImmutable $createdAt,
        /** true si tous les fournisseurs IA sont indisponibles — badge "RÉSUMÉ AUTOMATIQUE INDISPONIBLE". */
        public readonly bool $isDegraded = false,
        /** Extrait RSS brut ≤ 280 chars — utilisé uniquement quand isDegraded = true. */
        public readonly string $degradedContent = '',
    ) {
        $this->validate();
    }

    /**
     * Valide les invariants du condensé IA.
     *
     * @throws \InvalidArgumentException si les contraintes ne sont pas respectées
     */
    private function validate(): void
    {
        if ($this->isDegraded) {
            if ([] !== $this->keyPoints) {
                throw new \InvalidArgumentException('Un condensé dégradé ne doit pas contenir de puces (keyPoints doit être vide).');
            }

            $degradedLength = mb_strlen($this->degradedContent);

            if ($degradedLength > self::MAX_DEGRADED_CONTENT_LENGTH) {
                throw new \InvalidArgumentException(\sprintf('Le contenu dégradé dépasse %d caractères (%d fournis).', self::MAX_DEGRADED_CONTENT_LENGTH, $degradedLength));
            }
        } else {
            $count = \count($this->keyPoints);

            if ($count < self::MIN_KEY_POINTS || $count > self::MAX_KEY_POINTS) {
                throw new \InvalidArgumentException(\sprintf('Le condensé doit contenir entre %d et %d puces, %d fournie(s).', self::MIN_KEY_POINTS, self::MAX_KEY_POINTS, $count));
            }

            foreach ($this->keyPoints as $index => $bullet) {
                $bulletLength = mb_strlen($bullet);

                if ($bulletLength > self::MAX_KEY_POINT_LENGTH) {
                    throw new \InvalidArgumentException(\sprintf('La puce %d dépasse %d caractères (%d fournis) : "%s…"', $index + 1, self::MAX_KEY_POINT_LENGTH, $bulletLength, mb_substr($bullet, 0, 30)));
                }
            }
        }
    }
}

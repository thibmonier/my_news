<?php

declare(strict_types=1);

namespace App\Infrastructure\Feed\SimHash;

use App\Domain\Feed\SimHashServiceInterface;

/**
 * Implémentation SimHash 64 bits sur le titre d'un article.
 *
 * Algorithme en 3 étapes :
 *   1. Normalisation : mb_strtolower + tokenisation + suppression stopwords FR/EN
 *   2. Pour chaque token : FNV1a-64 → votes bit par bit (±1 par token par bit)
 *   3. Signe des votes → bit SimHash (1 si somme > 0, 0 sinon)
 *
 * Stockage : entier PHP 64 bits signé = BIGINT PostgreSQL signé.
 * Le bit 63 (signe PHP) est traité comme n'importe quel bit dans XOR/Hamming.
 *
 * Seuil configurable : 'briefly.simhash.threshold' (services.yaml, défaut 3).
 * Ce service ne connaît pas le seuil — il appartient au handler applicatif.
 *
 * @see SimHashServiceInterface
 */
final class SimHashService implements SimHashServiceInterface
{
    /**
     * Stopwords supprimés lors de la normalisation (FR + EN de base).
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'en',
        'the', 'a', 'an', 'of', 'in', 'is', 'it', 'to', 'and', 'or',
    ];

    /**
     * Calcule le SimHash 64 bits du titre normalisé.
     *
     * Retourne null si :
     * - Le titre est vide ou contient uniquement des espaces
     * - Le titre ne contient que des stopwords (liste de tokens vide après filtrage)
     *
     * Aucune RuntimeException n'est levée pour les cas normaux (UTF-8 standard, CJK, etc.).
     * Une RuntimeException peut être levée si preg_split échoue (corruption de mémoire, etc.)
     * et doit être attrapée par l'appelant (FetchSourceHandler).
     *
     * @throws \RuntimeException en cas de défaillance inattendue du traitement regexp
     */
    public function compute(string $title): ?int
    {
        $normalized = mb_strtolower(trim($title), 'UTF-8');

        if ('' === $normalized) {
            return null;
        }

        $rawTokens = preg_split('/[\s\p{P}]+/u', $normalized, -1, \PREG_SPLIT_NO_EMPTY);

        if (false === $rawTokens) {
            // Ne se produit que si le pattern PCRE est invalide (impossible ici)
            // ou si la PREG limite est dépassée — traité comme titre vide
            return null;
        }

        $tokens = array_values(array_diff($rawTokens, self::STOPWORDS));

        if ([] === $tokens) {
            return null;
        }

        // Votes par position de bit (valeurs de -N à +N)
        $bits = array_fill(0, 64, 0);

        foreach ($tokens as $token) {
            // FNV1a-64 : retourne 16 caractères hexadécimaux (64 bits)
            $hash = hash('fnv1a64', $token);

            for ($i = 0; $i < 64; ++$i) {
                // Extraction du bit $i depuis le hash hex (big-endian)
                $byteIndex = intdiv($i, 8);
                $byte = (int) hexdec(substr($hash, $byteIndex * 2, 2));
                $bitInByte = 7 - ($i % 8); // MSB du byte = bit byteIndex*8

                if ((($byte >> $bitInByte) & 1) !== 0) {
                    ++$bits[$i];
                } else {
                    --$bits[$i];
                }
            }
        }

        // Construction du SimHash : signe des votes → bit
        $result = 0;
        for ($i = 0; $i < 63; ++$i) {
            if ($bits[$i] > 0) {
                $result |= (1 << $i);
            }
        }
        // Bit 63 = signe PHP_INT_MIN (0x8000000000000000)
        if ($bits[63] > 0) {
            $result |= \PHP_INT_MIN;
        }

        return $result;
    }

    /**
     * Distance de Hamming entre deux SimHash 64 bits.
     *
     * Utilise l'algorithme de Kernighan pour compter les bits (O(k) où k = bits à 1).
     * Le bit de signe (bit 63, potentiellement négatif en PHP signé) est traité séparément
     * car le décalage arithmétique à droite en PHP propage le bit de signe.
     *
     * @param int $a Premier SimHash
     * @param int $b Second SimHash
     *
     * @return int Nombre de bits différents (0–64)
     */
    public function distance(int $a, int $b): int
    {
        $xor = $a ^ $b;
        $count = 0;

        // Bit 63 (signe) : vérifier via signe du XOR
        if ($xor < 0) {
            ++$count;
            $xor &= \PHP_INT_MAX; // Efface le bit de signe pour rendre positif
        }

        // Compte les 63 bits restants via l'algorithme de Kernighan
        while (0 !== $xor) {
            $xor &= $xor - 1; // Efface le bit le plus bas à 1
            ++$count;
        }

        return $count;
    }
}

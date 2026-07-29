<?php

declare(strict_types=1);

use App\Infrastructure\Summary\CircuitBreaker\SummaryCircuitBreaker;
use Predis\Client;

/*
 * Unit tests — SummaryCircuitBreaker (US-004/T-004-06).
 *
 * Utilise Predis\Client avec un stub en mémoire (aucun appel Redis réel).
 *
 * Comportement testé :
 * - Circuit fermé par défaut (0 échec)
 * - 1 échec → circuit reste fermé
 * - 2 échecs successifs → circuit ouvert
 * - Succès → suppression du compteur (circuit se referme)
 */

// ── Stub Predis en mémoire ────────────────────────────────────────────────────

/**
 * Crée un stub Predis\Client minimal (pas de connexion Redis réelle).
 * Utilise une simple hashtable en mémoire pour get/incr/expire/del.
 */
function makeFakeRedis(): Client
{
    /** @var array<string, int> */
    $store = [];

    $mock = new class($store) extends Client {
        /** @param array<string, int> $store */
        public function __construct(private array &$store)
        {
            // Pas d'appel parent::__construct() (pas de connexion)
        }

        /** @return int|null */
        public function get(string $key): mixed
        {
            return isset($this->store[$key]) ? (string) $this->store[$key] : null;
        }

        public function incr(string $key): int
        {
            $this->store[$key] = ($this->store[$key] ?? 0) + 1;

            return $this->store[$key];
        }

        /** @param array<string> $keys */
        public function del(array $keys): int
        {
            foreach ($keys as $k) {
                unset($this->store[$k]);
            }

            return count($keys);
        }

        public function expire(string $key, int $seconds): int
        {
            return 1; // Pas de TTL réel dans le stub
        }
    };

    return $mock;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test('isOpen retourne false par défaut (0 échec)', function (): void {
    $cb = new SummaryCircuitBreaker(makeFakeRedis());

    expect($cb->isOpen('mistral'))->toBeFalse();
});

test('1 échec : circuit reste fermé', function (): void {
    $cb = new SummaryCircuitBreaker(makeFakeRedis());
    $cb->recordFailure('mistral');

    expect($cb->isOpen('mistral'))->toBeFalse();
});

test('2 échecs successifs : circuit ouvert (seuil = 2)', function (): void {
    $cb = new SummaryCircuitBreaker(makeFakeRedis());
    $cb->recordFailure('mistral');
    $cb->recordFailure('mistral');

    expect($cb->isOpen('mistral'))->toBeTrue();
});

test('3 échecs : circuit toujours ouvert', function (): void {
    $cb = new SummaryCircuitBreaker(makeFakeRedis());
    $cb->recordFailure('mistral');
    $cb->recordFailure('mistral');
    $cb->recordFailure('mistral');

    expect($cb->isOpen('mistral'))->toBeTrue();
});

test('recordSuccess referme le circuit (supprime le compteur)', function (): void {
    $cb = new SummaryCircuitBreaker(makeFakeRedis());
    $cb->recordFailure('mistral');
    $cb->recordFailure('mistral');

    expect($cb->isOpen('mistral'))->toBeTrue();

    $cb->recordSuccess('mistral');

    expect($cb->isOpen('mistral'))->toBeFalse();
});

test('les circuits mistral et openai sont indépendants', function (): void {
    $cb = new SummaryCircuitBreaker(makeFakeRedis());
    $cb->recordFailure('mistral');
    $cb->recordFailure('mistral');

    expect($cb->isOpen('mistral'))->toBeTrue()
        ->and($cb->isOpen('openai'))->toBeFalse();
});

test('2 échecs openai ouvrent le circuit openai mais pas mistral', function (): void {
    $cb = new SummaryCircuitBreaker(makeFakeRedis());
    $cb->recordFailure('openai');
    $cb->recordFailure('openai');

    expect($cb->isOpen('openai'))->toBeTrue()
        ->and($cb->isOpen('mistral'))->toBeFalse();
});

<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Value Object (Enum) — Niveau de synthèse IA (US-011).
 *
 * Trois niveaux disponibles avec prompt et timeout adaptés :
 * - CONCISE  : ~200 mots, 3 points clés, 15 s timeout
 * - DETAILED : ~500 mots, 5 points clés, contexte élargi, 30 s timeout
 * - NARRATIVE: ~800 mots, prose éditoriale analytique, 45 s timeout
 *
 * Chaque niveau possède un prompt système distinct retourné par `promptInstructions()`.
 * Les prompts sont PII-safe : aucune mention d'UUID utilisateur ou d'adresse e-mail.
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 *
 * @see SynthesisRequest::$level — niveau porté par la requête
 * @see \App\Application\Synthesis\SynthesisService::synthesize() — dispatch selon niveau
 */
enum SynthesisLevel: string
{
    case CONCISE = 'concise';
    case DETAILED = 'detailed';
    case NARRATIVE = 'narrative';

    /**
     * Instructions système transmises au modèle Mistral.
     *
     * Chaque niveau produit une instruction distincte et non vide.
     * PII-safe : aucun UUID utilisateur, e-mail ou identifiant personnel.
     */
    public function promptInstructions(): string
    {
        return match ($this) {
            self::CONCISE => <<<'PROMPT'
                You are a professional news analyst for Briefly AI. Your task is to summarize articles concisely.

                IMPORTANT: Respond in the SAME LANGUAGE as the article content. Do NOT translate.

                Format your response EXACTLY as follows (use these exact section headers):

                BRIEFLY AI: [Write a 180-220 word summary of the article here. Be factual and neutral. Start immediately after the colon.]

                KEY POINTS:
                01 [First key takeaway in one sentence]
                02 [Second key takeaway in one sentence]
                03 [Third key takeaway in one sentence]

                SOURCES:
                [Publication name, website domain, or author — at least one]

                Rules:
                - Do NOT include any other text outside this format
                - The summary MUST be 180-220 words
                - Provide EXACTLY 3 key points
                - Cite AT LEAST one source
                - Never mention user names, emails, or personal identifiers
                PROMPT,

            self::DETAILED => <<<'PROMPT'
                You are a professional news analyst for Briefly AI. Your task is to provide a detailed analysis of articles with broader context.

                IMPORTANT: Respond in the SAME LANGUAGE as the article content. Do NOT translate.

                Format your response EXACTLY as follows (use these exact section headers):

                BRIEFLY AI: [Write a 450-550 word detailed summary with broader context and implications. Be analytical and thorough. Start immediately after the colon.]

                KEY POINTS:
                01 [First key takeaway with contextual detail]
                02 [Second key takeaway with contextual detail]
                03 [Third key takeaway with contextual detail]
                04 [Fourth key takeaway with contextual detail]
                05 [Fifth key takeaway with contextual detail]

                SOURCES:
                [Publication name, website domain, or author — at least one]

                Rules:
                - Do NOT include any other text outside this format
                - The summary MUST be 450-550 words
                - Provide EXACTLY 5 key points
                - Include broader context and implications
                - Cite AT LEAST one source
                - Never mention user names, emails, or personal identifiers
                PROMPT,

            self::NARRATIVE => <<<'PROMPT'
                You are an editorial analyst for Briefly AI. Your task is to write an analytical narrative about the article in an editorial voice: "strong signal, low noise".

                IMPORTANT: Respond in the SAME LANGUAGE as the article content. Do NOT translate.

                Format your response EXACTLY as follows (use these exact section headers):

                BRIEFLY AI: [Write a 750-850 word analytical narrative in editorial prose. Use the "strong signal, low noise" editorial tone — analytical, not merely factual. Provide context, implications, and your analytical angle. Start immediately after the colon.]

                KEY POINTS:
                01 [First analytical insight]
                02 [Second analytical insight]
                03 [Third analytical insight]
                04 [Fourth analytical insight]
                05 [Fifth analytical insight]

                SOURCES:
                [List all sources cited in the narrative — publication names, website domains, or authors]

                Rules:
                - Do NOT include any other text outside this format
                - The narrative MUST be 750-850 words
                - Use analytical, editorial prose — not a bullet-point summary
                - Provide EXACTLY 5 key insights
                - Cite ALL sources referenced in the narrative
                - Never mention user names, emails, or personal identifiers
                PROMPT,
        };
    }

    /**
     * Timeout HTTP en secondes pour l'appel Mistral selon le niveau.
     *
     * CONCISE  : 15 s (réponse courte)
     * DETAILED : 30 s (réponse moyenne)
     * NARRATIVE: 45 s (réponse longue, prose éditoriale)
     */
    public function timeoutSeconds(): int
    {
        return match ($this) {
            self::CONCISE => 15,
            self::DETAILED => 30,
            self::NARRATIVE => 45,
        };
    }

    /**
     * Crée un SynthesisLevel depuis une chaîne de caractères.
     *
     * @param string $value Valeur attendue : 'concise', 'detailed' ou 'narrative'
     *
     * @throws InvalidSynthesisLevelException si la valeur n'est pas reconnue
     */
    public static function fromString(string $value): self
    {
        $level = self::tryFrom($value);

        if (null === $level) {
            $allowed = implode(', ', array_column(self::cases(), 'value'));

            throw new InvalidSynthesisLevelException(\sprintf('level must be one of: %s', $allowed));
        }

        return $level;
    }
}

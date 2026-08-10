<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Stripe;

use App\Application\Subscription\StripeWebhookMessage;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur Webhook Stripe — réception et validation des événements Stripe.
 *
 * SÉCURITÉ :
 *  - HMAC-SHA256 vérifié via \Stripe\Webhook::constructEvent() AVANT tout traitement
 *  - Signature invalide → HTTP 400 + log WARNING (ip, reason) — JAMAIS le payload brut
 *  - HTTP 200 systématique après traitement valide (même événement inconnu ou doublon)
 *    pour éviter les retries infinis de Stripe
 *
 * La route est PUBLIC_ACCESS (security.yaml) — Stripe appelle depuis ses serveurs.
 * Exclue du CSRF : la validation se fait uniquement par HMAC-SHA256.
 */
final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly string $stripeWebhookSecret,
    ) {
    }

    /**
     * Reçoit un événement Stripe, vérifie la signature HMAC-SHA256 et dispatche le message.
     *
     * @see https://stripe.com/docs/webhooks/signatures
     */
    #[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'], stateless: true)]
    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature') ?? '';

        if ('' === $payload) {
            $this->logger->warning('Stripe webhook: empty payload', [
                'ip' => $request->getClientIp(),
                'reason' => 'empty_payload',
            ]);

            return new Response('Bad Request', Response::HTTP_BAD_REQUEST);
        }

        if ('' === $sigHeader) {
            $this->logger->warning('Stripe webhook: missing signature header', [
                'ip' => $request->getClientIp(),
                'reason' => 'missing_signature_header',
            ]);

            return new Response('Bad Request', Response::HTTP_BAD_REQUEST);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $this->stripeWebhookSecret);
        } catch (SignatureVerificationException) {
            // JAMAIS logguer le payload brut (peut contenir des données client Stripe)
            $this->logger->warning('Stripe webhook: invalid signature', [
                'ip' => $request->getClientIp(),
                'reason' => 'invalid_stripe_signature',
                'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ]);

            return new Response('Bad Request', Response::HTTP_BAD_REQUEST);
        } catch (\UnexpectedValueException) {
            $this->logger->warning('Stripe webhook: malformed payload', [
                'ip' => $request->getClientIp(),
                'reason' => 'malformed_payload',
            ]);

            return new Response('Bad Request', Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $rawData */
        $rawData = $event->data->toArray();
        /** @var array<string, mixed> $eventData */
        $eventData = isset($rawData['object']) && \is_array($rawData['object']) ? $rawData['object'] : [];

        $this->messageBus->dispatch(new StripeWebhookMessage(
            eventType: $event->type,
            eventId: $event->id,
            payload: $eventData,
        ));

        // HTTP 200 systématique — même sur événement inconnu ou doublon (évite les retries Stripe)
        return new Response('OK', Response::HTTP_OK);
    }
}

<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Feed\BulkFetch\BulkFetchMessage;
use App\Application\Feed\ValidateSource\ValidateSourceMessage;
use App\Domain\Feed\FeedType;
use App\Domain\Feed\Source;
use App\Domain\Feed\SourcePermission;
use App\Domain\Feed\SourceRepositoryInterface;
use App\Domain\Feed\SourceStatus;
use App\Presentation\Form\SourceFormData;
use App\Presentation\Form\SourceType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Contrôleur admin — CRUD des sources RSS/Atom.
 *
 * Routes : /admin/sources
 * Accès  : ROLE_ADMIN + SourceVoter (deny by default — constitution §6)
 *
 * Sécurité :
 *  - Voter SourceVoter requis sur chaque opération (CREATE, EDIT, DELETE, TOGGLE, BULK)
 *  - CSRF activé sur tous les formulaires POST (SourceType + tokens inline)
 *  - Validation SSRF via SsrfSafeUrlConstraint dans SourceFormData
 *
 * Couche Presentation — Deptrac: Presentation:[Domain, Application].
 */
#[Route('/admin/sources')]
#[IsGranted('ROLE_ADMIN')]
final class AdminSourceController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    /**
     * GET /admin/sources?q=&page=1 — Liste paginée des sources (hors soft-deleted).
     */
    #[Route('', name: 'admin_sources_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(SourcePermission::CREATE, null);

        $query = $request->query->get('q', '');
        $query = '' === trim($query) ? null : trim($query);
        $page = max(1, (int) $request->query->get('page', 1));

        $sources = $this->sourceRepository->findPaginated($page, self::PER_PAGE, $query);
        $total = $this->sourceRepository->countForListing($query);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        return $this->render('admin/sources/index.html.twig', [
            'sources' => $sources,
            'query' => $query ?? '',
            'page' => $page,
            'total' => $total,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * GET /admin/sources/new — Formulaire de création.
     * POST /admin/sources/new — Persistance + dispatch ValidateSourceMessage.
     */
    #[Route('/new', name: 'admin_sources_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted(SourcePermission::CREATE, null);

        $formData = new SourceFormData();
        $form = $this->createForm(SourceType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Contrôle d'unicité de l'URL
            if (null !== $this->sourceRepository->findByUrl($formData->url)) {
                $form->get('url')->addError(
                    new \Symfony\Component\Form\FormError('Cette URL est déjà enregistrée.'),
                );
            } else {
                $source = $this->buildSource($formData, SourceStatus::PendingValidation);
                $this->sourceRepository->save($source);

                $this->messageBus->dispatch(new ValidateSourceMessage($source->getId()));

                $this->addFlash('success', "Source « {$formData->name} » créée, validation en cours…");

                return $this->redirectToRoute('admin_sources_index');
            }
        }

        return $this->render('admin/sources/new.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * GET /admin/sources/{id}/edit — Formulaire d'édition.
     * POST /admin/sources/{id}/edit — Persistance des modifications.
     */
    #[Route('/{id}/edit', name: 'admin_sources_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $source = $this->loadSourceOrThrow($id);
        $this->denyAccessUnlessGranted(SourcePermission::EDIT, $source);

        $formData = SourceFormData::fromSource($source);
        $form = $this->createForm(SourceType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Contrôle d'unicité si l'URL a changé
            if ($formData->url !== $source->getUrl()) {
                $existing = $this->sourceRepository->findByUrl($formData->url);
                if (null !== $existing) {
                    $form->get('url')->addError(
                        new \Symfony\Component\Form\FormError('Cette URL est déjà enregistrée.'),
                    );
                    goto renderForm;
                }
            }

            $updated = $this->buildSourceFromExisting($source, $formData);
            $this->sourceRepository->save($updated);

            $this->addFlash('success', "Source « {$formData->name} » mise à jour.");

            return $this->redirectToRoute('admin_sources_index');
        }

        renderForm:

        return $this->render('admin/sources/edit.html.twig', [
            'form' => $form,
            'source' => $source,
        ]);
    }

    /**
     * POST /admin/sources/{id}/toggle — Bascule actif/inactif.
     * CSRF protégé via jeton 'source_actions'.
     */
    #[Route('/{id}/toggle', name: 'admin_sources_toggle', methods: ['POST'])]
    public function toggle(string $id, Request $request): Response
    {
        $this->validateCsrfOrThrow($request, 'source_actions');

        $source = $this->loadSourceOrThrow($id);
        $this->denyAccessUnlessGranted(SourcePermission::TOGGLE, $source);

        $newStatus = $source->isActive() ? SourceStatus::Inactive : SourceStatus::Active;
        $this->sourceRepository->updateStatus($source->getId(), $newStatus);

        $label = $newStatus->label();
        $this->addFlash('success', "Source « {$source->getName()} » passée en statut : {$label}.");

        return $this->redirectToRoute('admin_sources_index');
    }

    /**
     * POST /admin/sources/{id}/delete — Soft delete.
     * CSRF protégé via jeton 'source_actions'.
     */
    #[Route('/{id}/delete', name: 'admin_sources_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $this->validateCsrfOrThrow($request, 'source_actions');

        $source = $this->loadSourceOrThrow($id);
        $this->denyAccessUnlessGranted(SourcePermission::DELETE, $source);

        $this->sourceRepository->softDelete($source->getId());

        $this->addFlash('success', "Source « {$source->getName()} » supprimée.");

        return $this->redirectToRoute('admin_sources_index');
    }

    /**
     * POST /admin/sources/bulk-update — Mise à jour de toutes les sources actives.
     * CSRF protégé via jeton 'source_bulk'.
     */
    #[Route('/bulk-update', name: 'admin_sources_bulk_update', methods: ['POST'])]
    public function bulkUpdate(Request $request): Response
    {
        $this->validateCsrfOrThrow($request, 'source_bulk');
        $this->denyAccessUnlessGranted(SourcePermission::BULK, null);

        $activeSources = $this->sourceRepository->findAllActive();
        $count = \count($activeSources);

        $this->messageBus->dispatch(new BulkFetchMessage());

        $this->addFlash('success', "{$count} source(s) mise(s) en file de mise à jour.");

        return $this->redirectToRoute('admin_sources_index');
    }

    /**
     * Construit une Source pour création.
     * L'ID est généré ici (UUID v4).
     */
    private function buildSource(SourceFormData $data, SourceStatus $status): Source
    {
        return new Source(
            id: Uuid::v4()->toRfc4122(),
            name: $data->name,
            url: $data->url,
            feedType: FeedType::from($data->feedType),
            status: $status,
            fetchIntervalMinutes: $data->fetchIntervalMinutes,
        );
    }

    /**
     * Construit une Source mise à jour en conservant l'ID et les métadonnées existantes.
     */
    private function buildSourceFromExisting(Source $existing, SourceFormData $data): Source
    {
        return new Source(
            id: $existing->getId(),
            name: $data->name,
            url: $data->url,
            feedType: FeedType::from($data->feedType),
            status: $existing->getStatus(),
            lastFetchedAt: $existing->getLastFetchedAt(),
            lastErrorAt: $existing->getLastErrorAt(),
            etag: $existing->getEtag(),
            lastModified: $existing->getLastModified(),
            fetchIntervalMinutes: $data->fetchIntervalMinutes,
            deletedAt: $existing->getDeletedAt(),
        );
    }

    /**
     * Charge une source par ID ou lève une 404.
     */
    private function loadSourceOrThrow(string $id): Source
    {
        if ('' === $id) {
            throw $this->createNotFoundException('Source introuvable : ID vide');
        }

        $source = $this->sourceRepository->findById($id);

        if (null === $source) {
            throw $this->createNotFoundException("Source introuvable : {$id}");
        }

        return $source;
    }

    /**
     * Valide le jeton CSRF ou lève une 403.
     */
    private function validateCsrfOrThrow(Request $request, string $tokenId): void
    {
        $token = (string) $request->request->get('_token');

        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}

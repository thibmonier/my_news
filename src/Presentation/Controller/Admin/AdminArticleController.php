<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Domain\Feed\ArticleRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur admin — Liste paginée des articles ingérés.
 *
 * Route : GET /admin/articles
 * Accès : ROLE_ADMIN uniquement (constitution §6 : deny by default).
 *
 * Retourne du JSON (Twig non installé en Sprint 1).
 * La pagination est cursor-less (page/offset) : 50 articles par page.
 *
 * Couche Presentation — dépend de Domain (interface repository) et Application.
 * Deptrac : Presentation:[Domain, Application].
 */
#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AdminArticleController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ArticleRepositoryInterface $articleRepository,
    ) {
    }

    /**
     * GET /admin/articles?page=1.
     *
     * Réponse JSON :
     * {
     *   "page": 1,
     *   "perPage": 50,
     *   "total": 142,
     *   "articles": [
     *     { "id", "title", "url", "contentHash", "publishedAt", "sourceName" }
     *   ]
     * }
     */
    #[Route('/articles', name: 'admin_articles_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));

        $total = $this->articleRepository->countAll();
        $articles = $this->articleRepository->findPaginatedWithSourceName($page, self::PER_PAGE);

        $data = array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'title' => $row['title'],
                'url' => $row['url'],
                'contentHash' => $row['contentHash'],
                'publishedAt' => $row['publishedAt']->format(\DateTimeInterface::ATOM),
                'sourceName' => $row['sourceName'],
            ],
            $articles,
        );

        return new JsonResponse(
            data: [
                'page' => $page,
                'perPage' => self::PER_PAGE,
                'total' => $total,
                'articles' => $data,
            ],
            status: Response::HTTP_OK,
        );
    }
}

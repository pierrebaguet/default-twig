<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Controller;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Search\SearchGroup;
use BackOfficeDefaultTwigBundle\Service\Search\SearchResultsPresenter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Security\User\UserInterface;
use Thelia\Model\Lang;
use Twig\Environment;

final class SearchController
{
    private const RESOURCE = 'admin.search';

    private const TEMPLATE = '@BackOfficeDefaultTwig/search/index.html.twig';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly SearchResultsPresenter $presenter,
        private readonly Environment $twig,
        private readonly SecurityContext $securityContext,
        #[Autowire(service: 'limiter.admin_search')]
        private readonly RateLimiterFactoryInterface $searchLimiter,
    ) {
    }

    #[Route('/admin/search', name: 'admin.search', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $term = trim((string) $request->query->get('search_term', ''));
        $only = SearchGroup::tryFrom((string) $request->query->get('group', ''));

        if ($term !== '' && !$this->searchLimiter->create($this->limiterKey())->consume()->isAccepted()) {
            return new Response($this->twig->render(self::TEMPLATE, ['term' => $term, 'rate_limited' => true]), Response::HTTP_TOO_MANY_REQUESTS);
        }

        // The locale of the back-office interface, set from the administrator's own language;
        // a title missing there is read in the default language of the shop.
        $results = $this->presenter->buildResults(
            $term,
            $request->getLocale(),
            Lang::getDefaultLanguage()->getLocale(),
            $only,
            $request->query->getInt('page', 1),
        );

        return new Response($this->twig->render(self::TEMPLATE, $results));
    }

    private function limiterKey(): ?string
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof UserInterface ? $admin->getUsername() : null;
    }
}

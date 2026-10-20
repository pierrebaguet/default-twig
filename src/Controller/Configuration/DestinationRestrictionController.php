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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductDestinationRestrictionContext;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Shipping\DestinationRestriction\DestinationRestrictionException;
use Thelia\Domain\Shipping\DestinationRestriction\DestinationRestrictionFacade;
use Thelia\Model\CountryI18nQuery;
use Thelia\Model\CountryQuery;
use Thelia\Model\DestinationRestriction;
use Thelia\Model\DestinationRestrictionCountryQuery;
use Thelia\Model\DestinationRestrictionQuery;
use Thelia\Model\ProductDestinationRestrictionQuery;
use Thelia\Model\ProductI18nQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\StateI18nQuery;
use Thelia\Model\StateQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The groups of destinations a product can be kept from: the countries and regions they
 * list, whether the list is refused or the only one served, and the sentence the buyer is
 * given. Every rule lives in the core facade; a refusal reaches the merchant as a flash
 * message, never as a 500. On a core without the destination restrictions every route
 * answers 404.
 */
#[Route('/admin/configuration/destination-restrictions', name: 'admin.destination-restriction.')]
final class DestinationRestrictionController
{
    // The value of AdminResources::DESTINATION_RESTRICTION, written out: a constant expression
    // naming it fails at compile time on a core that predates the destination restrictions.
    private const RESOURCE = 'admin.configuration.destination-restriction';
    private const LIST_ROUTE = 'admin.destination-restriction.default';
    private const EDIT_ROUTE = 'admin.destination-restriction.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/destination-restriction/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/destination-restriction/edit.html.twig';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminFormErrorRenderer $errorRenderer,
        private readonly AdminLogger $adminLogger,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly EditLocaleResolver $editLocale,
        // Null on a core that predates the destination restrictions: the class is not there to autowire.
        private readonly ?DestinationRestrictionFacade $restrictions = null,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $request->getLocale();
        $counts = [];
        foreach (ProductDestinationRestrictionQuery::create()->find() as $link) {
            $groupId = (int) $link->getDestinationRestrictionId();
            $counts[$groupId] = ($counts[$groupId] ?? 0) + 1;
        }

        $rows = [];
        foreach (DestinationRestrictionQuery::create()->orderByPosition()->find() as $group) {
            $id = (int) $group->getId();
            $group->setLocale($locale);
            $rows[] = [
                'id' => $id,
                'code' => (string) $group->getCode(),
                'title' => (string) $group->getTitle(),
                'mode' => $this->translator->trans(DestinationRestriction::MODE_ALLOW === $group->getMode() ? 'Only listed destinations are served' : 'Listed destinations are refused'),
                'active' => (bool) $group->getActive(),
                'toggle_active_url' => $this->urls->generate('admin.destination-restriction.toggle-active', ['restriction_id' => $id]),
                'products' => $counts[$id] ?? 0,
                'position' => (int) $group->getPosition(),
                '_actions' => [
                    new RowAction(
                        kind: 'edit',
                        label: $this->translator->trans('Edit'),
                        href: $this->urls->generate(self::EDIT_ROUTE, ['restriction_id' => $id]),
                        grantedAttribute: AccessManager::UPDATE,
                        grantedSubject: self::RESOURCE,
                    ),
                    new RowAction(
                        kind: 'delete',
                        label: $this->translator->trans('Delete'),
                        modalTarget: '#destination-restriction-delete-modal',
                        grantedAttribute: AccessManager::DELETE,
                        grantedSubject: self::RESOURCE,
                        dataAttributes: ['restriction-id' => $id],
                    ),
                ],
            ];
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'locale' => $locale,
            'modes' => $this->modeChoices(),
            'update_position_url' => $this->urls->generate('admin.destination-restriction.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $locale = (string) $request->request->get('locale', '') ?: $request->getLocale();

        return $this->write(
            $request,
            AccessManager::CREATE,
            'Destination restriction creation',
            function () use ($request, $locale): string {
                $group = $this->facade()->save(
                    null,
                    (string) $request->request->get('code', ''),
                    $this->mode($request),
                    $request->request->getBoolean('active'),
                    [$locale => $this->wording($request)],
                );

                return $this->urls->generate(self::EDIT_ROUTE, ['restriction_id' => $group->getId()]);
            },
            $this->urls->generate(self::LIST_ROUTE),
        );
    }

    #[Route('/{restriction_id}', name: 'update', methods: ['GET'], requirements: ['restriction_id' => '\d+'])]
    public function updateView(int $restriction_id, Request $request): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $group = DestinationRestrictionQuery::create()->findPk($restriction_id);
        if (null === $group) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $uiLocale = $request->getLocale();
        $editLang = $this->editLocale->resolveFromRequest($request);
        $editLocale = $editLang->getLocale() ?? 'en_US';
        $group->setLocale($editLocale);

        $pickedCountryId = (int) $request->query->get('country_id', 0);
        $pickedCountry = $pickedCountryId > 0 ? CountryQuery::create()->findPk($pickedCountryId) : null;

        $regions = [];
        if (null !== $pickedCountry) {
            $stateIds = [];
            foreach (StateQuery::create()->filterByCountryId($pickedCountryId)->find() as $state) {
                $stateIds[] = (int) $state->getId();
            }
            $names = $this->stateNames($stateIds, $uiLocale);
            foreach ($stateIds as $stateId) {
                $regions[] = ['id' => $stateId, 'name' => $names[$stateId] ?? (string) $stateId];
            }
            usort($regions, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        }

        $countriesWithRegions = [];
        foreach (StateQuery::create()->select(['CountryId'])->distinct()->find()->getData() as $countryId) {
            $countriesWithRegions[(int) $countryId] = true;
        }

        $countryNames = $this->countryNames(null, $uiLocale);
        $countryChoices = [];
        $regionCountryChoices = [];
        foreach ($countryNames as $countryId => $name) {
            $countryChoices[] = ['id' => $countryId, 'name' => $name];
            if (isset($countriesWithRegions[$countryId])) {
                $regionCountryChoices[] = ['id' => $countryId, 'name' => $name];
            }
        }

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'restriction' => $group,
            'is_allow_mode' => DestinationRestriction::MODE_ALLOW === $group->getMode(),
            'modes' => $this->modeChoices(),
            'edit_language_id' => (int) $editLang->getId(),
            'edit_locale' => $editLocale,
            'destinations' => $this->destinationRows($group, $uiLocale),
            'country_choices' => $countryChoices,
            'region_country_choices' => $regionCountryChoices,
            'picked_country' => null === $pickedCountry ? null : ['id' => $pickedCountryId, 'name' => $countryNames[$pickedCountryId] ?? (string) $pickedCountryId],
            'picked_regions' => $regions,
            'products' => $this->productRows($restriction_id, $uiLocale),
        ]));
    }

    #[Route('/{restriction_id}/save', name: 'save', methods: ['POST'], requirements: ['restriction_id' => '\d+'])]
    public function save(int $restriction_id, Request $request): Response
    {
        $locale = (string) $request->request->get('locale', '') ?: $request->getLocale();

        return $this->write(
            $request,
            AccessManager::UPDATE,
            'Destination restriction update',
            fn () => $this->facade()->save(
                $restriction_id,
                (string) $request->request->get('code', ''),
                $this->mode($request),
                $request->request->getBoolean('active'),
                [$locale => $this->wording($request)],
            ),
            $this->editUrl($restriction_id, $request),
            $restriction_id,
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $restrictionId = (int) $request->request->get('restriction_id', 0);

        return $this->write(
            $request,
            AccessManager::DELETE,
            'Destination restriction deletion',
            fn () => $this->facade()->delete($restrictionId),
            $this->urls->generate(self::LIST_ROUTE),
            $restrictionId,
        );
    }

    #[Route('/{restriction_id}/toggle-active', name: 'toggle-active', methods: ['POST'], requirements: ['restriction_id' => '\d+'])]
    public function toggleActive(int $restriction_id, Request $request): Response
    {
        return $this->write(
            $request,
            AccessManager::UPDATE,
            'Destination restriction activation toggle',
            function () use ($restriction_id): void {
                $group = DestinationRestrictionQuery::create()->findPk($restriction_id)
                    ?? throw new DestinationRestrictionException($this->translator->trans('No such destination restriction.'));
                $this->facade()->save($restriction_id, (string) $group->getCode(), (int) $group->getMode(), !$group->getActive(), []);
            },
            $this->urls->generate(self::LIST_ROUTE),
            $restriction_id,
        );
    }

    #[Route('/update-position', name: 'update-position', methods: ['POST'])]
    public function updatePosition(Request $request): Response
    {
        $restrictionId = (int) ($request->query->get('restriction_id') ?? $request->request->get('restriction_id', 0));
        $mode = (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE));
        $position = (int) ($request->query->get('position') ?? $request->request->get('position', 0));

        return $this->write(
            $request,
            AccessManager::UPDATE,
            'Destination restriction reorder',
            function () use ($restrictionId, $mode, $position): void {
                $group = DestinationRestrictionQuery::create()->findPk($restrictionId)
                    ?? throw new DestinationRestrictionException($this->translator->trans('No such destination restriction.'));

                match ($mode) {
                    UpdatePositionEvent::POSITION_UP => $group->movePositionUp(),
                    UpdatePositionEvent::POSITION_DOWN => $group->movePositionDown(),
                    default => $group->changeAbsolutePosition(max(1, $position)),
                };
            },
            $this->urls->generate(self::LIST_ROUTE),
            $restrictionId,
        );
    }

    #[Route('/{restriction_id}/destination/add', name: 'destination.add', methods: ['POST'], requirements: ['restriction_id' => '\d+'])]
    public function addDestination(int $restriction_id, Request $request): Response
    {
        $stateId = (int) $request->request->get('state_id', 0);

        return $this->write(
            $request,
            AccessManager::UPDATE,
            'Destination restriction destination added',
            fn () => $this->facade()->addDestination(
                $restriction_id,
                (int) $request->request->get('country_id', 0),
                $stateId > 0 ? $stateId : null,
            ),
            $this->editUrl($restriction_id, $request),
            $restriction_id,
        );
    }

    #[Route('/{restriction_id}/destination/{destination_id}/remove', name: 'destination.remove', methods: ['POST'], requirements: ['restriction_id' => '\d+', 'destination_id' => '\d+'])]
    public function removeDestination(int $restriction_id, int $destination_id, Request $request): Response
    {
        return $this->write(
            $request,
            AccessManager::UPDATE,
            'Destination restriction destination removed',
            fn () => $this->facade()->removeDestination($restriction_id, $destination_id),
            $this->editUrl($restriction_id, $request),
            $restriction_id,
        );
    }

    #[Route('/{restriction_id}/product/{product_id}/remove', name: 'product.remove', methods: ['POST'], requirements: ['restriction_id' => '\d+', 'product_id' => '\d+'])]
    public function removeProduct(int $restriction_id, int $product_id, Request $request): Response
    {
        return $this->write(
            $request,
            AccessManager::UPDATE,
            'Destination restriction product removed',
            fn () => $this->facade()->detachProduct($restriction_id, $product_id),
            $this->editUrl($restriction_id, $request),
            $restriction_id,
        );
    }

    /**
     * The one road every write takes: permission, token read from the body, the write
     * itself, the log, and the way back to the screen. The write may return the address
     * to go to instead; a refusal comes back as a flash message.
     *
     * @param callable(): mixed $write
     */
    private function write(Request $request, string $access, string $actionLabel, callable $write, string $backTo, ?int $resourceId = null): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], $access)) {
            return $denied;
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
            $result = $write();
            $this->adminLogger->log(self::RESOURCE, $access, $actionLabel, $resourceId);

            if (\is_string($result)) {
                $backTo = $result;
            }

            $session = $request->getSession();
            if ($session instanceof Session) {
                $session->getFlashBag()->add('success', $this->translator->trans('Destination restrictions saved.'));
            }
        } catch (\Throwable $exception) {
            // The facade writes its refusals for the merchant; anything else is a fault of
            // the shop, whose wording goes to the log and not to the screen.
            $shown = $exception instanceof \InvalidArgumentException
                ? $exception->getMessage()
                : $this->translator->trans('The destination restrictions could not be saved. Please try again.');

            $this->errorRenderer->setup($this->translator->trans($actionLabel), $shown, null, $exception);
        }

        return new RedirectResponse($backTo);
    }

    /**
     * A core that predates the destination restrictions has no screen to show.
     */
    private function guard(): void
    {
        if (!ProductDestinationRestrictionContext::coreHasDestinationRestrictions()) {
            throw new NotFoundHttpException();
        }
    }

    private function facade(): DestinationRestrictionFacade
    {
        return $this->restrictions ?? throw new NotFoundHttpException();
    }

    private function editUrl(int $restrictionId, Request $request): string
    {
        $parameters = ['restriction_id' => $restrictionId];
        $editLanguageId = (int) $request->request->get('edit_language_id', 0);

        if ($editLanguageId > 0) {
            $parameters['edit_language_id'] = $editLanguageId;
        }

        return $this->urls->generate(self::EDIT_ROUTE, $parameters);
    }

    private function mode(Request $request): int
    {
        return (int) $request->request->get('mode', DestinationRestriction::MODE_DENY);
    }

    /**
     * @return array{title: string, reason: string}
     */
    private function wording(Request $request): array
    {
        return [
            'title' => (string) $request->request->get('title', ''),
            'reason' => (string) $request->request->get('reason', ''),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function modeChoices(): array
    {
        return [
            DestinationRestriction::MODE_DENY => $this->translator->trans('Listed destinations are refused'),
            DestinationRestriction::MODE_ALLOW => $this->translator->trans('Only listed destinations are served'),
        ];
    }

    /**
     * @return list<array{id: int, country: string, region: ?string}>
     */
    private function destinationRows(DestinationRestriction $group, string $locale): array
    {
        $links = DestinationRestrictionCountryQuery::create()->filterByDestinationRestrictionId($group->getId())->orderById()->find();
        $countryIds = [];
        $stateIds = [];
        foreach ($links as $link) {
            $countryIds[] = (int) $link->getCountryId();
            if (null !== $link->getStateId()) {
                $stateIds[] = (int) $link->getStateId();
            }
        }

        $countries = $this->countryNames($countryIds, $locale);
        $states = $this->stateNames($stateIds, $locale);
        $rows = [];
        foreach ($links as $link) {
            $stateId = $link->getStateId();
            $rows[] = [
                'id' => (int) $link->getId(),
                'country' => $countries[(int) $link->getCountryId()] ?? (string) $link->getCountryId(),
                'region' => null === $stateId ? null : ($states[(int) $stateId] ?? (string) $stateId),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, title: string, ref: string, url: string}>
     */
    private function productRows(int $groupId, string $locale): array
    {
        $productIds = [];
        foreach (ProductDestinationRestrictionQuery::create()->filterByDestinationRestrictionId($groupId)->find() as $link) {
            $productIds[] = (int) $link->getProductId();
        }

        if ([] === $productIds) {
            return [];
        }

        $titles = [];
        foreach (ProductI18nQuery::create()->filterById($productIds)->filterByLocale($locale)->find() as $i18n) {
            $titles[(int) $i18n->getId()] = (string) $i18n->getTitle();
        }

        $rows = [];
        foreach (ProductQuery::create()->filterById($productIds)->orderByRef()->find() as $product) {
            $id = (int) $product->getId();
            $rows[] = [
                'id' => $id,
                'title' => '' !== ($titles[$id] ?? '') ? $titles[$id] : (string) $product->getRef(),
                'ref' => (string) $product->getRef(),
                'url' => $this->urls->generate('admin.products.update', ['product_id' => $id]),
            ];
        }

        return $rows;
    }

    /**
     * @param list<int>|null $ids every country when null
     *
     * @return array<int, string> id => name, by name
     */
    private function countryNames(?array $ids, string $locale): array
    {
        $query = CountryI18nQuery::create()->filterByLocale($locale);
        if (null !== $ids) {
            $query->filterById($ids);
        }

        $names = [];
        foreach ($query->find() as $i18n) {
            $names[(int) $i18n->getId()] = (string) $i18n->getTitle();
        }

        // A country without a title in this language still has to be pickable.
        $missing = CountryQuery::create();
        if (null !== $ids) {
            $missing->filterById($ids);
        }
        foreach ($missing->find() as $country) {
            $names[(int) $country->getId()] ??= (string) ($country->getIsoalpha2() ?? $country->getId());
        }

        asort($names, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $names;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function stateNames(array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $names = [];
        foreach (StateI18nQuery::create()->filterById($ids)->filterByLocale($locale)->find() as $i18n) {
            $names[(int) $i18n->getId()] = (string) $i18n->getTitle();
        }

        return $names;
    }
}

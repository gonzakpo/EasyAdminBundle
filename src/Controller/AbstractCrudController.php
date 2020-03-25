<?php

namespace EasyCorp\Bundle\EasyAdminBundle\Controller;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Entity;
use EasyCorp\Bundle\EasyAdminBundle\Config\Fields;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Controller\CrudControllerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterCrudActionEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityDeletedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityUpdatedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeCrudActionEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityDeletedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityUpdatedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Exception\EntityRemoveException;
use EasyCorp\Bundle\EasyAdminBundle\Exception\ForbiddenActionException;
use EasyCorp\Bundle\EasyAdminBundle\Factory\ActionFactory;
use EasyCorp\Bundle\EasyAdminBundle\Factory\EntityFactory;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FormFactory;
use EasyCorp\Bundle\EasyAdminBundle\Factory\PaginatorFactory;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\FiltersFormType;
use EasyCorp\Bundle\EasyAdminBundle\Orm\EntityRepository;
use EasyCorp\Bundle\EasyAdminBundle\Orm\EntityUpdater;
use EasyCorp\Bundle\EasyAdminBundle\Provider\AdminContextProvider;
use EasyCorp\Bundle\EasyAdminBundle\Provider\FieldProvider;
use EasyCorp\Bundle\EasyAdminBundle\Router\CrudUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
abstract class AbstractCrudController extends AbstractController implements CrudControllerInterface
{
    public function configureCrud(Crud $crud): Crud
    {
        return $crud;
    }

    public function configureAssets(Assets $assets): Assets
    {
        return $assets;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters;
    }

    /**
     * {@inheritdoc}
     */
    public function configureFields(string $pageName): iterable
    {
        $entityFqcn = $this->getContext()->getCrud()->getEntityFqcn();
        $entityId = $this->getContext()->getRequest()->query->get('entityId');
        $entityPermission = $this->getContext()->getCrud()->getEntityPermission();

        $entity = new Entity($entityFqcn, $entityId, $entityPermission);
        $entityDto = $this->get(EntityFactory::class)->create($entity);

        return $this->get(FieldProvider::class)->getDefaultFields($pageName, $entityDto);
    }

    public static function getSubscribedServices()
    {
        return array_merge(parent::getSubscribedServices(), [
            'event_dispatcher' => '?'.EventDispatcherInterface::class,
            ActionFactory::class => '?'.ActionFactory::class,
            AdminContextProvider::class => '?'.AdminContextProvider::class,
            CrudUrlGenerator::class => '?'.CrudUrlGenerator::class,
            EntityFactory::class => '?'.EntityFactory::class,
            EntityRepository::class => '?'.EntityRepository::class,
            EntityUpdater::class => '?'.EntityUpdater::class,
            FieldProvider::class => '?'.FieldProvider::class,
            FilterFactory::class => '?'.FilterFactory::class,
            FormFactory::class => '?'.FormFactory::class,
            PaginatorFactory::class => '?'.PaginatorFactory::class,
        ]);
    }

    public function index(AdminContext $context)
    {
        $event = new BeforeCrudActionEvent($context);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        if (!$this->isGranted(Permission::EA_EXECUTE_ACTION)) {
            throw new ForbiddenActionException($context);
        }

        $entityFqcn = $context->getCrud()->getEntityFqcn();
        $entityId = $context->getRequest()->query->get('entityId');
        $entityPermission = $context->getCrud()->getEntityPermission();

        $entity = new Entity($entityFqcn, $entityId, $entityPermission);

        $entityDto = $this->get(EntityFactory::class)->create($entity);
        $fields = Fields::new($this->configureFields(Crud::PAGE_INDEX));
        $filters = $this->get(FilterFactory::class)->create($context->getCrud()->getFilters(), $fields, $entityDto);
        $queryBuilder = $this->createIndexQueryBuilder($context->getSearch(), $entityDto, $fields, $filters);
        $paginator = $this->get(PaginatorFactory::class)->create($queryBuilder);

        $entityInstances = $paginator->getResults();
        $actions = $this->get(ActionFactory::class)->create($context->getCrud()->getActions());
        $entities = $this->get(EntityFactory::class)->createAll($entityDto, $entityInstances, $fields, $actions);

        $responseParameters = $this->configureResponseParameters(ResponseParameters::new([
            'pageName' => Crud::PAGE_INDEX,
            'templateName' => 'crud/index',
            'entities' => $entities,
            'paginator' => $paginator,
            // 'batch_form' => $this->createBatchActionsForm(),
        ]));

        $event = new AfterCrudActionEvent($context, $responseParameters);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        return $responseParameters;
    }

    public function detail(AdminContext $context)
    {
        $event = new BeforeCrudActionEvent($context);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        if (!$this->isGranted(Permission::EA_EXECUTE_ACTION)) {
            throw new ForbiddenActionException($context);
        }

        $fields = $this->configureFields(Action::DETAIL);
        $actions = $this->get(ActionFactory::class)->create($context->getCrud()->getActions());
        $entityDto = $this->get(EntityFactory::class)->create($request->getEntity(), $fields, $actions);

        $responseParameters = $this->configureResponseParameters(ResponseParameters::new([
            'pageName' => Crud::PAGE_DETAIL,
            'templateName' => 'crud/detail',
            'entity' => $entityDto,
        ]));

        $event = new AfterCrudActionEvent($context, $responseParameters);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        return $responseParameters;
    }

    public function edit(AdminContext $context)
    {
        $event = new BeforeCrudActionEvent($context);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        if (!$this->isGranted(Permission::EA_EXECUTE_ACTION)) {
            throw new ForbiddenActionException($context);
        }

        $fields = $this->configureFields(Crud::PAGE_EDIT);
        $actions = $this->get(ActionFactory::class)->create($context->getCrud()->getActions());
        $entityDto = $this->get(EntityFactory::class)->create($request->getEntity(), $fields, $actions);
        $entityInstance = $entityDto->getInstance();

        if ($context->getRequest()->isXmlHttpRequest()) {
            $fieldName = $request->getContext()->getRequest()->query->get('fieldName');
            $newValue = 'true' === mb_strtolower($context->getRequest()->query->get('newValue'));

            $event = $this->ajaxEdit($entityDto, $fieldName, $newValue);
            if ($event->isPropagationStopped()) {
                return $event->getResponse();
            }

            // cast to integer instead of string to avoid sending empty responses for 'false'
            return new Response((int) $newValue);
        }

        $editForm = $this->createEditForm($entityDto);
        $editForm->handleRequest($context->getRequest());
        if ($editForm->isSubmitted() && $editForm->isValid()) {
            // TODO:
            // $this->processUploadedFiles($editForm);

            $event = new BeforeEntityUpdatedEvent($entityInstance);
            $this->get('event_dispatcher')->dispatch($event);
            $entityInstance = $event->getEntityInstance();

            $this->updateEntity($this->get('doctrine')->getManagerForClass($entityDto->getFqcn()), $entityInstance);

            $this->get('event_dispatcher')->dispatch(new AfterEntityUpdatedEvent($entityInstance));

            $submitButtonName = $context->getRequest()->request->get('ea')['newForm']['btn'];
            if (Action::SAVE_AND_CONTINUE === $submitButtonName) {
                $url = $this->get(CrudUrlGenerator::class)->build()
                    ->setAction(Action::EDIT)
                    ->setEntityId($entityDto->getPrimaryKeyValue())
                    ->generateUrl();

                return $this->redirect($url);
            }

            if (Action::SAVE_AND_RETURN === $submitButtonName) {
                $url = $context->getReferrer()
                    ?? $this->get(CrudUrlGenerator::class)->build()->setAction(Action::INDEX)->generateUrl();

                return $this->redirect($url);
            }

            return $this->redirectToRoute($context->getDashboardRouteName());
        }

        $responseParameters = $this->configureResponseParameters(ResponseParameters::new([
            'pageName' => Crud::PAGE_EDIT,
            'templateName' => 'crud/edit',
            'edit_form' => $editForm,
            'entity' => $entityDto->updateInstance($entityInstance),
        ]));

        $event = new AfterCrudActionEvent($context, $responseParameters);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        return $responseParameters;
    }

    public function new(AdminContext $context)
    {
        $event = new BeforeCrudActionEvent($context);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        if (!$this->isGranted(Permission::EA_EXECUTE_ACTION)) {
            throw new ForbiddenActionException($context);
        }

        $fields = $this->configureFields(Crud::PAGE_NEW);
        $actions = $this->get(ActionFactory::class)->create($context->getCrud()->getActions());
        $entityDto = $this->get(EntityFactory::class)->create($request->getEntity(), $fields, $actions);
        $entityInstance = $this->createEntity($entityDto->getFqcn());
        $entityDto = $entityDto->updateInstance($entityInstance);

        $newForm = $this->createNewForm($entityDto);
        $newForm->handleRequest($context->getRequest());
        if ($newForm->isSubmitted() && $newForm->isValid()) {
            // TODO:
            // $this->processUploadedFiles($newForm);

            $event = new BeforeEntityPersistedEvent($entityInstance);
            $this->get('event_dispatcher')->dispatch($event);
            $entityInstance = $event->getEntityInstance();

            $this->persistEntity($this->get('doctrine')->getManagerForClass($entityDto->getFqcn()), $entityInstance);

            $this->get('event_dispatcher')->dispatch(new AfterEntityPersistedEvent($entityInstance));
            $entityDto = $entityDto->updateInstance($entityInstance);

            $submitButtonName = $context->getRequest()->request->get('ea')['newForm']['btn'];
            if (Action::SAVE_AND_CONTINUE === $submitButtonName) {
                $url = $this->get(CrudUrlGenerator::class)->build()
                    ->setAction(Action::EDIT)
                    ->setEntityId($entityDto->getPrimaryKeyValue())
                    ->generateUrl();

                return $this->redirect($url);
            }

            if (Action::SAVE_AND_RETURN === $submitButtonName) {
                $url = $context->getReferrer()
                    ?? $this->get(CrudUrlGenerator::class)->build()->setAction(Action::INDEX)->generateUrl();

                return $this->redirect($url);
            }

            if (Action::SAVE_AND_ADD_ANOTHER === $submitButtonName) {
                $url = $this->get(CrudUrlGenerator::class)->build()->setAction(Action::NEW)->generateUrl();

                return $this->redirect($url);
            }

            return $this->redirectToRoute($context->getDashboardRouteName());
        }

        $responseParameters = $this->configureResponseParameters(ResponseParameters::new([
            'pageName' => Crud::PAGE_NEW,
            'templateName' => 'crud/new',
            'entity' => $entityDto,
            'new_form' => $newForm,
        ]));

        $event = new AfterCrudActionEvent($context, $responseParameters);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        return $responseParameters;
    }

    public function delete(AdminContext $context)
    {
        $event = new BeforeCrudActionEvent($context);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        if (!$this->isGranted(Permission::EA_EXECUTE_ACTION)) {
            throw new ForbiddenActionException($context);
        }

        $csrfToken = $context->getRequest()->request->get('token');
        if (!$this->isCsrfTokenValid('ea-delete', $csrfToken)) {
            return $this->redirectToRoute($context->getDashboardRouteName());
        }

        $entityDto = $this->get(EntityFactory::class)->create();
        $entityInstance = $entityDto->getInstance();

        $event = new BeforeEntityDeletedEvent($entityInstance);
        $this->get('event_dispatcher')->dispatch($event);
        $entityInstance = $event->getEntityInstance();

        try {
            $this->deleteEntity($this->get('doctrine')->getManagerForClass($entityDto->getFqcn()), $entityInstance);
        } catch (ForeignKeyConstraintViolationException $e) {
            throw new EntityRemoveException(['entity_name' => $entityDto->getName(), 'message' => $e->getMessage()]);
        }

        $this->get('event_dispatcher')->dispatch(new AfterEntityDeletedEvent($entityInstance));

        $responseParameters = $this->configureResponseParameters(ResponseParameters::new([
            'entity' => $entityDto,
        ]));

        $event = new AfterCrudActionEvent($context, $responseParameters);
        $this->get('event_dispatcher')->dispatch($event);
        if ($event->isPropagationStopped()) {
            return $event->getResponse();
        }

        return null !== $context->getReferrer()
            ? $this->redirect($context->getReferrer())
            : $this->redirectToRoute($context->getDashboardRouteName());
    }

    public function autocomplete(AdminContext $context): JsonResponse
    {
        $entityDto = $this->get(EntityFactory::class)->create($request->getEntity());
        $queryBuilder = $this->createIndexQueryBuilder($context->getSearch(), $entityDto);
        $paginator = $this->get(PaginatorFactory::class)->create($queryBuilder);

        return JsonResponse::fromJsonString($paginator->getJsonResults());
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, Fields $fields, array $filtersDto): QueryBuilder
    {
        return $this->get(EntityRepository::class)->createQueryBuilder($searchDto, $entityDto, $fields, $filtersDto);
    }

    public function renderFilters(AdminContext $context): ResponseParameters
    {
        $fields = $this->configureFields(Crud::PAGE_INDEX);
        $fields = \is_array($fields) ? $fields : iterator_to_array($fields);
        $entityDto = $this->get(EntityFactory::class)->create($request->getEntity());

        /** @var FiltersFormType $filtersForm */
        $filtersForm = $this->get(FormFactory::class)->createFiltersForm($context, Fields::new($fields), $entityDto);
        $formActionParts = parse_url($filtersForm->getConfig()->getAction());
        $queryString = $formActionParts['query'] ?? [];
        parse_str($queryString, $queryStringAsArray);

        $responseParameters = ResponseParameters::new([
            'templateName' => 'crud/filters',
            'filters_form' => $filtersForm,
            'form_action_query_string_as_array' => $queryStringAsArray,
        ]);

        return $this->configureResponseParameters($responseParameters);
    }

    public function createEntity(string $entityFqcn)
    {
        return new $entityFqcn();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        // there's no need to persist the entity explicitly again because it's already
        // managed by Doctrine. The instance is passed to the method in case the
        // user application needs to make decisions
        $entityManager->flush();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $entityManager->persist($entityInstance);
        $entityManager->flush();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $entityManager->remove($entityInstance);
        $entityManager->flush();
    }

    public function createEditForm(EntityDto $entityDto): FormInterface
    {
        return $this->get(FormFactory::class)->createEditForm($entityDto);
    }

    public function createNewForm(EntityDto $entityDto): FormInterface
    {
        return $this->get(FormFactory::class)->createNewForm($entityDto);
    }

    /**
     * Used to add/modify/remove parameters before passing them to the Twig template.
     */
    public function configureResponseParameters(ResponseParameters $responseParameters): ResponseParameters
    {
        return $responseParameters;
    }

    private function getContext(): ?AdminContext
    {
        return $this->get(AdminContextProvider::class)->getContext();
    }

    private function ajaxEdit(EntityDto $entityDto, ?string $propertyName, bool $newValue): AfterCrudActionEvent
    {
        if (!$entityDto->hasProperty($propertyName)) {
            throw new \RuntimeException(sprintf('The "%s" boolean field cannot be changed because it doesn\'t exist in the "%s" entity.', $propertyName, $entityDto->getName()));
        }

        $this->get(EntityUpdater::class)->updateProperty($entityDto, $propertyName, $newValue);

        $event = new BeforeEntityUpdatedEvent($entityDto->getInstance());
        $this->get('event_dispatcher')->dispatch($event);
        $entityInstance = $event->getEntityInstance();

        $this->updateEntity($this->get('doctrine')->getManagerForClass($entityDto->getFqcn()), $entityInstance);

        $this->get('event_dispatcher')->dispatch(new AfterEntityUpdatedEvent($entityInstance));

        $parameters = ResponseParameters::new([
            'action' => Action::EDIT,
            'entity' => $entityDto->updateInstance($entityInstance),
        ]);

        $event = new AfterCrudActionEvent($this->getContext(), $parameters);
        $this->get('event_dispatcher')->dispatch($event);

        return $event;
    }
}

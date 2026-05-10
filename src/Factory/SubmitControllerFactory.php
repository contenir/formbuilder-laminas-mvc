<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Laminas\Mvc\Controller\SubmitController;
use Contenir\FormBuilder\Laminas\Mvc\Loader\LaminasDbFormLoader;
use Contenir\FormBuilder\Laminas\Mvc\Registrar\StoreSubmissionRegistrar;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Contenir\Storage\StorageManager;
use Psr\Container\ContainerInterface;
use SplObserver;

use function is_array;
use function is_string;

class SubmitControllerFactory
{
    public function __invoke(ContainerInterface $container): SubmitController
    {
        $config   = $container->get('config')['formbuilder'] ?? [];
        $services = $container instanceof \Laminas\Mvc\Controller\ControllerManager
            ? $container->getServiceLocator()
            : $container;

        $storage = null;
        if ($services->has(StorageManager::class)) {
            $candidate = $services->get(StorageManager::class);
            if ($candidate instanceof StorageManager) {
                $storage = $candidate;
            }
        }

        $service = new FormSubmissionService(
            new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory()),
            $storage,
        );

        $observers = [$services->get(StoreSubmissionRegistrar::class)];
        foreach (is_array($config['observers'] ?? null) ? $config['observers'] : [] as $entry) {
            if (! is_string($entry) || ! $services->has($entry)) {
                continue;
            }
            $resolved = $services->get($entry);
            if ($resolved instanceof SplObserver) {
                $observers[] = $resolved;
            }
        }

        $siteContext = is_array($config['site_context'] ?? null) ? $config['site_context'] : [];

        return new SubmitController(
            $services->get(LaminasDbFormLoader::class),
            $service,
            $observers,
            $siteContext,
        );
    }
}

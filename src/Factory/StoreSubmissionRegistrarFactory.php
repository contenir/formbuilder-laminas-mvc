<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\Registrar\StoreSubmissionRegistrar;
use Contenir\FormBuilder\Laminas\Mvc\Repository\LaminasDbEntryRepository;
use Psr\Container\ContainerInterface;

class StoreSubmissionRegistrarFactory
{
    public function __invoke(ContainerInterface $container): StoreSubmissionRegistrar
    {
        return new StoreSubmissionRegistrar(
            $container->get(LaminasDbEntryRepository::class),
        );
    }
}

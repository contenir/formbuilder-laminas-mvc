<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\Repository\LaminasDbEntryRepository;
use Laminas\Db\Adapter\Adapter;
use Psr\Container\ContainerInterface;

class LaminasDbEntryRepositoryFactory
{
    public function __invoke(ContainerInterface $container): LaminasDbEntryRepository
    {
        $config    = $container->get('config')['formbuilder'] ?? [];
        $adapterId = (string) ($config['db_adapter'] ?? Adapter::class);
        /** @var Adapter $adapter */
        $adapter   = $container->get($adapterId);
        return new LaminasDbEntryRepository($adapter);
    }
}

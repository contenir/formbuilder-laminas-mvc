<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\Loader\LaminasDbFormLoader;
use Laminas\Db\Adapter\Adapter;
use Psr\Container\ContainerInterface;

class LaminasDbFormLoaderFactory
{
    public function __invoke(ContainerInterface $container): LaminasDbFormLoader
    {
        $config       = $container->get('config')['formbuilder'] ?? [];
        $adapterId    = (string) ($config['db_adapter'] ?? Adapter::class);
        /** @var Adapter $adapter */
        $adapter      = $container->get($adapterId);
        return new LaminasDbFormLoader($adapter);
    }
}

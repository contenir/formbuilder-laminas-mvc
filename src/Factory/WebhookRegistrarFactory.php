<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;

class WebhookRegistrarFactory
{
    public function __invoke(ContainerInterface $container): WebhookRegistrar
    {
        $logger = null;
        if ($container->has(LoggerInterface::class)) {
            $candidate = $container->get(LoggerInterface::class);
            if ($candidate instanceof LoggerInterface) {
                $logger = $candidate;
            }
        }

        return new WebhookRegistrar($logger);
    }
}

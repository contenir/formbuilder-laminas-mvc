<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\Registrar\EmailNotificationRegistrar;
use Contenir\FormBuilder\Service\TokenReplacer;
use Laminas\Mail\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;

class EmailNotificationRegistrarFactory
{
    public function __invoke(ContainerInterface $container): EmailNotificationRegistrar
    {
        /** @var TransportInterface $transport */
        $transport = $container->get(TransportInterface::class);

        $logger = null;
        if ($container->has(LoggerInterface::class)) {
            $candidate = $container->get(LoggerInterface::class);
            if ($candidate instanceof LoggerInterface) {
                $logger = $candidate;
            }
        }

        return new EmailNotificationRegistrar(
            $container->get(TokenReplacer::class),
            $transport,
            $logger,
        );
    }
}

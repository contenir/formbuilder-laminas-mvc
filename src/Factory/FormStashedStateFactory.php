<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Contenir\FormBuilder\Laminas\Mvc\View\Helper\FormStashedState;
use Psr\Container\ContainerInterface;

class FormStashedStateFactory
{
    public function __invoke(ContainerInterface $container): FormStashedState
    {
        return new FormStashedState($container->get(FormStateStash::class));
    }
}

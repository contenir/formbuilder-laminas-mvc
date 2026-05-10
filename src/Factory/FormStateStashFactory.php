<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Psr\Container\ContainerInterface;

class FormStateStashFactory
{
    public function __invoke(ContainerInterface $container): FormStateStash
    {
        return new FormStateStash();
    }
}

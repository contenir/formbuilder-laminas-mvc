<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\View\Helper;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Render\FormMarkup as RenderFormMarkup;
use Laminas\Form\FormInterface;
use Laminas\View\Helper\AbstractHelper;

use function method_exists;

/**
 * Laminas-View adapter for the framework-agnostic
 * {@see RenderFormMarkup} renderer.
 *
 * Wires the host's `escapeHtml` plugin into the renderer's escape hook
 * so HTML output is escaped per the application's view-helper config
 * (charset, recursion limit, etc.) rather than the renderer's
 * generic htmlspecialchars default.
 */
class FormMarkup extends AbstractHelper
{
    public function __construct(private RenderFormMarkup $renderer = new RenderFormMarkup())
    {
    }

    public function __invoke(FormDefinition $definition, FormInterface $form, bool $preview = false): string
    {
        $view = $this->getView();
        if ($view !== null && method_exists($view, 'plugin')) {
            $escapeHtml = $view->plugin('escapeHtml');
            $this->renderer->setEscaper(static fn (string $value): string => (string) $escapeHtml($value));
        }
        return $this->renderer->render($definition, $form, $preview);
    }
}

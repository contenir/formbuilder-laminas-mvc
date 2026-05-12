<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\View\Helper;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Render\FormMarkup as RenderFormMarkup;
use Laminas\Form\FormInterface;
use Laminas\Form\View\Helper\FormElement;
use Laminas\View\Helper\AbstractHelper;

use function method_exists;

/**
 * Laminas-View adapter that renders a {@see FormDefinition} via
 * {@see RenderFormMarkup}.
 *
 * Wires two view-helper plugins into the renderer at render time:
 *  - `escapeHtml` so HTML output is escaped per the application's
 *    view-helper config (charset, recursion limit, etc.) rather than the
 *    renderer's generic htmlspecialchars default.
 *  - `formElement` so every input dispatched through the renderer travels
 *    through the same helper as a hand-rolled `<?= $this->formElement($e) ?>`
 *    call. Delegators registered against `Laminas\Form\View\Helper\FormElement`
 *    (e.g. the page-cache CSRF-disable delegator shipped by
 *    `contenir/cache-laminas-mvc`) therefore observe formbuilder renders too.
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

            $formElement = $view->plugin('formElement');
            if ($formElement instanceof FormElement) {
                $this->renderer->setFormElementHelper($formElement);
            }
        }
        return $this->renderer->render($definition, $form, $preview);
    }
}

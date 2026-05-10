<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\View\Helper;

use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Laminas\View\Helper\AbstractHelper;

/**
 * View-side accessor for {@see FormStateStash}.
 *
 * Section partials call `$this->formStashedState($slug)` to read-and-
 * clear any stashed state for that form. Returns null when there is
 * nothing pending — the partial then renders an empty form.
 *
 * Kept deliberately thin: the partial is responsible for hydrating the
 * Laminas form (`setData()` for values, `setMessages()` for errors).
 * That keeps this helper free of any form-building dependency.
 */
class FormStashedState extends AbstractHelper
{
    public function __construct(private FormStateStash $stash)
    {
    }

    /**
     * @return ?array{values: array<string, mixed>, errors: array<string, array<int, string>>}
     */
    public function __invoke(string $slug): ?array
    {
        return $this->stash->consume($slug);
    }
}

<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\State;

use Laminas\Session\Container;

use function is_array;
use function preg_replace;
use function strtolower;

/**
 * One-shot session stash for invalid submissions.
 *
 * On a non-AJAX validation failure the SubmitController stores the raw
 * post values plus the form's validator messages here, then redirects
 * back to the page that hosts the form. The next render of the section
 * block calls {@see consume()} to read-and-clear the entry, hydrating
 * the rebuilt Laminas form so the user sees their values and the
 * inline error messages instead of an empty form.
 *
 * Entries are keyed by form slug so multiple form blocks on the same
 * page do not collide. Read once and discarded — a second render with
 * no new submission shows a blank form, which is what the user expects
 * after they navigated away and came back.
 */
class FormStateStash
{
    private const SESSION_NAMESPACE = 'ContenirFormBuilderFlash';

    private Container $container;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? new Container(self::SESSION_NAMESPACE);
    }

    /**
     * @param array<string, mixed>              $values
     * @param array<string, array<int, string>> $errors
     */
    public function store(string $slug, array $values, array $errors): void
    {
        $this->container[$this->key($slug)] = [
            'values' => $values,
            'errors' => $errors,
        ];
    }

    /**
     * Read and remove the stash for $slug.
     *
     * @return ?array{values: array<string, mixed>, errors: array<string, array<int, string>>}
     */
    public function consume(string $slug): ?array
    {
        $key = $this->key($slug);
        if (! isset($this->container[$key])) {
            return null;
        }
        $data = $this->container[$key];
        unset($this->container[$key]);

        if (! is_array($data) || ! isset($data['values'], $data['errors'])) {
            return null;
        }
        if (! is_array($data['values']) || ! is_array($data['errors'])) {
            return null;
        }

        return [
            'values' => $data['values'],
            'errors' => $data['errors'],
        ];
    }

    private function key(string $slug): string
    {
        $clean = preg_replace('/[^a-z0-9_\-]/', '_', strtolower($slug)) ?? '';
        return 'form_' . $clean;
    }
}

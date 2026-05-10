<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Registrar;

use ArrayObject;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Repository\LaminasDbEntryRepository;
use Contenir\FormBuilder\Service\BuilderForm;
use SplObserver;
use SplSubject;

use function is_array;

/**
 * Persists a submitted form into the entries tables (Laminas\Db edition).
 *
 * Reads the {@see BuilderForm} subject's registry for the form definition,
 * the canonical submitted values, and submission metadata, then delegates
 * to {@see LaminasDbEntryRepository::record()} so the persistence concern
 * stays in one place.
 *
 * Mirrors admin4's `PeptoCms\Form\Builder\Registrar\StoreSubmissionRegistrar`
 * but bound to a Laminas\Db repository — admin4's version persists while
 * this version covers the public submit path on Laminas-MVC sites.
 */
class StoreSubmissionRegistrar implements SplObserver
{
    public function __construct(private LaminasDbEntryRepository $repository)
    {
    }

    public function update(SplSubject $subject): void
    {
        if (! $subject instanceof BuilderForm) {
            return;
        }

        $registry = $this->extractRegistry($subject);
        $form     = $registry['form'] ?? null;
        $context  = $registry['context'] ?? [];

        if (! $form instanceof FormDefinition) {
            return;
        }
        if ($form->id === null) {
            return;
        }

        $values = $registry['values'] ?? [];
        if (! is_array($values)) {
            return;
        }

        $status = $registry['spam'] ?? false
            ? LaminasDbEntryRepository::STATUS_SPAM
            : LaminasDbEntryRepository::STATUS_COMPLETE;
        $ip     = isset($context['ip']) ? (string) $context['ip'] : null;
        $userId = isset($context['user_id']) ? (int) $context['user_id'] : null;
        $meta   = isset($context['meta']) && is_array($context['meta']) ? $context['meta'] : [];

        $entryId = $this->repository->record($form->id, $values, $status, $ip, $userId, $meta);

        $registry['entry_id']     = $entryId;
        $registry['entry_status'] = $status;
        $this->writeBack($subject, $registry);
    }

    /** @return array<string, mixed> */
    private function extractRegistry(BuilderForm $form): array
    {
        $registry = $form->registry;
        if ($registry instanceof ArrayObject) {
            return (array) $registry;
        }
        return [];
    }

    /** @param array<string, mixed> $registry */
    private function writeBack(BuilderForm $form, array $registry): void
    {
        if ($form->registry instanceof ArrayObject) {
            foreach ($registry as $key => $value) {
                $form->registry[$key] = $value;
            }
            return;
        }
        $form->registry = new ArrayObject($registry, ArrayObject::ARRAY_AS_PROPS);
    }
}

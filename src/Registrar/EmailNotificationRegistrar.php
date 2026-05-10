<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Registrar;

use ArrayObject;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Service\BuilderForm;
use Contenir\FormBuilder\Service\TokenReplacer;
use Laminas\Mail\Message;
use Laminas\Mail\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function filter_var;
use function is_array;
use function preg_split;
use function sprintf;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * Sends each enabled notification defined for the form after submission,
 * via Laminas\Mail.
 *
 * Skips when the entry was flagged as spam, when no notifications are
 * configured, or when the per-notification trigger does not match.
 * Failures are logged via the optional PSR-3 logger rather than
 * propagating — a transport error must never prevent submission
 * persistence.
 *
 * Mirrors the Zend_Mail-bound variant kept in admin4 for the legacy
 * ZF1 admin module; the runtime semantics (token replacement, address
 * splitting, validation, From/Reply-To handling) are identical.
 */
class EmailNotificationRegistrar implements SplObserver
{
    public function __construct(
        private TokenReplacer $tokens,
        private TransportInterface $transport,
        private ?LoggerInterface $log = null,
    ) {
    }

    public function update(SplSubject $subject): void
    {
        if (! $subject instanceof BuilderForm) {
            return;
        }

        $registry = $this->extractRegistry($subject);
        $form     = $registry['form'] ?? null;
        if (! $form instanceof FormDefinition) {
            return;
        }
        if ((bool) ($registry['spam'] ?? false)) {
            return;
        }

        $values = isset($registry['values']) && is_array($registry['values']) ? $registry['values'] : [];
        $entry  = isset($registry['entry'])  && is_array($registry['entry']) ? $registry['entry'] : [];

        foreach ($form->notifications as $notification) {
            if (! $notification->enabled) {
                continue;
            }
            $this->dispatch($notification, $form, $values, $entry);
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $entry
     */
    private function dispatch(
        NotificationDefinition $notification,
        FormDefinition $form,
        array $values,
        array $entry,
    ): void {
        try {
            $message = new Message();
            $message->setEncoding('UTF-8');
            $message->setSubject($this->tokens->replace($notification->subject, $form, $values, $entry));
            $message->setBody($this->tokens->replace($notification->bodyTemplate ?? '', $form, $values, $entry));

            if ($notification->fromAddress !== null && $notification->fromAddress !== '') {
                $message->setFrom($notification->fromAddress);
            }
            if ($notification->replyTo !== null && $notification->replyTo !== '') {
                $message->setReplyTo($notification->replyTo);
            }

            foreach ($this->splitAddresses($notification->toAddress) as $recipient) {
                $resolved = $this->tokens->replace($recipient, $form, $values, $entry);
                if ($this->isValidAddress($resolved)) {
                    $message->addTo($resolved);
                }
            }

            $this->transport->send($message);
        } catch (Throwable $e) {
            $this->log?->warning(sprintf(
                'Form notification "%s" failed for form "%s": %s',
                $notification->name,
                $form->slug,
                $e->getMessage(),
            ));
        }
    }

    /** @return list<string> */
    private function splitAddresses(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $s): bool => $s !== '',
        ));
    }

    private function isValidAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
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
}

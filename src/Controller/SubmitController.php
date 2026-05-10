<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Controller;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Loader\LaminasDbFormLoader;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Service\TokenReplacer;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use SplObserver;

use function http_build_query;
use function in_array;
use function is_array;
use function is_string;
use function str_contains;

/**
 * Public POST endpoint for site submissions.
 *
 * Routes typically map at `/forms/submit/{slug}`. The controller:
 *  - Resolves the form by slug via {@see LaminasDbFormLoader}.
 *  - Hands off to {@see FormSubmissionService} for build / validate /
 *    spam-detection / dispatch.
 *  - Branches on the form's `settings.success.mode` for the response:
 *    redirect_referrer (default) / redirect_url / inline_message.
 *  - Returns JSON when `Accept: application/json` is set on the
 *    request, otherwise redirects.
 *
 * Observers (registrars) are attached by the consumer site via
 * {@see ConfigProvider}'s factory wiring or by passing an array of
 * SplObserver instances at construction time.
 *
 * @phpstan-type SuccessSettings array{
 *     mode: string,
 *     redirect_url: ?string,
 *     title: ?string,
 *     message: ?string,
 * }
 */
class SubmitController extends AbstractActionController
{
    /** @param list<SplObserver> $observers */
    public function __construct(
        private LaminasDbFormLoader $loader,
        private FormSubmissionService $service,
        private array $observers = [],
        /** @var array<string, mixed> */
        private array $siteContext = [],
    ) {
        foreach ($observers as $observer) {
            $service->attach($observer);
        }
    }

    public function submitAction(): mixed
    {
        $request = $this->getRequest();
        if (! $request->isPost()) {
            return $this->respondJson(['error' => 'Method not allowed'], 405);
        }

        $slug = (string) $this->params()->fromRoute('slug', '');
        if ($slug === '') {
            return $this->respondJson(['error' => 'Missing slug'], 400);
        }

        $form = $this->loader->loadBySlug($slug);
        if ($form === null) {
            return $this->respondJson(['error' => 'Form not found'], 404);
        }

        $context = [
            'ip'      => $this->getServerVar('REMOTE_ADDR'),
            'user_id' => null,
            'meta'    => [
                'user_agent' => (string) $this->getServerVar('HTTP_USER_AGENT'),
                'referer'    => (string) $this->getServerVar('HTTP_REFERER'),
            ],
        ];

        $post   = $request->getPost()->toArray();
        $files  = $_FILES;
        $result = $this->service->submit($form, $post, $files, $context);

        $isSuccess = $result->valid || $result->isSpam;
        if (! $isSuccess) {
            if ($this->wantsJson()) {
                return $this->respondJson(['ok' => false, 'errors' => $result->errors], 422);
            }
            return $this->redirectToReferrer('invalid', null);
        }

        $success = $this->resolveSuccessSettings($form);
        $tokens  = new TokenReplacer($this->siteContext);
        $entry   = $result->entryId !== null ? ['id' => $result->entryId] : [];

        if ($this->wantsJson()) {
            $payload = ['ok' => true, 'mode' => $success['mode']];
            if (
                $success['mode'] === FormDefinition::SUCCESS_REDIRECT_URL
                && is_string($success['redirect_url']) && $success['redirect_url'] !== ''
            ) {
                $payload['url'] = $tokens->replaceForUrl($success['redirect_url'], $form, $result->values, $entry);
            } elseif ($success['mode'] === FormDefinition::SUCCESS_INLINE_MESSAGE) {
                $payload['title']   = $tokens->replace((string) $success['title'], $form, $result->values, $entry);
                $payload['message'] = $tokens->replace((string) $success['message'], $form, $result->values, $entry);
            }
            return $this->respondJson($payload, 200);
        }

        if (
            $success['mode'] === FormDefinition::SUCCESS_REDIRECT_URL
            && is_string($success['redirect_url']) && $success['redirect_url'] !== ''
        ) {
            $url = $tokens->replaceForUrl($success['redirect_url'], $form, $result->values, $entry);
            return $this->redirect()->toUrl($url);
        }

        return $this->redirectToReferrer('ok', $form->slug);
    }

    /**
     * @return SuccessSettings
     */
    private function resolveSuccessSettings(FormDefinition $form): array
    {
        $stored = is_array($form->settings['success'] ?? null) ? $form->settings['success'] : [];

        $mode = $stored['mode'] ?? null;
        $mode = is_string($mode) && in_array($mode, FormDefinition::SUCCESS_MODES, true)
            ? $mode
            : FormDefinition::SUCCESS_REDIRECT_REFERRER;

        return [
            'mode'         => $mode,
            'redirect_url' => is_string($stored['redirect_url'] ?? null) ? $stored['redirect_url'] : null,
            'title'        => is_string($stored['title'] ?? null) ? $stored['title'] : null,
            'message'      => is_string($stored['message'] ?? null) ? $stored['message'] : null,
        ];
    }

    private function redirectToReferrer(string $status, ?string $slug): Response
    {
        $referer = (string) $this->getServerVar('HTTP_REFERER', '/');
        $params  = ['form' => $status];
        if ($slug !== null && $slug !== '') {
            $params['slug'] = $slug;
        }
        $separator = str_contains($referer, '?') ? '&' : '?';
        return $this->redirect()->toUrl($referer . $separator . http_build_query($params));
    }

    private function wantsJson(): bool
    {
        $accept = (string) $this->getServerVar('HTTP_ACCEPT');
        return str_contains($accept, 'application/json');
    }

    private function getServerVar(string $key, string $default = ''): string
    {
        return (string) ($_SERVER[$key] ?? $default);
    }

    /** @param array<string, mixed> $payload */
    private function respondJson(array $payload, int $status): JsonModel
    {
        $this->getResponse()->setStatusCode($status);
        return new JsonModel($payload);
    }
}

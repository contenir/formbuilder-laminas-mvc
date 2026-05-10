<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Factory;

use Contenir\FormBuilder\Service\TokenReplacer;
use Psr\Container\ContainerInterface;

use function is_array;
use function is_callable;
use function is_string;

/**
 * Builds a shared {@see TokenReplacer} for notification + redirect URL
 * substitution. The `site:` namespace is wired from
 * `formbuilder.site_context`; consumers can register additional
 * namespaces via `formbuilder.token_resolvers`, an array keyed by
 * namespace name with values that are service-manager ids resolving
 * to callables of shape `function (string $key): string`.
 *
 * Example consumer config:
 *
 *     'formbuilder' => [
 *         'token_resolvers' => [
 *             'settings' => Application\Mail\TokenResolver\SettingsResolver::class,
 *         ],
 *     ],
 *
 * The same instance is consumed by EmailNotificationRegistrar and
 * SubmitController so a `{settings:foo}` token resolves identically in
 * notification subject/body templates and in success-redirect URLs.
 */
class TokenReplacerFactory
{
    public function __invoke(ContainerInterface $container): TokenReplacer
    {
        $config      = $container->get('config')['formbuilder'] ?? [];
        $siteContext = is_array($config['site_context'] ?? null) ? $config['site_context'] : [];

        $tokens = new TokenReplacer($siteContext);

        $resolvers = is_array($config['token_resolvers'] ?? null) ? $config['token_resolvers'] : [];
        foreach ($resolvers as $namespace => $serviceId) {
            if (! is_string($namespace) || $namespace === '' || ! is_string($serviceId)) {
                continue;
            }
            if (! $container->has($serviceId)) {
                continue;
            }
            $resolver = $container->get($serviceId);
            if (is_callable($resolver)) {
                $tokens->register($namespace, $resolver);
            }
        }

        return $tokens;
    }
}

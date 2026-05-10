# contenir/formbuilder-laminas-mvc

Laminas MVC adapter for [`contenir/formbuilder`](https://github.com/contenir/formbuilder).

Wires the framework-agnostic form-builder engine into a Laminas MVC site:
a Laminas\Db loader, an entry-write repository, the standard
`StoreSubmissionRegistrar`, a public-submit controller, a render-only
view helper, and a service-manager Module + ConfigProvider with
factories for everything.

## Install

```bash
composer require contenir/formbuilder-laminas-mvc
```

The package's Module gets auto-registered via `extra.laminas.module`
and `laminas/laminas-component-installer`. If your site doesn't run
the component-installer, add it to `config/modules.config.php`
manually:

```php
'Contenir\FormBuilder\Laminas\Mvc',
```

## Configuration

Defaults in `config/autoload/`:

```php
return [
    'formbuilder' => [
        // Service id of the Laminas\Db\Adapter\Adapter the loader
        // and entry repository should consume. Defaults to the
        // standard 'Laminas\Db\Adapter\Adapter' service name.
        'db_adapter'   => \Laminas\Db\Adapter\Adapter::class,
        // Static values for {site:*} TokenReplacer expansion.
        'site_context' => [],
        // Service ids of additional submission observers (extra
        // registrars) to attach beyond the built-in
        // StoreSubmissionRegistrar. Add email / webhook /
        // analytics registrars here.
        'observers'    => [],
    ],
];
```

## Use

The package follows a **controller-driven render pattern** — the
controller loads the definition, builds the form, branches on query
state; the view template only renders. There is no view helper that
does its own data-fetching.

```php
use Contenir\FormBuilder\Laminas\Mvc\Loader\LaminasDbFormLoader;
use Contenir\FormBuilder\Service\FormBuilderService;

class FormController extends AbstractActionController
{
    public function __construct(
        private LaminasDbFormLoader $loader,
        private FormBuilderService $builder,
    ) {
    }

    public function indexAction(): ViewModel
    {
        $definition = $this->loader->loadBySlug('contact');
        $form       = $this->builder->build($definition);
        $isSuccess  = $this->params()->fromQuery('form') === 'ok';

        return new ViewModel([
            'definition' => $definition,
            'form'       => $form,
            'isSuccess'  => $isSuccess,
        ]);
    }
}
```

```phtml
<?php if ($isSuccess): ?>
    <div class="form-success">Thank you.</div>
<?php else: ?>
    <?= $this->formMarkup($definition, $form) ?>
<?php endif ?>
```

Submissions POST to `/forms/submit/{slug}` (registered by the
ConfigProvider's `router.routes.forms-submit` entry). The
package's `SubmitController` builds the same submission service,
attaches the `StoreSubmissionRegistrar` plus any observers from
`formbuilder.observers`, and branches on the form's stored
post-submission action (redirect_referrer / redirect_url /
inline_message — see contenir/formbuilder docs).

## License

MIT.

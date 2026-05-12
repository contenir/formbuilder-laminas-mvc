<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Render;

use Contenir\FormBuilder\Conditional\RuleEvaluator;
use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;
use Contenir\FormBuilder\Html\FormContentSanitizer;
use Contenir\FormBuilder\Service\FormBuilderService;
use Laminas\Form\Element\Checkbox;
use Laminas\Form\Element\File;
use Laminas\Form\Element\MultiCheckbox;
use Laminas\Form\Element\Radio;
use Laminas\Form\Element\Select;
use Laminas\Form\ElementInterface;
use Laminas\Form\FormInterface;
use Laminas\Form\View\Helper\FormElement;
use LogicException;

use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_scalar;
use function json_encode;
use function preg_replace;
use function sprintf;
use function strpos;
use function trim;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;

/**
 * Walks a {@see FormDefinition} and emits the form markup, dispatching each
 * input through the Laminas `formElement` view helper so that delegators
 * registered against `Laminas\Form\View\Helper\FormElement` (e.g. the
 * page-cache CSRF-disable delegator shipped by `contenir/cache-laminas-mvc`)
 * fire for formbuilder forms.
 *
 * Radio and multi-checkbox lists keep bespoke per-option markup because the
 * stock Laminas helpers wrap each `<input>` in a `<label>`, which is
 * incompatible with the BEM `formbuilder__control--checkbox-list-item`
 * pattern this project's CSS targets.
 *
 * The outer BEM structure (section / group / row / field / actions) and the
 * preview-mode class prefix (`form-preview__*`) match the framework-agnostic
 * {@see \Contenir\FormBuilder\Render\FormMarkup} renderer so consumers can
 * swap implementations without touching templates or stylesheets.
 */
class FormMarkup
{
    private RuleEvaluator $conditionalEvaluator;
    private bool $preview = false;

    /** @var (callable(string): string)|null */
    private $escaper = null;

    private ?FormElement $formElement = null;

    /** @var array<string, mixed> */
    private array $valueContext = [];

    public function __construct()
    {
        $this->conditionalEvaluator = new RuleEvaluator();
    }

    /**
     * Plug in a host-provided HTML escaper. The callable takes a string
     * and returns the escaped string.
     *
     * @param callable(string): string $escaper
     */
    public function setEscaper(callable $escaper): void
    {
        $this->escaper = $escaper;
    }

    /**
     * Plug in the Laminas form-element view helper. The renderer hands every
     * non-choice-list input to this helper rather than building markup
     * directly, so the helper's delegator chain (most notably the
     * CSRF cache-disable delegator) intercepts every render.
     */
    public function setFormElementHelper(FormElement $formElement): void
    {
        $this->formElement = $formElement;
    }

    public function render(FormDefinition $definition, FormInterface $form, bool $preview = false): string
    {
        $this->preview      = $preview;
        $this->valueContext = $this->buildValueContext($form);

        if ($definition->layoutMode === FormDefinition::LAYOUT_STEPPED && $definition->sections !== []) {
            return $this->renderStepped($definition, $form);
        }

        $html  = $this->openForm($form);
        $html .= $this->renderSections($definition, $form);
        $html .= $this->renderActions($definition, $form);
        $html .= '</form>';

        return $html;
    }

    private function classFor(string $element): string
    {
        $preview = $this->preview;
        return match ($element) {
            'section'             => $preview ? 'form-preview__section' : 'formbuilder__section',
            'section-title'       => $preview ? 'form-preview__section-title' : 'formbuilder__section-title',
            'section-description' => $preview
                ? 'form-preview__section-description'
                : 'formbuilder__section-description',
            'group'               => $preview ? 'form-preview__group' : 'formbuilder__panel',
            'group-description'   => $preview
                ? 'form-preview__group-description'
                : 'formbuilder__panel-description',
            'group-empty'         => $preview ? 'form-preview__group-empty' : 'formbuilder__panel-empty',
            'group-body'          => $preview ? 'form-preview__group-body' : 'formbuilder__panel-body',
            'row'                 => $preview ? 'form-preview__row' : 'formbuilder__row',
            'field'               => $preview ? 'form-preview__field' : 'formbuilder__field',
            default               => '',
        };
    }

    /** @return array<string, mixed> */
    private function buildValueContext(FormInterface $form): array
    {
        $context = [];
        foreach ($form->getElements() as $element) {
            $context[$element->getName()] = $element->getValue();
        }
        return $context;
    }

    private function openForm(FormInterface $form, bool $stepped = false): string
    {
        $attribs  = $form->getAttributes();
        $defaults = [
            'method'       => 'post',
            'class'        => 'formbuilder__form formbuilder__form--stacked',
            'autocomplete' => 'on',
        ];
        foreach ($defaults as $key => $value) {
            if (! isset($attribs[$key]) || $attribs[$key] === '') {
                $attribs[$key] = $value;
            }
        }
        if ($stepped) {
            $attribs['class']             = trim((string) $attribs['class'] . ' formbuilder__form--stepped');
            $attribs['data-form-stepper'] = 'true';
        }
        if ($this->hasFileField($form)) {
            $attribs['enctype'] = 'multipart/form-data';
        }

        return '<form' . $this->htmlAttribs($attribs) . '>';
    }

    private function hasFileField(FormInterface $form): bool
    {
        foreach ($form->getElements() as $element) {
            if ($element instanceof File) {
                return true;
            }
        }
        return false;
    }

    private function renderSections(FormDefinition $definition, FormInterface $form): string
    {
        $html = '';
        foreach ($definition->sections as $section) {
            $html .= $this->renderSection($section, $form);
        }
        return $html;
    }

    private function renderSection(SectionDefinition $section, FormInterface $form): string
    {
        $body = $this->renderSectionBody($section, $form);
        if ($body === '') {
            return '';
        }

        $hasLegend      = $section->legend !== null && $section->legend !== '';
        $hasDescription = $section->description !== null && $section->description !== '';
        if (! $hasLegend && ! $hasDescription) {
            return $body;
        }

        $html = '<section class="' . $this->classFor('section') . '">';
        if ($hasLegend) {
            $html .= '<h2 class="' . $this->classFor('section-title') . '">'
                . $this->escape((string) $section->legend) . '</h2>';
        }
        if ($hasDescription) {
            $html .= '<p class="' . $this->classFor('section-description') . '">'
                . $this->escape((string) $section->description) . '</p>';
        }
        $html .= $body;
        $html .= '</section>';

        return $html;
    }

    private function renderSectionBody(SectionDefinition $section, FormInterface $form): string
    {
        $html = '';
        foreach ($section->groups as $group) {
            $html .= $this->renderGroup($group, $form);
        }
        return $html;
    }

    private function renderStepped(FormDefinition $definition, FormInterface $form): string
    {
        $sections  = $definition->sections;
        $lastIndex = count($sections) - 1;

        $html  = $this->openForm($form, true);
        $html .= $this->renderStepNav($sections);

        foreach ($sections as $index => $section) {
            $isFirst = $index === 0;
            $isLast  = $index === $lastIndex;
            $stepKey = $this->escape($section->key);
            $hidden  = $isFirst ? '' : ' hidden';
            $active  = $isFirst ? ' is-active' : '';

            $html .= sprintf(
                '<section class="formbuilder__step%s" data-form-step="%s"%s>',
                $active,
                $stepKey,
                $hidden,
            );

            if ($section->legend !== null && $section->legend !== '') {
                $html .= '<h2 class="formbuilder__step-title">' . $this->escape($section->legend) . '</h2>';
            }
            if ($section->description !== null && $section->description !== '') {
                $html .= '<p class="formbuilder__step-description">' . $this->escape($section->description) . '</p>';
            }

            $html .= $this->renderSectionBody($section, $form);
            $html .= $this->renderStepNavButtons($definition, $form, $isFirst, $isLast);
            $html .= '</section>';
        }

        $html .= '</form>';
        return $html;
    }

    /** @param list<SectionDefinition> $sections */
    private function renderStepNav(array $sections): string
    {
        $html = '<ol class="formbuilder__steps" role="tablist">';
        foreach ($sections as $index => $section) {
            $isFirst  = $index === 0;
            $disabled = $isFirst ? '' : ' disabled';
            $active   = $isFirst ? ' is-active' : '';
            $label    = $section->legend !== null && $section->legend !== ''
                ? $section->legend
                : ucfirst(str_replace(['-', '_'], ' ', $section->key));

            $html .= sprintf(
                '<li><button type="button" class="formbuilder__step-tab%s" data-form-step-target="%s"%s>'
                    . '<span class="formbuilder__step-tab-index">%d</span> %s</button></li>',
                $active,
                $this->escape($section->key),
                $disabled,
                $index + 1,
                $this->escape($label),
            );
        }
        $html .= '</ol>';
        return $html;
    }

    private function renderStepNavButtons(
        FormDefinition $definition,
        FormInterface $form,
        bool $isFirst,
        bool $isLast,
    ): string {
        $html = '<nav class="formbuilder__step-nav">';
        if (! $isFirst) {
            $html .= '<button type="button" class="btn" data-form-step-prev>Previous</button>';
        }
        if ($isLast) {
            $html .= $this->renderActions($definition, $form);
        } else {
            $html .= '<button type="button" class="btn btn--primary" data-form-step-next>Next</button>';
        }
        $html .= '</nav>';
        return $html;
    }

    private function renderGroup(GroupDefinition $group, FormInterface $form): string
    {
        $rows = '';
        foreach ($group->rows as $row) {
            $rows .= $this->renderRow($row, $form);
        }

        $html = '<fieldset class="' . $this->classFor('group') . '">';
        if ($group->legend !== null && $group->legend !== '') {
            $html .= '<legend class="formbuilder__legend">' . $this->escape($group->legend) . '</legend>';
        }
        if ($group->description !== null && $group->description !== '') {
            $html .= '<p class="' . $this->classFor('group-description') . '">'
                . $this->escape($group->description) . '</p>';
        }
        if ($rows === '') {
            $html .= '<p class="' . $this->classFor('group-empty') . '"><em>No fields in this group yet.</em></p>';
        } else {
            $html .= '<div class="' . $this->classFor('group-body') . '">' . $rows . '</div>';
        }
        $html .= '</fieldset>';

        return $html;
    }

    private function renderRow(RowDefinition $row, FormInterface $form): string
    {
        if ($row->fields === []) {
            return '';
        }

        $cols = '';
        foreach ($row->fields as $field) {
            if ($field->type === 'content') {
                $cols .= $this->renderContent($field);
                continue;
            }
            if (! $form->has($field->name)) {
                continue;
            }
            $element = $form->get($field->name);
            $cols   .= $this->renderField($field, $element, $form);
        }
        if ($cols === '') {
            return '';
        }

        return '<div class="' . $this->classFor('row') . '">' . $cols . '</div>';
    }

    private function renderContent(FieldDefinition $field): string
    {
        $raw = (string) ($field->options['html'] ?? '');
        if (trim($raw) === '') {
            return '';
        }

        $colAttribs = ['class' => $this->classFor('field')];

        if ($field->conditional !== null && $field->conditional !== []) {
            $encoded = json_encode($field->conditional, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $colAttribs['data-form-conditional'] = $encoded;
                if (! $this->conditionalEvaluator->shouldShow($field->conditional, $this->valueContext)) {
                    $colAttribs['hidden'] = 'hidden';
                }
            }
        }

        $body         = FormContentSanitizer::sanitize($raw);
        $contentClass = $this->preview ? 'form-preview__content' : 'formbuilder__content';

        return '<div' . $this->htmlAttribs($colAttribs) . '>'
            . '<div class="' . $contentClass . '">' . $body . '</div>'
            . '</div>';
    }

    private function renderField(FieldDefinition $field, ElementInterface $element, FormInterface $form): string
    {
        $colAttribs = [
            'class' => $this->classFor('field'),
        ];

        if ($field->conditional !== null && $field->conditional !== []) {
            $encoded = json_encode($field->conditional, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $colAttribs['data-form-conditional'] = $encoded;
                if (! $this->conditionalEvaluator->shouldShow($field->conditional, $this->valueContext)) {
                    $colAttribs['hidden'] = 'hidden';
                }
            }
        }

        $html = '<div' . $this->htmlAttribs($colAttribs) . '>';

        // Single checkboxes draw their own sibling label inline (the styled
        // custom-checkbox UI puts the `<label>` AFTER the `<input>` rather
        // than wrapping it). renderCheckbox emits both.
        $isCheckbox = $element instanceof Checkbox;

        if (! $isCheckbox && $field->showLabel && $field->label !== null && $field->label !== '') {
            $labelClass = $field->required
                ? 'formbuilder__label formbuilder__label--required'
                : 'formbuilder__label';
            $html      .= sprintf(
                '<label class="%s" for="%s">%s</label>',
                $labelClass,
                $this->escape($field->name),
                $this->escape($field->label),
            );
        }

        $html .= '<div class="formbuilder__element">'
            . $this->renderInput($element)
            . '</div>';

        $html .= $this->renderErrors($form, $field->name);

        if ($field->description !== null && $field->description !== '') {
            $html .= '<p class="formbuilder__description">' . $this->escape($field->description) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    private function renderActions(FormDefinition $definition, FormInterface $form): string
    {
        $alignment = $this->escape($definition->submitAlignment);
        $html      = '<div class="formbuilder__actions formbuilder__actions--' . $alignment . '">';

        if ($form->has(FormBuilderService::CSRF_NAME)) {
            $html .= $this->renderInput($form->get(FormBuilderService::CSRF_NAME));
        }

        if ($form->has(FormBuilderService::HONEYPOT_NAME)) {
            $html .= '<div class="formbuilder__honeypot" aria-hidden="true">'
                . $this->renderInput($form->get(FormBuilderService::HONEYPOT_NAME))
                . '</div>';
        }

        if ($form->has('_submit')) {
            $html .= $this->renderInput($form->get('_submit'));
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Hands the element to the Laminas `formElement` view helper for every
     * type the helper produces compatible markup for. Radio and
     * multi-checkbox lists go through the bespoke {@see renderChoiceList}
     * (Laminas wraps each option in a `<label>`, which doesn't match this
     * project's sibling-label pattern).
     */
    private function renderInput(ElementInterface $element): string
    {
        return match (true) {
            $element instanceof Radio         => $this->renderChoiceList($element, 'radio'),
            $element instanceof MultiCheckbox => $this->renderChoiceList($element, 'checkbox'),
            $element instanceof Checkbox      => $this->renderCheckbox($element),
            $element instanceof Select        => $this->renderViaFormElement($this->prepareSelect($element)),
            default                           => $this->renderViaFormElement($element),
        };
    }

    private function renderViaFormElement(ElementInterface $element): string
    {
        if ($this->formElement === null) {
            throw new LogicException(
                'FormMarkup requires a Laminas\\Form\\View\\Helper\\FormElement helper; '
                . 'call setFormElementHelper() before render().',
            );
        }
        return ($this->formElement)($element);
    }

    /**
     * Apply the `formbuilder__control--select` modifier to single-value
     * selects so the project's chevron / appearance reset CSS targets them.
     * Multi-selects keep their native list rendering — no chevron makes
     * sense — so the modifier is skipped.
     */
    private function prepareSelect(Select $element): Select
    {
        $isMulti = (bool) ($element->getAttributes()['multiple'] ?? false);
        if ($isMulti) {
            return $element;
        }

        $existing = (string) ($element->getAttribute('class') ?? '');
        if (strpos($existing, 'formbuilder__control--select') !== false) {
            return $element;
        }

        $element->setAttribute('class', trim($existing . ' formbuilder__control--select'));
        return $element;
    }

    /**
     * Render a radio or multi-checkbox list with sibling-label markup.
     *
     * The stock Laminas helpers wrap each option's `<input>` in a `<label>`,
     * which the project's `.formbuilder__control--checkbox-list-item` CSS
     * doesn't target. This emits the project's BEM-shaped structure
     * directly while keeping per-option attribute escaping consistent.
     *
     * @param Radio|MultiCheckbox $element
     */
    private function renderChoiceList(ElementInterface $element, string $type): string
    {
        $name     = $element->getName();
        $isRadio  = $type === 'radio';
        $selected = $this->normaliseSelected($element->getValue());

        $html = '<div class="formbuilder__control--checkbox-list">';
        foreach ($element->getValueOptions() as $value => $label) {
            $inputId = sprintf('%s-%s', $name, preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $value));
            $attribs = [
                'type'  => $type,
                'name'  => $isRadio ? $name : $name . '[]',
                'id'    => $inputId,
                'value' => (string) $value,
                'class' => 'formbuilder__control--checkbox',
            ];
            if (in_array((string) $value, $selected, true)) {
                $attribs['checked'] = 'checked';
            }
            $html .= '<span class="formbuilder__control--checkbox-list-item">';
            $html .= '<input' . $this->htmlAttribs($attribs) . '>';
            $html .= '<label class="formbuilder__label" for="' . $this->escape($inputId) . '">'
                . $this->escape((string) $label) . '</label>';
            $html .= '</span>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Render a single checkbox with the project's `formbuilder__control--checkbox`
     * styling and an inline trailing `<label>`. The hidden + checkbox input
     * pair is produced by the Laminas `formCheckbox` helper (via
     * `formElement`) so that delegators registered against `FormElement`
     * still observe the render.
     */
    private function renderCheckbox(Checkbox $element): string
    {
        $existing = (string) ($element->getAttribute('class') ?? '');
        $swapped  = trim((string) preg_replace(
            '/\bformbuilder__control\b/',
            'formbuilder__control--checkbox',
            $existing,
        ));
        if (strpos($swapped, 'formbuilder__control--checkbox') === false) {
            $swapped = trim('formbuilder__control--checkbox ' . $swapped);
        }
        $element->setAttribute('class', $swapped);

        $id = (string) ($element->getAttribute('id') ?? '');
        if ($id === '') {
            $id = (string) $element->getName();
            $element->setAttribute('id', $id);
        }

        // `formCheckbox` reads label text from the helper's own translator,
        // not from the element's label property — clearing it would change
        // no output. The label is emitted below as a sibling so the
        // project's custom-checkbox CSS (input + ~ label) applies.
        $label = (string) ($element->getLabel() ?? '');

        $input = $this->renderViaFormElement($element);

        $labelHtml = $label !== ''
            ? sprintf(
                '<label class="formbuilder__label" for="%s">%s</label>',
                $this->escape($id),
                $this->escape($label),
            )
            : '';

        return $input . $labelHtml;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normaliseSelected(mixed $value): array
    {
        if (is_array($value)) {
            return array_map(static fn ($v): string => (string) $v, $value);
        }
        if ($value === null || $value === '') {
            return [];
        }
        return [is_scalar($value) ? (string) $value : ''];
    }

    private function renderErrors(FormInterface $form, string $name): string
    {
        $messages = $form->getMessages($name);
        if (! is_array($messages) || $messages === []) {
            return '';
        }

        $items = '';
        foreach ($messages as $message) {
            if (is_array($message)) {
                foreach ($message as $nested) {
                    $items .= '<li>' . $this->escape((string) $nested) . '</li>';
                }
                continue;
            }
            $items .= '<li>' . $this->escape((string) $message) . '</li>';
        }

        return '<ul class="formbuilder__errors">' . $items . '</ul>';
    }

    private function escape(string $value): string
    {
        if ($this->escaper !== null) {
            return ($this->escaper)($value);
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, scalar|null> $attribs */
    private function htmlAttribs(array $attribs): string
    {
        $out = '';
        foreach ($attribs as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $out .= sprintf(' %s="%s"', $name, $this->escape((string) $value));
        }
        return $out;
    }
}

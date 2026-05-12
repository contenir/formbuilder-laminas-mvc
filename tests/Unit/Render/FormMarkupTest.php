<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Render;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Render\FormMarkup;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\FormDefinitionFactory;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\RecordingFormElement;
use Contenir\FormBuilder\Laminas\Mvc\Tests\TestAsset\ViewRendererBuilder;
use Contenir\FormBuilder\Service\FormBuilderService;
use Laminas\Form\Element\Checkbox;
use Laminas\Form\Element\Csrf;
use Laminas\Form\Element\Email;
use Laminas\Form\Element\File;
use Laminas\Form\Element\Hidden;
use Laminas\Form\Element\MultiCheckbox;
use Laminas\Form\Element\Radio;
use Laminas\Form\Element\Select;
use Laminas\Form\Element\Submit;
use Laminas\Form\Element\Text;
use Laminas\Form\Element\Textarea;
use Laminas\Form\Form;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FormMarkupTest extends TestCase
{
    private FormMarkup $renderer;
    private RecordingFormElement $formElement;

    protected function setUp(): void
    {
        [$view] = ViewRendererBuilder::build();
        $this->formElement = new RecordingFormElement();
        $this->formElement->setView($view);

        $this->renderer = new FormMarkup();
        $this->renderer->setFormElementHelper($this->formElement);
    }

    public function testRendersOpeningAndClosingFormTags(): void
    {
        $form = $this->buildForm();
        $def  = FormDefinitionFactory::withFields([]);

        $html = $this->renderer->render($def, $form);

        self::assertStringStartsWith('<form', $html);
        self::assertStringContainsString('class="formbuilder__form formbuilder__form--stacked"', $html);
        self::assertMatchesRegularExpression('/method="(post|POST)"/', $html);
        self::assertStringContainsString('autocomplete="on"', $html);
        self::assertStringEndsWith('</form>', $html);
    }

    public function testCsrfElementIsRoutedThroughFormElementHelper(): void
    {
        $form = $this->buildForm();
        $form->add(new Csrf(FormBuilderService::CSRF_NAME));
        $def  = FormDefinitionFactory::withFields([]);

        $this->renderer->render($def, $form);

        self::assertContains(Csrf::class, $this->formElement->renderedClasses());
    }

    /**
     * @param class-string<\Laminas\Form\ElementInterface> $expected
     */
    #[DataProvider('elementsRoutedThroughFormElementProvider')]
    public function testRoutesScalarElementsThroughFormElementHelper(
        string $factoryMethod,
        string $type,
        string $name,
        string $expected,
    ): void {
        $form  = $this->buildForm();
        $element = self::$factoryMethod($name);
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field($type, $name),
        ]);

        $this->renderer->render($def, $form);

        self::assertContains($expected, $this->formElement->renderedClasses());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: class-string}> */
    public static function elementsRoutedThroughFormElementProvider(): array
    {
        return [
            'text'     => ['makeText', 'text', 'first_name', Text::class],
            'email'    => ['makeText', 'email', 'email', Text::class], // helper just creates a Text
            'hidden'   => ['makeHidden', 'hidden', 'token', Hidden::class],
            'textarea' => ['makeTextarea', 'textarea', 'notes', Textarea::class],
            'submit'   => ['makeSubmit', 'submit', '_submit', Submit::class],
        ];
    }

    public function testSingleSelectGetsControlSelectClassAndRoutesThroughFormElement(): void
    {
        $form    = $this->buildForm();
        $element = new Select('country');
        $element->setValueOptions(['au' => 'Australia', 'nz' => 'New Zealand']);
        $element->setAttribute('class', 'formbuilder__control');
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('select', 'country'),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertContains(Select::class, $this->formElement->renderedClasses());
        self::assertStringContainsString('formbuilder__control--select', $html);
        self::assertStringContainsString('<select', $html);
        self::assertStringContainsString('<option value="au">Australia</option>', $html);
    }

    public function testMultiSelectKeepsBracketNameAndSkipsSelectModifierClass(): void
    {
        $form    = $this->buildForm();
        $element = new Select('roles');
        $element->setAttribute('multiple', true);
        $element->setValueOptions(['admin' => 'Admin', 'editor' => 'Editor']);
        $element->setAttribute('class', 'formbuilder__control');
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('multiselect', 'roles'),
        ]);

        $html = $this->renderer->render($def, $form);

        // Laminas's escape helper renders the brackets as HTML entities;
        // the browser decodes them back to `[]` either way.
        self::assertMatchesRegularExpression('/name="roles(\[\]|&#x5B;&#x5D;)"/', $html);
        self::assertStringNotContainsString('formbuilder__control--select', $html);
    }

    public function testRadioListEmitsSiblingLabelMarkupAndBypassesFormElementHelper(): void
    {
        $form    = $this->buildForm();
        $element = new Radio('contact_method');
        $element->setValueOptions(['email' => 'Email', 'phone' => 'Phone']);
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('radio', 'contact_method'),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertNotContains(Radio::class, $this->formElement->renderedClasses());
        self::assertStringContainsString('formbuilder__control--checkbox-list', $html);
        self::assertStringContainsString('<span class="formbuilder__control--checkbox-list-item">', $html);
        self::assertStringContainsString('<input type="radio" name="contact_method"', $html);
        self::assertStringContainsString(
            '<label class="formbuilder__label" for="contact_method-email">Email</label>',
            $html,
        );
    }

    public function testMultiCheckboxListEmitsSiblingLabelMarkupAndBypassesFormElementHelper(): void
    {
        $form    = $this->buildForm();
        $element = new MultiCheckbox('interests');
        $element->setValueOptions(['news' => 'News', 'events' => 'Events']);
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('multicheckbox', 'interests'),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertNotContains(MultiCheckbox::class, $this->formElement->renderedClasses());
        self::assertStringContainsString('<input type="checkbox" name="interests[]"', $html);
        self::assertStringContainsString(
            '<label class="formbuilder__label" for="interests-news">News</label>',
            $html,
        );
    }

    public function testSingleCheckboxRoutesInputPairThroughFormElementWithTrailingLabel(): void
    {
        $form    = $this->buildForm();
        $element = new Checkbox('subscribe');
        $element->setLabel('Subscribe to newsletter');
        $element->setAttribute('class', 'formbuilder__control');
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('checkbox', 'subscribe'),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertContains(Checkbox::class, $this->formElement->renderedClasses());
        self::assertStringContainsString('formbuilder__control--checkbox', $html);
        self::assertStringContainsString('type="hidden"', $html);
        self::assertStringContainsString('type="checkbox"', $html);
        // Sibling label, not wrapping label
        self::assertStringContainsString(
            '<label class="formbuilder__label" for="subscribe">Subscribe to newsletter</label>',
            $html,
        );
    }

    public function testFileFieldSwitchesFormEnctypeToMultipart(): void
    {
        $form    = $this->buildForm();
        $element = new File('upload');
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('file', 'upload'),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertContains(File::class, $this->formElement->renderedClasses());
    }

    public function testHoneypotElementWrappedInHoneypotDiv(): void
    {
        $form = $this->buildForm();
        $form->add(new Text(FormBuilderService::HONEYPOT_NAME));
        $def  = FormDefinitionFactory::withFields([]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString('<div class="formbuilder__honeypot" aria-hidden="true">', $html);
    }

    public function testConditionalFieldGetsHiddenAttributeWhenRuleFails(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('details'));

        $rule = [
            'show_when' => [
                'all' => [
                    ['field' => 'opt_in', 'op' => 'equals', 'value' => 'yes'],
                ],
            ],
        ];

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'details', ['conditional' => $rule]),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString('data-form-conditional=', $html);
        self::assertStringContainsString(' hidden="hidden"', $html);
    }

    public function testRequiredFieldLabelCarriesRequiredModifierClass(): void
    {
        $form = $this->buildForm();
        $form->add(new Email('email'));

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('email', 'email', ['required' => true]),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString(
            '<label class="formbuilder__label formbuilder__label--required" for="email">Email</label>',
            $html,
        );
    }

    public function testFieldLabelOmittedWhenShowLabelIsFalse(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('first_name'));

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'first_name', ['showLabel' => false]),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertStringNotContainsString('<label class="formbuilder__label"', $html);
    }

    public function testFieldDescriptionRenderedAfterInput(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('promo_code'));

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'promo_code', ['description' => 'Optional']),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString('<p class="formbuilder__description">Optional</p>', $html);
    }

    public function testErrorMessagesRenderedAsList(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('email'));
        $form->setMessages(['email' => ['Invalid email address']]);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'email'),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString(
            '<ul class="formbuilder__errors"><li>Invalid email address</li></ul>',
            $html,
        );
    }

    public function testStepLayoutEmitsStepNavAndPerStepSections(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('first_name'));
        $form->add(new Text('email'));

        $def = FormDefinitionFactory::withSections([
            ['key' => 'about', 'legend' => 'About', 'fields' => [FormDefinitionFactory::field('text', 'first_name')]],
            ['key' => 'contact', 'legend' => 'Contact', 'fields' => [FormDefinitionFactory::field('text', 'email')]],
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString('<ol class="formbuilder__steps"', $html);
        self::assertStringContainsString('data-form-step-target="about"', $html);
        self::assertStringContainsString('data-form-step-target="contact"', $html);
        self::assertStringContainsString('data-form-step="about"', $html);
        self::assertStringContainsString('data-form-step="contact"', $html);
        self::assertStringContainsString('data-form-stepper="true"', $html);
    }

    public function testPreviewModeSwapsToPreviewClassPrefix(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('first_name'));

        $row     = new \Contenir\FormBuilder\Definition\RowDefinition(
            null,
            0,
            [FormDefinitionFactory::field('text', 'first_name')],
        );
        $group   = new \Contenir\FormBuilder\Definition\GroupDefinition(null, 'Personal', null, 0, [$row]);
        $section = new \Contenir\FormBuilder\Definition\SectionDefinition(null, 'main', 'Main', null, 0, [$group]);
        $def     = new FormDefinition(
            id: 1,
            slug: 'preview',
            title: 'Preview',
            sections: [$section],
        );

        $html = $this->renderer->render($def, $form, preview: true);

        self::assertStringContainsString('class="form-preview__section"', $html);
        self::assertStringContainsString('class="form-preview__field"', $html);
        self::assertStringContainsString('class="form-preview__row"', $html);
    }

    public function testRenderWithoutFormElementHelperThrowsLogicException(): void
    {
        $renderer = new FormMarkup();
        $form     = $this->buildForm();
        $form->add(new Text('first_name'));
        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'first_name'),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('FormMarkup requires a Laminas\\Form\\View\\Helper\\FormElement helper');

        $renderer->render($def, $form);
    }

    public function testContentFieldEmitsSanitizedHtmlBody(): void
    {
        $form = $this->buildForm();

        $contentField = FormDefinitionFactory::field('content', 'intro', [
            'options' => ['html' => '<p>Welcome <strong>back</strong></p>'],
        ]);
        $def = FormDefinitionFactory::withFields([$contentField]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString('<div class="formbuilder__content">', $html);
        self::assertStringContainsString('<p>Welcome <strong>back</strong></p>', $html);
    }

    public function testCustomEscaperIsUsedForLabelText(): void
    {
        $this->renderer->setEscaper(static fn (string $value): string => '[ESC:' . $value . ']');

        $form = $this->buildForm();
        $form->add(new Text('first_name'));
        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'first_name', ['label' => 'Name']),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString('[ESC:Name]', $html);
    }

    public function testRadioOptionMatchingSelectedValueGetsCheckedAttribute(): void
    {
        $form    = $this->buildForm();
        $element = new Radio('contact_method');
        $element->setValueOptions(['email' => 'Email', 'phone' => 'Phone']);
        $element->setValue('phone');
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('radio', 'contact_method'),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertMatchesRegularExpression(
            '/id="contact_method-phone"[^>]*checked="checked"/',
            $html,
        );
    }

    public function testMultiCheckboxArrayDefaultMarksMatchingOptionsChecked(): void
    {
        $form    = $this->buildForm();
        $element = new MultiCheckbox('interests');
        $element->setValueOptions(['news' => 'News', 'events' => 'Events', 'jobs' => 'Jobs']);
        $element->setValue(['news', 'jobs']);
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('multicheckbox', 'interests'),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertMatchesRegularExpression('/id="interests-news"[^>]*checked="checked"/', $html);
        self::assertMatchesRegularExpression('/id="interests-jobs"[^>]*checked="checked"/', $html);
        self::assertDoesNotMatchRegularExpression('/id="interests-events"[^>]*checked="checked"/', $html);
    }

    public function testSingleCheckboxRendersWithoutSiblingLabelWhenElementLabelEmpty(): void
    {
        $form    = $this->buildForm();
        $element = new Checkbox('flag');
        $form->add($element);

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('checkbox', 'flag', ['label' => null]),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertContains(Checkbox::class, $this->formElement->renderedClasses());
        // No sibling label emitted (the input pair still renders)
        self::assertStringNotContainsString('<label class="formbuilder__label" for="flag">', $html);
    }

    public function testSectionLegendRenderedWhenSet(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('first_name'));

        $row     = new \Contenir\FormBuilder\Definition\RowDefinition(
            null,
            0,
            [FormDefinitionFactory::field('text', 'first_name')],
        );
        $group   = new \Contenir\FormBuilder\Definition\GroupDefinition(null, null, null, 0, [$row]);
        $section = new \Contenir\FormBuilder\Definition\SectionDefinition(
            null,
            'main',
            'About You',
            'Tell us a bit about yourself',
            0,
            [$group],
        );
        $def = new FormDefinition(id: 1, slug: 'sec', title: 'Sec', sections: [$section]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString(
            '<h2 class="formbuilder__section-title">About You</h2>',
            $html,
        );
        self::assertStringContainsString(
            '<p class="formbuilder__section-description">Tell us a bit about yourself</p>',
            $html,
        );
    }

    public function testGroupLegendAndDescriptionRendered(): void
    {
        $form = $this->buildForm();
        $form->add(new Text('first_name'));

        $row   = new \Contenir\FormBuilder\Definition\RowDefinition(
            null,
            0,
            [FormDefinitionFactory::field('text', 'first_name')],
        );
        $group = new \Contenir\FormBuilder\Definition\GroupDefinition(
            null,
            'Personal',
            'Personal details',
            0,
            [$row],
        );
        $section = new \Contenir\FormBuilder\Definition\SectionDefinition(null, 'main', null, null, 0, [$group]);
        $def     = new FormDefinition(id: 1, slug: 'g', title: 'G', sections: [$section]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString('<legend class="formbuilder__legend">Personal</legend>', $html);
        self::assertStringContainsString(
            '<p class="formbuilder__panel-description">Personal details</p>',
            $html,
        );
    }

    public function testEmptyGroupEmitsPlaceholderCopy(): void
    {
        $form    = $this->buildForm();
        $emptyRow = new \Contenir\FormBuilder\Definition\RowDefinition(null, 0, []);
        $group    = new \Contenir\FormBuilder\Definition\GroupDefinition(null, 'Empty', null, 0, [$emptyRow]);
        $section  = new \Contenir\FormBuilder\Definition\SectionDefinition(null, 'main', null, null, 0, [$group]);
        $def      = new FormDefinition(id: 1, slug: 'e', title: 'E', sections: [$section]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString(
            '<p class="formbuilder__panel-empty"><em>No fields in this group yet.</em></p>',
            $html,
        );
    }

    public function testContentFieldWithEmptyHtmlIsSkipped(): void
    {
        $form = $this->buildForm();

        $blank = FormDefinitionFactory::field('content', 'blank', ['options' => ['html' => '   ']]);
        $def   = FormDefinitionFactory::withFields([$blank]);

        $html = $this->renderer->render($def, $form);

        self::assertStringNotContainsString('formbuilder__content', $html);
    }

    public function testConditionalFieldVisibleWhenRulePasses(): void
    {
        $form = $this->buildForm();
        $form->add((new Text('opt_in'))->setValue('yes'));
        $form->add(new Text('details'));

        $rule = [
            'show_when' => [
                'all' => [
                    ['field' => 'opt_in', 'op' => 'equals', 'value' => 'yes'],
                ],
            ],
        ];

        $def = FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'opt_in'),
            FormDefinitionFactory::field('text', 'details', ['conditional' => $rule]),
        ]);

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString('data-form-conditional=', $html);
        self::assertStringNotContainsString(' hidden="hidden"', $html);
    }

    public function testSubmitAlignmentClassFollowsDefinition(): void
    {
        $form = $this->buildForm();
        $form->add(new Submit('_submit'));

        $def = new FormDefinition(
            id: 1,
            slug: 'aligned',
            title: 'Aligned',
            submitAlignment: 'right',
            sections: [],
        );

        $html = $this->renderer->render($def, $form);

        self::assertStringContainsString('formbuilder__actions--right', $html);
    }

    private function buildForm(): Form
    {
        return new Form('test');
    }

    private static function makeText(string $name): Text
    {
        return new Text($name);
    }

    private static function makeHidden(string $name): Hidden
    {
        return new Hidden($name);
    }

    private static function makeTextarea(string $name): Textarea
    {
        return new Textarea($name);
    }

    private static function makeSubmit(string $name): Submit
    {
        $element = new Submit($name);
        $element->setValue('Submit');
        return $element;
    }
}

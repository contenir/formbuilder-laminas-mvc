<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\Loader;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Loader\LaminasDbFormLoader;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class LaminasDbFormLoaderTest extends TestCase
{
    private Adapter $adapter;
    private LaminasDbFormLoader $loader;

    protected function setUp(): void
    {
        $this->adapter = new Adapter([
            'driver' => 'Pdo_Sqlite',
            'dsn'    => 'sqlite::memory:',
        ]);
        $this->loadSchema();
        $this->loader = new LaminasDbFormLoader($this->adapter);
    }

    public function testLoadByIdReturnsNullWhenFormMissing(): void
    {
        self::assertNull($this->loader->loadById(9999));
    }

    public function testLoadBySlugReturnsNullWhenFormMissing(): void
    {
        self::assertNull($this->loader->loadBySlug('nope'));
    }

    public function testLoadByIdHydratesFullAggregate(): void
    {
        $formId = $this->seedSimpleForm();

        $form = $this->loader->loadById($formId);
        self::assertInstanceOf(FormDefinition::class, $form);
        self::assertSame('contact', $form->slug);
        self::assertSame('Contact', $form->title);
        self::assertCount(1, $form->sections);
        self::assertCount(1, $form->sections[0]->groups);
        self::assertCount(1, $form->sections[0]->groups[0]->rows);
        self::assertCount(2, $form->sections[0]->groups[0]->rows[0]->fields);

        $name  = $form->sections[0]->groups[0]->rows[0]->fields[0];
        $email = $form->sections[0]->groups[0]->rows[0]->fields[1];
        self::assertSame('name', $name->name);
        self::assertSame('text', $name->type);
        self::assertTrue($name->required);
        self::assertSame('email', $email->name);
        self::assertSame('email', $email->type);
    }

    public function testLoadBySlugHydratesSameAggregateAsLoadById(): void
    {
        $formId   = $this->seedSimpleForm();
        $byId     = $this->loader->loadById($formId);
        $bySlug   = $this->loader->loadBySlug('contact');

        self::assertNotNull($byId);
        self::assertNotNull($bySlug);
        self::assertSame($byId->id, $bySlug->id);
        self::assertSame($byId->slug, $bySlug->slug);
        self::assertCount(
            count($byId->sections[0]->groups[0]->rows[0]->fields),
            $bySlug->sections[0]->groups[0]->rows[0]->fields,
        );
    }

    public function testListSummariesReturnsLightweightProjection(): void
    {
        $this->seedSimpleForm();
        $rows = $this->loader->listSummaries();

        self::assertCount(1, $rows);
        self::assertSame('contact', $rows[0]['slug']);
        self::assertSame('Contact', $rows[0]['title']);
        self::assertSame('active', $rows[0]['status']);
    }

    public function testLoadAllReturnsAllFormsHydratedSortedByTitle(): void
    {
        $this->seedSimpleForm();
        $this->seedNamedForm('signup', 'Sign-Up');

        $forms = $this->loader->loadAll();

        self::assertCount(2, $forms);
        // ORDER BY title ASC: "Contact" < "Sign-Up"
        self::assertSame('Contact', $forms[0]->title);
        self::assertSame('Sign-Up', $forms[1]->title);
    }

    private function loadSchema(): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/../../install-forms.sqlite.sql');
        // Sqlite executes one statement at a time via Pdo; split on
        // semicolons that terminate a statement (the fixture is plain DDL,
        // no string literals containing semicolons).
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $this->adapter->getDriver()->getConnection()->getResource()->exec($stmt);
        }
    }

    private function seedSimpleForm(): int
    {
        return $this->seedNamedForm('contact', 'Contact');
    }

    private function seedNamedForm(string $slug, string $title): int
    {
        $now = '2026-05-10 00:00:00';
        $this->adapter->query(
            "INSERT INTO form (slug, title, layout_mode, submit_label, submit_alignment, "
            . "settings_json, status, created, updated) "
            . "VALUES (?, ?, 'single', 'Submit', 'left', '[]', 'active', ?, ?)",
            [$slug, $title, $now, $now],
        );
        $formId = (int) $this->adapter->getDriver()->getLastGeneratedValue();

        $this->adapter->query(
            "INSERT INTO form_section (form_id, key, legend, sort) VALUES (?, 'main', NULL, 0)",
            [$formId],
        );
        $sectionId = (int) $this->adapter->getDriver()->getLastGeneratedValue();

        $this->adapter->query(
            "INSERT INTO form_group (form_section_id, legend, sort) VALUES (?, NULL, 0)",
            [$sectionId],
        );
        $groupId = (int) $this->adapter->getDriver()->getLastGeneratedValue();

        $this->adapter->query(
            "INSERT INTO form_row (form_group_id, sort) VALUES (?, 0)",
            [$groupId],
        );
        $rowId = (int) $this->adapter->getDriver()->getLastGeneratedValue();

        $insertField = "INSERT INTO form_field "
            . "(form_row_id, type, name, label, show_label, required, col_span, sort, options_json) "
            . "VALUES (?, ?, ?, ?, 1, ?, 4, ?, '{}')";
        $this->adapter->query($insertField, [$rowId, 'text',  'name',  'Name',  1, 0]);
        $this->adapter->query($insertField, [$rowId, 'email', 'email', 'Email', 0, 1]);

        return $formId;
    }
}

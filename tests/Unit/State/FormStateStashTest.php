<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Tests\Unit\State;

use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Laminas\Session\Container;
use Laminas\Session\Storage\ArrayStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FormStateStashTest extends TestCase
{
    private FormStateStash $stash;

    protected function setUp(): void
    {
        Container::setDefaultManager(null);
        $manager = (new \Laminas\Session\SessionManager())->setStorage(new ArrayStorage());
        Container::setDefaultManager($manager);

        $this->stash = new FormStateStash(new Container('FormStateStashTest'));
    }

    protected function tearDown(): void
    {
        Container::setDefaultManager(null);
    }

    public function testConsumeReturnsNullWhenNothingStashed(): void
    {
        self::assertNull($this->stash->consume('contact'));
    }

    public function testStoreThenConsumeReturnsTheStashedPayload(): void
    {
        $this->stash->store(
            'contact',
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['email' => ['Invalid email']],
        );

        $consumed = $this->stash->consume('contact');

        self::assertSame(
            [
                'values' => ['name' => 'Alice', 'email' => 'alice@example.com'],
                'errors' => ['email' => ['Invalid email']],
            ],
            $consumed,
        );
    }

    public function testConsumeIsOneShot(): void
    {
        $this->stash->store('contact', ['name' => 'Alice'], []);

        $this->stash->consume('contact');

        self::assertNull($this->stash->consume('contact'));
    }

    public function testEntriesAreScopedPerSlug(): void
    {
        $this->stash->store('contact', ['name' => 'Alice'], []);
        $this->stash->store('enquiry', ['name' => 'Bob'], []);

        self::assertSame(['name' => 'Alice'], $this->stash->consume('contact')['values'] ?? null);
        self::assertSame(['name' => 'Bob'], $this->stash->consume('enquiry')['values'] ?? null);
    }

    public function testSlugsThatDifferOnlyByCaseShareAnEntry(): void
    {
        $this->stash->store('Contact', ['name' => 'Alice'], []);

        $consumed = $this->stash->consume('contact');

        self::assertNotNull($consumed);
        self::assertSame(['name' => 'Alice'], $consumed['values']);
    }

    public function testSlugsWithDisallowedCharsAreNormalisedToTheSameKey(): void
    {
        $this->stash->store('contact form!', ['name' => 'Alice'], []);

        $consumed = $this->stash->consume('contact_form_');

        self::assertNotNull($consumed);
        self::assertSame(['name' => 'Alice'], $consumed['values']);
    }
}

<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Loader;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;
use Contenir\FormBuilder\Definition\ValidatorDefinition;
use Contenir\FormBuilder\Definition\WebhookDefinition;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Adapter\Driver\ResultInterface;

use function array_fill;
use function array_filter;
use function array_map;
use function count;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function min;
use function strtoupper;

/**
 * Hydrates {@see FormDefinition} aggregates from the forms schema using
 * a Laminas\Db adapter.
 *
 * Mirrors the bulk-fetch shape of admin4's `DbFormLoader` (one query
 * per level: form → sections → groups → rows → fields, plus
 * notifications and webhooks) so cost is fixed regardless of form
 * size — no N+1 traversal.
 */
class LaminasDbFormLoader
{
    public function __construct(private Adapter $adapter)
    {
    }

    public function loadById(int $formId): ?FormDefinition
    {
        $row = $this->fetchOne('SELECT * FROM form WHERE form_id = ?', [$formId]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function loadBySlug(string $slug): ?FormDefinition
    {
        $row = $this->fetchOne('SELECT * FROM form WHERE slug = ?', [$slug]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    /** @return list<FormDefinition> */
    public function loadAll(): array
    {
        $rows  = $this->fetchAll('SELECT * FROM form ORDER BY title ASC', []);
        $forms = [];
        foreach ($rows as $row) {
            $forms[] = $this->hydrate($row);
        }
        return $forms;
    }

    /**
     * Lightweight projection — single query, no nested hydration.
     *
     * @return list<array{id: int, slug: string, title: string, status: string}>
     */
    public function listSummaries(): array
    {
        $rows = $this->fetchAll(
            'SELECT form_id, slug, title, status FROM form ORDER BY title ASC',
            [],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'     => (int) $row['form_id'],
                'slug'   => (string) $row['slug'],
                'title'  => (string) $row['title'],
                'status' => (string) $row['status'],
            ];
        }
        return $out;
    }

    /** @param array<string, mixed> $formRow */
    private function hydrate(array $formRow): FormDefinition
    {
        $formId = (int) $formRow['form_id'];

        $sectionRows = $this->fetchAll(
            'SELECT * FROM form_section WHERE form_id = ? ORDER BY sort ASC, form_section_id ASC',
            [$formId],
        );
        $notificationRows = $this->fetchAll(
            'SELECT * FROM form_notification WHERE form_id = ? ORDER BY sort ASC, form_notification_id ASC',
            [$formId],
        );
        $webhookRows = $this->fetchAll(
            'SELECT * FROM form_webhook WHERE form_id = ? ORDER BY sort ASC, form_webhook_id ASC',
            [$formId],
        );

        $sectionIds = array_map(static fn (array $r): int => (int) $r['form_section_id'], $sectionRows);
        $groupRows  = $sectionIds === []
            ? []
            : $this->fetchAll(
                'SELECT * FROM form_group WHERE form_section_id IN (' . $this->placeholders($sectionIds) . ') '
                . 'ORDER BY sort ASC, form_group_id ASC',
                $sectionIds,
            );

        $groupIds = array_map(static fn (array $r): int => (int) $r['form_group_id'], $groupRows);
        $rowRows  = $groupIds === []
            ? []
            : $this->fetchAll(
                'SELECT * FROM form_row WHERE form_group_id IN (' . $this->placeholders($groupIds) . ') '
                . 'ORDER BY sort ASC, form_row_id ASC',
                $groupIds,
            );

        $rowIds    = array_map(static fn (array $r): int => (int) $r['form_row_id'], $rowRows);
        $fieldRows = $rowIds === []
            ? []
            : $this->fetchAll(
                'SELECT * FROM form_field WHERE form_row_id IN (' . $this->placeholders($rowIds) . ') '
                . 'ORDER BY sort ASC, form_field_id ASC',
                $rowIds,
            );

        $fieldsByRow     = $this->groupFields($fieldRows);
        $rowsByGroup     = $this->groupRows($rowRows, $fieldsByRow);
        $groupsBySection = $this->groupGroups($groupRows, $rowsByGroup);
        $sections        = $this->buildSections($sectionRows, $groupsBySection);
        $notifications   = $this->buildNotifications($notificationRows);
        $webhooks        = $this->buildWebhooks($webhookRows);

        return new FormDefinition(
            id:              $formId,
            slug:            (string) $formRow['slug'],
            title:           (string) $formRow['title'],
            description:     $formRow['description'] !== null ? (string) $formRow['description'] : null,
            layoutMode:      (string) ($formRow['layout_mode'] ?? FormDefinition::LAYOUT_SINGLE),
            submitLabel:     (string) ($formRow['submit_label'] ?? 'Submit'),
            submitAlignment: (string) ($formRow['submit_alignment'] ?? 'left'),
            settings:        $this->decodeJson($formRow['settings_json'] ?? null),
            retentionDays:   isset($formRow['retention_days']) && $formRow['retention_days'] !== null
                ? (int) $formRow['retention_days']
                : null,
            status:          (string) ($formRow['status'] ?? FormDefinition::STATUS_ACTIVE),
            sections:        $sections,
            notifications:   $notifications,
            webhooks:        $webhooks,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<WebhookDefinition>
     */
    private function buildWebhooks(array $rows): array
    {
        $webhooks = [];
        foreach ($rows as $row) {
            $headers    = $this->decodeJson($row['headers_json'] ?? null);
            $webhooks[] = new WebhookDefinition(
                id:      (int) $row['form_webhook_id'],
                name:    (string) $row['name'],
                url:     (string) $row['url'],
                method:  strtoupper((string) ($row['method'] ?? 'POST')),
                secret:  $row['secret'] !== null && $row['secret'] !== ''
                    ? (string) $row['secret']
                    : null,
                headers: array_filter(
                    $headers,
                    static fn ($v): bool => is_string($v),
                ),
                enabled: (bool) ($row['enabled'] ?? true),
                sort:    (int) ($row['sort'] ?? 0),
            );
        }
        return $webhooks;
    }

    /**
     * @param list<array<string, mixed>> $fieldRows
     * @return array<int, list<FieldDefinition>>
     */
    private function groupFields(array $fieldRows): array
    {
        $byRow = [];
        foreach ($fieldRows as $row) {
            $rowId           = (int) $row['form_row_id'];
            $byRow[$rowId] ??= [];
            $byRow[$rowId][] = $this->buildField($row);
        }
        return $byRow;
    }

    /**
     * @param list<array<string, mixed>> $rowRows
     * @param array<int, list<FieldDefinition>> $fieldsByRow
     * @return array<int, list<RowDefinition>>
     */
    private function groupRows(array $rowRows, array $fieldsByRow): array
    {
        $byGroup = [];
        foreach ($rowRows as $row) {
            $rowId             = (int) $row['form_row_id'];
            $groupId           = (int) $row['form_group_id'];
            $byGroup[$groupId] ??= [];
            $byGroup[$groupId][] = new RowDefinition(
                id:     $rowId,
                sort:   (int) $row['sort'],
                fields: $fieldsByRow[$rowId] ?? [],
            );
        }
        return $byGroup;
    }

    /**
     * @param list<array<string, mixed>> $groupRows
     * @param array<int, list<RowDefinition>> $rowsByGroup
     * @return array<int, list<GroupDefinition>>
     */
    private function groupGroups(array $groupRows, array $rowsByGroup): array
    {
        $bySection = [];
        foreach ($groupRows as $row) {
            $groupId   = (int) $row['form_group_id'];
            $sectionId = (int) $row['form_section_id'];
            $bySection[$sectionId] ??= [];
            $bySection[$sectionId][] = new GroupDefinition(
                id:          $groupId,
                legend:      $row['legend'] !== null ? (string) $row['legend'] : null,
                description: $row['description'] !== null ? (string) $row['description'] : null,
                sort:        (int) $row['sort'],
                rows:        $rowsByGroup[$groupId] ?? [],
            );
        }
        return $bySection;
    }

    /**
     * @param list<array<string, mixed>> $sectionRows
     * @param array<int, list<GroupDefinition>> $groupsBySection
     * @return list<SectionDefinition>
     */
    private function buildSections(array $sectionRows, array $groupsBySection): array
    {
        $sections = [];
        foreach ($sectionRows as $row) {
            $sectionId  = (int) $row['form_section_id'];
            $sections[] = new SectionDefinition(
                id:          $sectionId,
                key:         (string) $row['key'],
                legend:      $row['legend'] !== null ? (string) $row['legend'] : null,
                description: $row['description'] !== null ? (string) $row['description'] : null,
                sort:        (int) $row['sort'],
                groups:      $groupsBySection[$sectionId] ?? [],
            );
        }
        return $sections;
    }

    /** @param array<string, mixed> $row */
    private function buildField(array $row): FieldDefinition
    {
        $validatorsRaw = $this->decodeJson($row['validators_json'] ?? null);
        $validators    = [];
        foreach ($validatorsRaw as $entry) {
            if (! is_array($entry) || ! isset($entry['type'])) {
                continue;
            }
            $validators[] = ValidatorDefinition::fromArray($entry);
        }

        $filtersRaw = $this->decodeJson($row['filters_json'] ?? null);
        $filters    = [];
        foreach ($filtersRaw as $value) {
            if (is_string($value) && $value !== '') {
                $filters[] = $value;
            }
        }

        return new FieldDefinition(
            id:           (int) $row['form_field_id'],
            type:         (string) $row['type'],
            name:         (string) $row['name'],
            label:        $row['label'] !== null ? (string) $row['label'] : null,
            showLabel:    (bool) ($row['show_label'] ?? true),
            description:  $row['description'] !== null ? (string) $row['description'] : null,
            placeholder:  $row['placeholder'] !== null ? (string) $row['placeholder'] : null,
            defaultValue: $row['default_value'] !== null ? (string) $row['default_value'] : null,
            required:     (bool) ($row['required'] ?? false),
            colSpan:      max(1, min(4, (int) ($row['col_span'] ?? 4))),
            sort:         (int) ($row['sort'] ?? 0),
            options:      $this->decodeJson($row['options_json'] ?? null),
            validators:   $validators,
            filters:      $filters,
            conditional:  $this->decodeJsonOrNull($row['conditional_json'] ?? null),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<NotificationDefinition>
     */
    private function buildNotifications(array $rows): array
    {
        $notifications = [];
        foreach ($rows as $row) {
            $notifications[] = new NotificationDefinition(
                id:           (int) $row['form_notification_id'],
                name:         (string) $row['name'],
                trigger:      (string) ($row['trigger'] ?? 'submit'),
                toAddress:    (string) ($row['to_address'] ?? ''),
                fromAddress:  $row['from_address'] !== null ? (string) $row['from_address'] : null,
                replyTo:      $row['reply_to'] !== null ? (string) $row['reply_to'] : null,
                subject:      (string) ($row['subject'] ?? ''),
                bodyTemplate: $row['body_template'] !== null ? (string) $row['body_template'] : null,
                conditions:   $this->decodeJsonOrNull($row['conditions_json'] ?? null),
                enabled:      (bool) ($row['enabled'] ?? true),
                sort:         (int) ($row['sort'] ?? 0),
            );
        }
        return $notifications;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params): ?array
    {
        $rows = $this->fetchAll($sql, $params);
        return $rows[0] ?? null;
    }

    /**
     * @param array<int, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params): array
    {
        $statement = $this->adapter->createStatement($sql);
        $result    = $statement->execute($params);

        if (! $result instanceof ResultInterface || ! $result->isQueryResult()) {
            return [];
        }

        $out = [];
        foreach ($result as $row) {
            $out[] = (array) $row;
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     */
    private function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed>|null */
    private function decodeJsonOrNull(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

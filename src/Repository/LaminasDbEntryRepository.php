<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Repository;

use DateTimeImmutable;
use Laminas\Db\Adapter\Adapter;
use Throwable;

use function is_array;
use function is_scalar;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Submission-write entry repository for Laminas\Db sites.
 *
 * Slice 2 ports only `record()` — that's everything the public submit
 * path needs. Read-side methods (find/list/setStatus/redact) live on
 * admin4's `EntryRepository` and aren't reproduced here because Sites
 * don't read entries; that's the admin module's job. Add them if a
 * future Site grows an entry-management surface.
 */
class LaminasDbEntryRepository
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_SPAM     = 'spam';
    public const STATUS_ARCHIVE  = 'archive';
    public const STATUS_REDACTED = 'redacted';

    public function __construct(private Adapter $adapter)
    {
    }

    /**
     * @param array<string, mixed> $values  field name => value
     * @param array<string, mixed> $meta
     */
    public function record(
        int $formId,
        array $values,
        string $status,
        ?string $ip,
        ?int $userId,
        array $meta = [],
    ): int {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $this->adapter->query(
                'INSERT INTO form_entry (form_id, submitted_at, ip, user_id, status, meta_json) '
                . 'VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $formId,
                    (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                    $ip,
                    $userId,
                    $status,
                    $meta === []
                        ? null
                        : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            );
            $entryId = (int) $this->adapter->getDriver()->getLastGeneratedValue();

            $insertValueSql = 'INSERT INTO form_entry_value '
                . '(form_entry_id, form_field_id, field_name, value_text, value_json) '
                . 'VALUES (?, ?, ?, ?, ?)';
            foreach ($values as $name => $value) {
                $this->adapter->query($insertValueSql, [
                    $entryId,
                    null,
                    (string) $name,
                    is_scalar($value) ? (string) $value : null,
                    is_array($value)
                        ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : null,
                ]);
            }

            $connection->commit();
            return $entryId;
        } catch (Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    }
}

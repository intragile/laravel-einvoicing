<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Ksef;

use DateTimeImmutable;
use Exception;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use IntrAgile\EInvoicing\Transport\Ksef\Inbound\CursorWindow;
use IntrAgile\EInvoicing\Transport\Ksef\Inbound\KsefCursorStore;
use JsonException;
use UnexpectedValueException;

/**
 * Keeps the KSeF inbox reading windows in the einvoicing_ksef_cursors table, one row per key.
 *
 * The window bounds are stored as ISO 8601 strings with microseconds and the UTC offset, so they
 * read back exactly as KSeF reported them; the KSeF numbers of the window and whether each one is
 * acknowledged are stored as a JSON object.
 */
final class DatabaseKsefCursorStore implements KsefCursorStore
{
    public const TABLE = 'einvoicing_ksef_cursors';

    /** The stored form of a window bound. */
    private const DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * @throws UnexpectedValueException when the stored row cannot be read back as a window
     */
    public function window(string $key): ?CursorWindow
    {
        $row = $this->connection->table(self::TABLE)->where('key', $key)->first(['from', 'hwm', 'ids']);
        if ($row === null) {
            return null;
        }
        $row = (array) $row;

        return new CursorWindow(
            self::date($row['from'] ?? null, $key) ?? throw self::corrupt($key),
            self::date($row['hwm'] ?? null, $key),
            self::ids($row['ids'] ?? null, $key),
        );
    }

    public function save(string $key, CursorWindow $window): void
    {
        $now = Carbon::now();
        $this->connection->table(self::TABLE)->upsert(
            [[
                'key' => $key,
                'from' => $window->from->format(self::DATE_FORMAT),
                'hwm' => $window->hwm?->format(self::DATE_FORMAT),
                'ids' => json_encode((object) $window->ids, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['key'],
            ['from', 'hwm', 'ids', 'updated_at'],
        );
    }

    /**
     * @throws UnexpectedValueException when the value is set but no stored date
     */
    private static function date(mixed $value, string $key): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw self::corrupt($key);
        }
        $date = DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $value);
        if ($date === false) {
            try {
                // A value edited by hand in another ISO 8601 form is still accepted.
                $date = new DateTimeImmutable($value);
            } catch (Exception) {
                throw self::corrupt($key);
            }
        }

        return $date;
    }

    /**
     * @return array<string, bool>
     *
     * @throws UnexpectedValueException when the value is not a JSON object of booleans
     */
    private static function ids(mixed $value, string $key): array
    {
        if (! is_string($value)) {
            throw self::corrupt($key);
        }
        try {
            $decoded = json_decode($value, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::corrupt($key);
        }
        if (! is_array($decoded)) {
            throw self::corrupt($key);
        }
        $ids = [];
        foreach ($decoded as $id => $acknowledged) {
            if (! is_bool($acknowledged)) {
                throw self::corrupt($key);
            }
            $ids[(string) $id] = $acknowledged;
        }

        return $ids;
    }

    private static function corrupt(string $key): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('The KSeF cursor stored in %s under "%s" cannot be read; delete the row to read the inbox again from its start.', self::TABLE, $key));
    }
}

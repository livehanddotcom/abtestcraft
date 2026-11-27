<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;

/**
 * Small key/value store for plugin state that isn't a setting, such as when
 * the weekly summary was last sent (table abtestcraft_state)
 */
class StateService extends Component
{
    private const TABLE = '{{%abtestcraft_state}}';

    public function get(string $key): ?string
    {
        $value = (new Query())->select(['value'])->from(self::TABLE)->where(['key' => $key])->scalar();

        return $value === false || $value === null ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        // Audit columns set explicitly, so this doesn't depend on the
        // connection using Craft's command class (which adds them)
        $now = Db::prepareDateForDb(new DateTime());
        Db::upsert(
            self::TABLE,
            [
                'key' => $key,
                'value' => $value,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ],
            ['value' => $value, 'dateUpdated' => $now],
            [],
            false
        );
    }
}

<?php

declare(strict_types=1);

namespace livehand\abtestcraft\records;

use craft\db\ActiveRecord;

/**
 * Rate limit record for database-based rate limiting
 *
 * Stores request counts per cache key for multi-server rate limiting.
 * Each record tracks requests within a sliding time window.
 *
 * @property int $id
 * @property string $cacheKey
 * @property int $requestCount
 * @property \DateTime $windowStart
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class RateLimitRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%abtestcraft_rate_limits}}';
    }
}

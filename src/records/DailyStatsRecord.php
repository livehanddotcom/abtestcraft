<?php

declare(strict_types=1);

namespace livehand\abtestcraft\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Daily stats record
 *
 * @property int $id
 * @property int $testId
 * @property string $date
 * @property string $variant
 * @property int $impressions
 * @property int $conversions
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class DailyStatsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%abtestcraft_daily_stats}}';
    }

    public function getTest(): ActiveQueryInterface
    {
        return $this->hasOne(TestRecord::class, ['id' => 'testId']);
    }
}

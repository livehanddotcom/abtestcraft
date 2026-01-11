<?php

declare(strict_types=1);

namespace livehand\abtestcraft\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use craft\records\Site;
use yii\db\ActiveQueryInterface;

/**
 * Test record
 *
 * @property int $id
 * @property int $siteId
 * @property string $name
 * @property string $handle
 * @property string|null $hypothesis
 * @property string|null $variantDescription
 * @property string|null $learnings
 * @property string $status
 * @property int $controlEntryId
 * @property int $variantEntryId
 * @property int $trafficSplit
 * @property string $goalType
 * @property string|null $goalValue
 * @property \DateTime|null $startedAt
 * @property \DateTime|null $endedAt
 * @property string|null $winnerVariant
 * @property \DateTime|null $significanceNotifiedAt
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property \DateTime|null $dateDeleted
 * @property string $uid
 */
class TestRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%abtestcraft_tests}}';
    }

    public function getSite(): ActiveQueryInterface
    {
        return $this->hasOne(Site::class, ['id' => 'siteId']);
    }

    public function getControlEntry(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'controlEntryId']);
    }

    public function getVariantEntry(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'variantEntryId']);
    }

    public function getVisitors(): ActiveQueryInterface
    {
        return $this->hasMany(VisitorRecord::class, ['testId' => 'id']);
    }

    public function getDailyStats(): ActiveQueryInterface
    {
        return $this->hasMany(DailyStatsRecord::class, ['testId' => 'id']);
    }
}

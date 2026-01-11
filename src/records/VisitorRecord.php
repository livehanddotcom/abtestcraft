<?php

declare(strict_types=1);

namespace livehand\abtestcraft\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Visitor record
 *
 * @property int $id
 * @property int $testId
 * @property string $visitorId
 * @property string $variant
 * @property bool $converted
 * @property string|null $conversionType
 * @property \DateTime|null $dateConverted
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class VisitorRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%abtestcraft_visitors}}';
    }

    public function getTest(): ActiveQueryInterface
    {
        return $this->hasOne(TestRecord::class, ['id' => 'testId']);
    }
}

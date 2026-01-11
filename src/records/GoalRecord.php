<?php

declare(strict_types=1);

namespace livehand\abtestcraft\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Goal record
 *
 * @property int $id
 * @property int $testId
 * @property string $goalType
 * @property bool $isEnabled
 * @property array|null $config
 * @property int|null $sortOrder
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class GoalRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%abtestcraft_goals}}';
    }

    public function getTest(): ActiveQueryInterface
    {
        return $this->hasOne(TestRecord::class, ['id' => 'testId']);
    }
}

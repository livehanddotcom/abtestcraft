<?php

declare(strict_types=1);

namespace livehand\abtestcraft\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use craft\records\Site;
use yii\db\ActiveQueryInterface;

/**
 * Test Descendant record - tracks cascade relationships for nested entries
 *
 * @property int $id
 * @property int $testId
 * @property int $controlEntryId
 * @property int $descendantEntryId
 * @property int $variantAncestorId
 * @property int $depth
 * @property int $siteId
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class TestDescendantRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%abtestcraft_test_descendants}}';
    }

    public function getTest(): ActiveQueryInterface
    {
        return $this->hasOne(TestRecord::class, ['id' => 'testId']);
    }

    public function getControlEntry(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'controlEntryId']);
    }

    public function getDescendantEntry(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'descendantEntryId']);
    }

    public function getVariantAncestor(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'variantAncestorId']);
    }

    public function getSite(): ActiveQueryInterface
    {
        return $this->hasOne(Site::class, ['id' => 'siteId']);
    }
}

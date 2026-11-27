<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;

/**
 * Adds abtestcraft_visitor_goals: one row per counted conversion slot, so
 * conversion de-duplication is atomic and works per goal type.
 *
 * Before this, "one conversion per goal type" was checked against the single
 * visitor row, whose conversionType is overwritten on each conversion, so a
 * visitor alternating goal types (phone, email, phone...) was counted again
 * and again. Existing conversions are backfilled so already-converted
 * visitors stay de-duplicated.
 */
class m261125_000002_add_visitor_goals_table extends Migration
{
    private const TABLE = '{{%abtestcraft_visitor_goals}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            $this->createTable(self::TABLE, [
                'id' => $this->primaryKey(),
                'testId' => $this->integer()->notNull(),
                'visitorId' => $this->string(36)->notNull(),
                'goalType' => $this->string(50)->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::TABLE, ['testId', 'visitorId', 'goalType'], true);
            $this->addForeignKey(
                null,
                self::TABLE,
                ['testId'],
                '{{%abtestcraft_tests}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }

        // Backfill from converted visitors: their last goal type, plus the
        // '*' slot used by first-conversion-only mode
        $now = Db::prepareDateForDb(new DateTime());
        $query = (new Query())
            ->select(['testId', 'visitorId', 'conversionType'])
            ->from('{{%abtestcraft_visitors}}')
            ->where(['not', ['dateConverted' => null]]);

        foreach ($query->batch(500, $this->db) as $rows) {
            $insert = [];
            foreach ($rows as $row) {
                foreach (array_filter([$row['conversionType'], '*']) as $goalType) {
                    $insert[] = [
                        (int) $row['testId'],
                        $row['visitorId'],
                        $goalType,
                        $now,
                        $now,
                        StringHelper::UUID(),
                    ];
                }
            }
            if ($insert) {
                $columns = ['testId', 'visitorId', 'goalType', 'dateCreated', 'dateUpdated', 'uid'];
                $this->batchInsert(self::TABLE, $columns, $insert);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }
}

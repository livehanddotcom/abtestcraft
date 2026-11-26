<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;

/**
 * Per-visitor results (1.2.0).
 *
 * Results used to divide conversions (summed across goal types) by
 * impressions (every render of a tested page). They're now based on
 * visitors: the share of assigned visitors who converted on the test's
 * primary goal. This adds:
 *
 * - `abtestcraft_daily_stats.visitors`: visitors assigned that day (on the
 *   goalType-null row), so visitor counts survive `cleanup`
 * - `abtestcraft_daily_stats.convertedVisitors`: visitors whose first
 *   conversion of that goal type (or of any type, on the null row) happened
 *   that day
 * - `abtestcraft_tests.primaryGoalType`: the goal results are judged on;
 *   null means any goal
 *
 * and backfills both counts from the existing visitor records. Visitor goal
 * slots are completed too, so every converted visitor has a row for its goal
 * type and for '*' (any goal), whatever counting mode was in use.
 *
 * Visitor records already deleted by `cleanup` can't be counted; those tests'
 * page views and raw conversion totals are unaffected.
 */
class m261126_000001_per_visitor_stats extends Migration
{
    private const STATS = '{{%abtestcraft_daily_stats}}';
    private const VISITORS = '{{%abtestcraft_visitors}}';
    private const GOALS = '{{%abtestcraft_visitor_goals}}';
    private const TESTS = '{{%abtestcraft_tests}}';
    private const ANY = '*';

    private DateTimeZone $utc;
    private DateTimeZone $siteZone;

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::STATS, 'visitors')) {
            $column = $this->integer()->notNull()->defaultValue(0)->after('conversions');
            $this->addColumn(self::STATS, 'visitors', $column);
        }
        if (!$this->db->columnExists(self::STATS, 'convertedVisitors')) {
            $column = $this->integer()->notNull()->defaultValue(0)->after('visitors');
            $this->addColumn(self::STATS, 'convertedVisitors', $column);
        }
        if (!$this->db->columnExists(self::TESTS, 'primaryGoalType')) {
            $this->addColumn(self::TESTS, 'primaryGoalType', $this->string(50)->null()->after('goalValue'));
        }

        // Craft stores dates in UTC; daily stats are bucketed by the site's date
        $this->utc = new DateTimeZone('UTC');
        $this->siteZone = new DateTimeZone(Craft::$app->getTimeZone());

        // Postgres plans the per-batch visitor goal lookups below from table
        // statistics. Upgrading from 1.0, the visitor goals table was created
        // and filled by the previous migration moments ago and has none yet,
        // so every lookup would scan the whole table: hours on large sites.
        if ($this->db->getIsPgsql()) {
            $this->execute('ANALYZE {{%abtestcraft_visitor_goals}}');
            $this->execute('ANALYZE {{%abtestcraft_visitors}}');
        }

        $testIds = (new Query())->select(['id'])->from(self::TESTS)->column($this->db);
        foreach ($testIds as $testId) {
            $this->backfillTest((int) $testId);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn(self::STATS, 'convertedVisitors');
        $this->dropColumn(self::STATS, 'visitors');
        $this->dropColumn(self::TESTS, 'primaryGoalType');
        return true;
    }

    private function backfillTest(int $testId): void
    {
        // [date][variant][goalKey] => ['visitors' => n, 'convertedVisitors' => n]
        $counts = [];
        $now = Db::prepareDateForDb(new DateTime());

        $visitors = (new Query())
            ->select(['visitorId', 'variant', 'dateCreated', 'conversionType', 'dateConverted'])
            ->from(self::VISITORS)
            ->where(['testId' => $testId])
            ->orderBy(['id' => SORT_ASC]);

        foreach ($visitors->batch(500, $this->db) as $rows) {
            $slots = $this->loadSlots($testId, array_column($rows, 'visitorId'));
            $newSlots = [];

            foreach ($rows as $row) {
                $variant = $row['variant'];
                $assignedAt = $row['dateCreated'];
                $this->add($counts, $this->siteDate($assignedAt), $variant, self::ANY, 'visitors');

                $visitorSlots = $slots[$row['visitorId']] ?? [];

                // Converted visitors missing their goal-type slot (first-only or
                // unlimited mode) get one for their recorded conversion type
                if ($row['dateConverted'] !== null && $row['conversionType']) {
                    $type = $row['conversionType'];
                    if (!isset($visitorSlots[$type])) {
                        $visitorSlots[$type] = $row['dateConverted'];
                        $newSlots[] = [$row['visitorId'], $type, $row['dateConverted']];
                    }
                }

                $goalTypes = array_diff_key($visitorSlots, [self::ANY => true]);
                if ($goalTypes === []) {
                    continue;
                }

                // Rows backfilled in 1.0.1 are dated at that upgrade, so use the
                // earlier recorded conversion date where there is one
                foreach ($goalTypes as $type => $claimedAt) {
                    $recordedAt = $type === $row['conversionType'] ? $row['dateConverted'] : null;
                    $date = $this->conversionDate($claimedAt, $recordedAt, $assignedAt);
                    $goalTypes[$type] = $date;
                    $this->add($counts, $this->siteDate($date), $variant, (string) $type, 'convertedVisitors');
                }

                // Any goal: the visitor's first conversion
                $dates = array_values($goalTypes);
                if (isset($visitorSlots[self::ANY])) {
                    $dates[] = $this->conversionDate($visitorSlots[self::ANY], null, $assignedAt);
                }
                $firstAt = min($dates);
                $this->add($counts, $this->siteDate($firstAt), $variant, self::ANY, 'convertedVisitors');
                if (!isset($visitorSlots[self::ANY])) {
                    $newSlots[] = [$row['visitorId'], self::ANY, $firstAt];
                }
            }

            if ($newSlots) {
                $insert = array_map(fn(array $slot) => [
                    $testId,
                    $slot[0],
                    $slot[1],
                    Db::prepareDateForDb(new DateTime($slot[2], $this->utc)),
                    $now,
                    StringHelper::UUID(),
                ], $newSlots);
                $columns = ['testId', 'visitorId', 'goalType', 'dateCreated', 'dateUpdated', 'uid'];
                $this->batchInsert(self::GOALS, $columns, $insert, false);
            }
        }

        // Replace (not add to) the counts, so running this again is harmless
        $this->update(self::STATS, ['visitors' => 0, 'convertedVisitors' => 0], ['testId' => $testId], [], false);

        foreach ($counts as $date => $variants) {
            foreach ($variants as $variant => $goals) {
                foreach ($goals as $goalKey => $values) {
                    $goalType = $goalKey === self::ANY ? null : (string) $goalKey;
                    $this->writeCounts($testId, $date, $variant, $goalType, $values, $now);
                }
            }
        }
    }

    /**
     * Existing visitor goal slots for a batch of visitors
     *
     * @param string[] $visitorIds
     * @return array<string, array<string, string>> visitorId => [goalType => dateCreated]
     */
    private function loadSlots(int $testId, array $visitorIds): array
    {
        $slots = [];
        $rows = (new Query())
            ->select(['visitorId', 'goalType', 'dateCreated'])
            ->from(self::GOALS)
            ->where(['testId' => $testId, 'visitorId' => $visitorIds])
            ->all($this->db);

        foreach ($rows as $row) {
            $slots[$row['visitorId']][$row['goalType']] = $row['dateCreated'];
        }

        return $slots;
    }

    /**
     * The earliest known date of a conversion, never before the assignment
     */
    private function conversionDate(string $claimedAt, ?string $convertedAt, string $assignedAt): string
    {
        $date = $convertedAt !== null && $convertedAt < $claimedAt ? $convertedAt : $claimedAt;

        return $date < $assignedAt ? $assignedAt : $date;
    }

    private function siteDate(string $utcDate): string
    {
        return (new DateTime($utcDate, $this->utc))->setTimezone($this->siteZone)->format('Y-m-d');
    }

    private function add(array &$counts, string $date, string $variant, string $goalKey, string $column): void
    {
        $counts[$date][$variant][$goalKey][$column] = ($counts[$date][$variant][$goalKey][$column] ?? 0) + 1;
    }

    private function writeCounts(
        int $testId,
        string $date,
        string $variant,
        ?string $goalType,
        array $values,
        string $now,
    ): void {
        $columns = [
            'visitors' => $values['visitors'] ?? 0,
            'convertedVisitors' => $values['convertedVisitors'] ?? 0,
        ];
        $condition = ['testId' => $testId, 'date' => $date, 'variant' => $variant, 'goalType' => $goalType];

        $exists = (new Query())->from(self::STATS)->where($condition)->exists($this->db);
        if ($exists) {
            $this->update(self::STATS, $columns, $condition, [], false);
            return;
        }

        $this->insert(self::STATS, $condition + $columns + [
            'impressions' => 0,
            'conversions' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);
    }
}

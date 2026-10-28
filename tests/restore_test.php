<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Availability cohort - Tests for backup and restore
 *
 * @package     availability_cohort
 * @copyright   2026 Alexander Bias <bias@alexanderbias.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace availability_cohort;

/**
 * Unit tests for the backup and restore of cohort conditions.
 *
 * @package     availability_cohort
 * @copyright   2026 Alexander Bias <bias@alexanderbias.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \availability_cohort\condition::include_after_restore
 * @covers      \availability_cohort\condition::update_after_restore
 */
final class restore_test extends \advanced_testcase {
    /** @var string[] The warnings which were added to the log of the last restore */
    protected $restorewarnings = [];

    /**
     * Load required libraries.
     */
    public function setUp(): void {
        global $CFG;

        $this->resetAfterTest();

        // We need the backup and cohort libraries.
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/cohort/lib.php');

        // Enable availability restrictions.
        $CFG->enableavailability = true;

        parent::setUp();
    }

    /**
     * Data provider which runs each test once with the cleanup setting enabled and once with it disabled.
     *
     * @return array[]
     */
    public static function cleanup_enabled_provider(): array {
        return [
            'Cleanup enabled' => [true],
            'Cleanup disabled' => [false],
        ];
    }

    /**
     * Test that an "any cohort" condition is kept when restoring on the same site.
     *
     * @dataProvider cleanup_enabled_provider
     * @param bool $cleanupenabled Whether the cleanup setting is enabled.
     */
    public function test_anycohort_is_kept_on_same_site(bool $cleanupenabled): void {
        set_config('cleanuponrestore', $cleanupenabled ? 1 : 0, 'availability_cohort');
        $this->setAdminUser();

        $this->getDataGenerator()->create_cohort();
        $course = $this->create_course_with_cohort_condition(0);

        $conditions = $this->backup_and_restore($course);

        $this->assertEquals([condition::get_json(0)], $conditions);
    }

    /**
     * Test that an "any cohort" condition is kept when restoring on another site.
     *
     * @dataProvider cleanup_enabled_provider
     * @param bool $cleanupenabled Whether the cleanup setting is enabled.
     */
    public function test_anycohort_is_kept_on_other_site(bool $cleanupenabled): void {
        set_config('cleanuponrestore', $cleanupenabled ? 1 : 0, 'availability_cohort');
        $this->setAdminUser();

        $course = $this->create_course_with_cohort_condition(0);

        $conditions = $this->backup_and_restore($course, true);

        $this->assertEquals([condition::get_json(0)], $conditions);
    }

    /**
     * Test that a condition on an existing system cohort is kept when restoring on the same site.
     *
     * @dataProvider cleanup_enabled_provider
     * @param bool $cleanupenabled Whether the cleanup setting is enabled.
     */
    public function test_existing_cohort_is_kept_on_same_site(bool $cleanupenabled): void {
        set_config('cleanuponrestore', $cleanupenabled ? 1 : 0, 'availability_cohort');
        $this->setAdminUser();

        $cohort = $this->getDataGenerator()->create_cohort();
        $course = $this->create_course_with_cohort_condition($cohort->id);

        $conditions = $this->backup_and_restore($course);

        $this->assertEquals([condition::get_json($cohort->id)], $conditions);
    }

    /**
     * Test that a condition on a hidden cohort in an unrelated category is kept when a teacher
     * without the capability to view this cohort restores the course on the same site.
     *
     * @dataProvider cleanup_enabled_provider
     * @param bool $cleanupenabled Whether the cleanup setting is enabled.
     */
    public function test_hidden_cohort_is_kept_when_teacher_restores(bool $cleanupenabled): void {
        set_config('cleanuponrestore', $cleanupenabled ? 1 : 0, 'availability_cohort');
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $cohort = $generator->create_cohort([
            'contextid' => \context_coursecat::instance($category->id)->id,
            'visible' => 0,
        ]);
        $course = $this->create_course_with_cohort_condition($cohort->id);
        $targetcourse = $generator->create_course();

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($teacher->id, $targetcourse->id, 'editingteacher');
        $this->setUser($teacher);

        $conditions = $this->backup_and_restore($course, false, null, $targetcourse);

        $this->assertEquals([condition::get_json($cohort->id)], $conditions);
    }

    /**
     * Test the handling of a condition on a cohort which was deleted between backup and restore on the same site.
     *
     * @dataProvider cleanup_enabled_provider
     * @param bool $cleanupenabled Whether the cleanup setting is enabled.
     */
    public function test_deleted_cohort_on_same_site(bool $cleanupenabled): void {
        set_config('cleanuponrestore', $cleanupenabled ? 1 : 0, 'availability_cohort');
        $this->setAdminUser();

        $cohort = $this->getDataGenerator()->create_cohort();
        $course = $this->create_course_with_cohort_condition($cohort->id);

        $conditions = $this->backup_and_restore($course, false, function () use ($cohort) {
            cohort_delete_cohort($cohort);
        });

        if ($cleanupenabled) {
            // The orphaned condition is removed and a warning is logged.
            $this->assertEquals([], $conditions);
            $this->assertEquals(1, $this->count_restore_warnings('the condition was removed'));
        } else {
            // The orphaned condition is kept as it is and a warning is logged.
            $this->assertEquals([condition::get_json($cohort->id)], $conditions);
            $this->assertEquals(1, $this->count_restore_warnings('the condition can not be fulfilled'));
        }
    }

    /**
     * Test the handling of a condition on a particular cohort when restoring on another site.
     *
     * @dataProvider cleanup_enabled_provider
     * @param bool $cleanupenabled Whether the cleanup setting is enabled.
     */
    public function test_cohort_on_other_site(bool $cleanupenabled): void {
        set_config('cleanuponrestore', $cleanupenabled ? 1 : 0, 'availability_cohort');
        $this->setAdminUser();

        // The cohort exists on this site, but must not be used as the backup is treated as coming from another site.
        $cohort = $this->getDataGenerator()->create_cohort();
        $course = $this->create_course_with_cohort_condition($cohort->id);

        $conditions = $this->backup_and_restore($course, true);

        if ($cleanupenabled) {
            // The unresolvable condition is removed and a warning is logged.
            $this->assertEquals([], $conditions);
            $this->assertEquals(1, $this->count_restore_warnings('the condition was removed'));
        } else {
            // The unresolvable condition is kept, but with an invalid cohort id, and a warning is logged once.
            $this->assertEquals([condition::get_json(-1)], $conditions);
            $this->assertEquals(1, $this->count_restore_warnings('the condition can not be fulfilled'));
        }
    }

    /**
     * Create a course with a page which is restricted by a cohort condition.
     *
     * @param int $cohortid The cohort id of the condition (0 = any cohort).
     * @return \stdClass The course.
     */
    protected function create_course_with_cohort_condition(int $cohortid): \stdClass {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $tree = \core_availability\tree::get_root_json([condition::get_json($cohortid)]);
        $generator->create_module('page', ['course' => $course->id, 'availability' => json_encode($tree)]);
        return $course;
    }

    /**
     * Back up the given course, restore it into another course and return the cohort conditions of the restored page.
     *
     * @param \stdClass $course The course to back up.
     * @param bool $othersite Whether the backup should be restored as if it came from another site.
     * @param callable|null $beforerestore Callback which is executed between backup and restore.
     * @param \stdClass|null $targetcourse The course to restore into (a new course is created if not given).
     * @return array The cohort conditions of the restored page.
     */
    protected function backup_and_restore(
        \stdClass $course,
        bool $othersite = false,
        ?callable $beforerestore = null,
        ?\stdClass $targetcourse = null
    ): array {
        global $DB, $USER;

        if ($targetcourse === null) {
            $targetcourse = $this->getDataGenerator()->create_course();
        }

        // Back up the course.
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        if ($beforerestore !== null) {
            $beforerestore();
        }

        // Simulate another site by changing the site identifier which the backup is compared against.
        if ($othersite) {
            set_config('siteidentifier', random_string(32) . 'othersite');
        }

        // Restore the course.
        $rc = new \restore_controller(
            $backupid,
            $targetcourse->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $restoreid = $rc->get_restoreid();
        $rc->destroy();

        // Remember the warnings of the restore log.
        $this->restorewarnings = $DB->get_fieldset(
            'backup_logs',
            'message',
            ['backupid' => $restoreid, 'loglevel' => \backup::LOG_WARNING]
        );

        // Get the cohort conditions of the restored page.
        $availability = $DB->get_field_sql(
            'SELECT cm.availability
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND m.name = :modname',
            ['courseid' => $targetcourse->id, 'modname' => 'page'],
            MUST_EXIST
        );
        if (empty($availability)) {
            return [];
        }
        $tree = json_decode($availability);
        return array_values(array_filter($tree->c, fn($child) => ($child->type ?? '') === 'cohort'));
    }

    /**
     * Count the warnings of the last restore log which contain the given text.
     *
     * @param string $text The text to look for.
     * @return int The number of matching warnings.
     */
    protected function count_restore_warnings(string $text): int {
        return count(array_filter($this->restorewarnings, fn($message) => str_contains($message, $text)));
    }
}

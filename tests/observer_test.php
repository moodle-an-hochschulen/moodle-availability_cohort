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
 * Availability cohort - Tests for the event observer
 *
 * @package     availability_cohort
 * @copyright   2026 Moodle an Hochschulen e.V. <kontakt@moodle-an-hochschulen.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace availability_cohort;

/**
 * Unit tests for the event observer.
 *
 * @package     availability_cohort
 * @copyright   2026 Moodle an Hochschulen e.V. <kontakt@moodle-an-hochschulen.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \availability_cohort\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Load required libraries.
     */
    public function setUp(): void {
        global $CFG;

        $this->resetAfterTest();

        // We need the cohort library to be able to delete cohorts.
        require_once($CFG->dirroot . '/cohort/lib.php');

        parent::setUp();
    }

    /**
     * Data provider which runs each test once with the cleanup kill switch enabled and once with it disabled.
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
     * Tests that a restriction which only requires the deleted cohort is removed completely (if the cleanup is enabled).
     *
     * @dataProvider cleanup_enabled_provider
     * @covers \availability_cohort\observer::cohort_deleted
     * @param bool $cleanupenabled Whether the cleanup kill switch is enabled.
     */
    public function test_only_cohort_condition_is_removed(bool $cleanupenabled): void {
        // Set the kill switch.
        $this->set_cleanup($cleanupenabled);

        // Create the necessary data assets.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $cohort = $generator->create_cohort();

        // Restrict the activity to the cohort only.
        $structure = \core_availability\tree::get_root_json([condition::get_json($cohort->id)]);
        $this->set_availability($page->cmid, $course->id, $structure);

        // Delete the cohort.
        cohort_delete_cohort($cohort);

        if ($cleanupenabled) {
            // The whole restriction should be gone now.
            $this->assertNull($this->get_availability($page->cmid));
        } else {
            // The restriction should still be in place.
            $this->assert_availability_unchanged($page->cmid, $structure);
        }
    }

    /**
     * Tests that only the cohort condition is removed while other conditions and the parallel showc array are kept.
     *
     * @dataProvider cleanup_enabled_provider
     * @covers \availability_cohort\observer::cohort_deleted
     * @param bool $cleanupenabled Whether the cleanup kill switch is enabled.
     */
    public function test_cohort_condition_is_removed_but_others_are_kept(bool $cleanupenabled): void {
        // Set the kill switch.
        $this->set_cleanup($cleanupenabled);

        // Create the necessary data assets.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $cohort = $generator->create_cohort();

        // Restrict the activity to the cohort AND a date, using an explicit (asymmetric) showc array.
        $datecondition = \availability_date\condition::get_json('>=', time());
        $structure = \core_availability\tree::get_root_json(
            [condition::get_json($cohort->id), $datecondition],
            \core_availability\tree::OP_AND,
            [false, true]
        );
        $this->set_availability($page->cmid, $course->id, $structure);

        // Delete the cohort.
        cohort_delete_cohort($cohort);

        if ($cleanupenabled) {
            // The date condition should remain, with its showc entry kept in sync.
            $tree = json_decode($this->get_availability($page->cmid));
            $this->assertNotNull($tree);
            $this->assertCount(1, $tree->c);
            $this->assertEquals('date', $tree->c[0]->type);
            $this->assertEquals([true], $tree->showc);
        } else {
            // The whole restriction should still be in place.
            $this->assert_availability_unchanged($page->cmid, $structure);
        }
    }

    /**
     * Tests that a restriction referring to a different cohort or to 'any cohort' is left untouched.
     *
     * @dataProvider cleanup_enabled_provider
     * @covers \availability_cohort\observer::cohort_deleted
     * @param bool $cleanupenabled Whether the cleanup kill switch is enabled.
     */
    public function test_unrelated_conditions_are_untouched(bool $cleanupenabled): void {
        // Set the kill switch.
        $this->set_cleanup($cleanupenabled);

        // Create the necessary data assets.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $othercohortpage = $generator->create_module('page', ['course' => $course->id]);
        $anycohortpage = $generator->create_module('page', ['course' => $course->id]);
        $cohort = $generator->create_cohort();
        $othercohort = $generator->create_cohort();

        // One activity restricted to another cohort, one activity restricted to 'any cohort'.
        $othercohortstructure = \core_availability\tree::get_root_json([condition::get_json($othercohort->id)]);
        $this->set_availability($othercohortpage->cmid, $course->id, $othercohortstructure);
        $anycohortstructure = \core_availability\tree::get_root_json([condition::get_json(0)]);
        $this->set_availability($anycohortpage->cmid, $course->id, $anycohortstructure);

        // Delete the first cohort.
        cohort_delete_cohort($cohort);

        // Both restrictions must still be in place, regardless of whether the cleanup is enabled, as neither of them
        // requires the deleted cohort.
        $this->assert_availability_unchanged($othercohortpage->cmid, $othercohortstructure);
        $this->assert_availability_unchanged($anycohortpage->cmid, $anycohortstructure);
    }

    /**
     * Tests that the cohort condition is also removed from a nested subtree and that an emptied subtree is dropped.
     *
     * @dataProvider cleanup_enabled_provider
     * @covers \availability_cohort\observer::cohort_deleted
     * @param bool $cleanupenabled Whether the cleanup kill switch is enabled.
     */
    public function test_cohort_condition_is_removed_from_nested_subtree(bool $cleanupenabled): void {
        // Set the kill switch.
        $this->set_cleanup($cleanupenabled);

        // Create the necessary data assets.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $mixedpage = $generator->create_module('page', ['course' => $course->id]);
        $emptypage = $generator->create_module('page', ['course' => $course->id]);
        $cohort = $generator->create_cohort();

        // Case 1: A date condition AND a nested OR subtree which requires the cohort or a date.
        $datecondition = \availability_date\condition::get_json('>=', time());
        $nested = \core_availability\tree::get_nested_json(
            [condition::get_json($cohort->id), $datecondition],
            \core_availability\tree::OP_OR
        );
        $mixedstructure = \core_availability\tree::get_root_json([$datecondition, $nested]);
        $this->set_availability($mixedpage->cmid, $course->id, $mixedstructure);

        // Case 2: A nested subtree which only requires the cohort, so the whole tree becomes empty afterwards.
        $emptynested = \core_availability\tree::get_nested_json([condition::get_json($cohort->id)]);
        $emptystructure = \core_availability\tree::get_root_json([$emptynested]);
        $this->set_availability($emptypage->cmid, $course->id, $emptystructure);

        // Delete the cohort.
        cohort_delete_cohort($cohort);

        if ($cleanupenabled) {
            // Case 1: The nested subtree should now hold only the date condition, the root should still hold two children.
            $mixedtree = json_decode($this->get_availability($mixedpage->cmid));
            $this->assertCount(2, $mixedtree->c);
            $this->assertCount(1, $mixedtree->c[1]->c);
            $this->assertEquals('date', $mixedtree->c[1]->c[0]->type);

            // Case 2: The emptied subtree should have been dropped, leaving no restriction at all.
            $this->assertNull($this->get_availability($emptypage->cmid));
        } else {
            // Both restrictions should still be in place.
            $this->assert_availability_unchanged($mixedpage->cmid, $mixedstructure);
            $this->assert_availability_unchanged($emptypage->cmid, $emptystructure);
        }
    }

    /**
     * Enables or disables the cleanup kill switch on the plugin settings.
     *
     * @param bool $cleanupenabled Whether the cleanup should be enabled.
     */
    protected function set_cleanup(bool $cleanupenabled): void {
        set_config('cleanuponcohortdeletion', $cleanupenabled ? 1 : 0, 'availability_cohort');
    }

    /**
     * Sets the availability restriction of a course module and rebuilds the course cache.
     *
     * @param int $cmid The course module ID.
     * @param int $courseid The course ID.
     * @param \stdClass $structure The availability tree structure.
     */
    protected function set_availability(int $cmid, int $courseid, \stdClass $structure): void {
        global $DB;

        $DB->set_field('course_modules', 'availability', json_encode($structure), ['id' => $cmid]);
        rebuild_course_cache($courseid, true);
    }

    /**
     * Returns the raw availability restriction of a course module directly from the database.
     *
     * @param int $cmid The course module ID.
     * @return string|null The availability JSON or null.
     */
    protected function get_availability(int $cmid): ?string {
        global $DB;

        return $DB->get_field('course_modules', 'availability', ['id' => $cmid]);
    }

    /**
     * Asserts that the availability restriction of a course module still equals the given (unchanged) structure.
     *
     * @param int $cmid The course module ID.
     * @param \stdClass $structure The availability tree structure which was originally set.
     */
    protected function assert_availability_unchanged(int $cmid, \stdClass $structure): void {
        $this->assertEquals(json_encode($structure), $this->get_availability($cmid));
    }
}

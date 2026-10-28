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
 * Availability cohort - Tests for cohort restrictions
 *
 * @package     availability_cohort
 * @copyright   2018 Ulm University <kathrin.osswald@uni-ulm.de>
 *              based on code of availability_group 2014 The Open University
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace availability_cohort;

/**
 * Unit tests for the condition.
 *
 * @package     availability_cohort
 * @copyright   2018 Ulm University <kathrin.osswald@uni-ulm.de>
 *              based on code of availability_group 2014 The Open University
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class condition_test extends \advanced_testcase {
    /**
     * Load required classes.
     */
    public function setUp(): void {
        global $CFG;

        // Load the mock info class so that it can be used.
        require_once($CFG->dirroot . '/availability/tests/fixtures/mock_info.php');

        // Call parent setup.
        parent::setUp();
    }

    /**
     * Tests constructing and using condition.
     *
     * @covers \availability_cohort\condition::is_available()
     * @covers \availability_cohort\condition::get_description()
     */
    public function test_usage(): void {
        global $CFG, $USER;
        $this->resetAfterTest();
        $CFG->enableavailability = true;

        // Erase static cache before test.
        condition::wipe_static_cache();

        // Make a test course and user.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);
        $info = new \core_availability\mock_info($course, $user->id);

        // Make two cohorts.
        $cohort1 = $generator->create_cohort(['idnumber' => 1, 'name' => 'Cohort 1']);
        $cohort2 = $generator->create_cohort(['idnumber' => 2, 'name' => 'Cohort 2']);

        // Do test (not in cohort).
        $cond = new condition((object)['id' => (int)$cohort1->id]);

        // Check if available (when not available).
        $this->assertFalse($cond->is_available(false, $info, true, $user->id));
        $information = $cond->get_description(false, false, $info);
        $information = \core_availability\info::format_info($information, $course);
        $this->assertMatchesRegularExpression('~You belong to .*<strong>Cohort 1</strong>~', $information);
        $this->assertTrue($cond->is_available(true, $info, true, $user->id));

        // Add user to cohorts and refresh cache.
        cohort_add_member($cohort1->id, $user->id);
        cohort_add_member($cohort2->id, $user->id);
        get_fast_modinfo($course->id, 0, true);

        // Recheck.
        $this->assertTrue($cond->is_available(false, $info, true, $user->id));
        $this->assertFalse($cond->is_available(true, $info, true, $user->id));
        $information = $cond->get_description(false, true, $info);
        $information = \core_availability\info::format_info($information, $course);
        $this->assertMatchesRegularExpression('~do <strong>not</strong> belong to .*<strong>Cohort 1</strong>~', $information);

        // Check cohort 2 works also.
        $cond = new condition((object)['id' => (int)$cohort2->id]);
        $this->assertTrue($cond->is_available(false, $info, true, $user->id));

        // What about an 'any cohort' condition?
        $cond = new condition((object)[]);
        $this->assertTrue($cond->is_available(false, $info, true, $user->id));
        $this->assertFalse($cond->is_available(true, $info, true, $user->id));
        $information = $cond->get_description(false, true, $info);
        $this->assertMatchesRegularExpression('~do <strong>not</strong> belong to <strong>any cohort</strong>~', $information);

        // Cohort that doesn't exist uses 'missing' text.
        $cond = new condition((object)['id' => $cohort2->id + 1000]);
        $this->assertFalse($cond->is_available(false, $info, true, $user->id));
        $information = $cond->get_description(false, false, $info);
        $information = \core_availability\info::format_info($information, $course);
        $this->assertMatchesRegularExpression('~You belong to.*\(Missing cohort\)~', $information);
    }

    /**
     * Tests that an 'any cohort' condition is evaluated against the course of the checked item
     * and not against the course in the $COURSE global.
     *
     * @covers \availability_cohort\condition::is_available()
     */
    public function test_any_cohort_uses_course_of_item(): void {
        global $CFG, $COURSE;
        $this->resetAfterTest();
        $CFG->enableavailability = true;

        // Make two categories with a course each and a user.
        $generator = $this->getDataGenerator();
        $categorya = $generator->create_category();
        $categoryb = $generator->create_category();
        $coursea = $generator->create_course(['category' => $categorya->id]);
        $courseb = $generator->create_course(['category' => $categoryb->id]);
        $user = $generator->create_user();
        $infoa = new \core_availability\mock_info($coursea, $user->id);
        $infob = new \core_availability\mock_info($courseb, $user->id);

        // Make a cohort in category A and add the user to it.
        $cohort = $generator->create_cohort(['contextid' => \context_coursecat::instance($categorya->id)->id]);
        cohort_add_member($cohort->id, $user->id);

        $cond = new condition((object)[]);

        // Check with $COURSE being the site course (e.g. on the Dashboard or in cron).
        $COURSE = get_site();
        $this->assertTrue($cond->is_available(false, $infoa, false, $user->id));
        $this->assertFalse($cond->is_available(false, $infob, false, $user->id));

        // Check with $COURSE being a course in another category.
        $COURSE = $courseb;
        $this->assertTrue($cond->is_available(false, $infoa, false, $user->id));
        $COURSE = $coursea;
        $this->assertFalse($cond->is_available(false, $infob, false, $user->id));
    }

    /**
     * Tests the filter_user_list (bulk checking) function. Also tests the SQL
     * variant get_user_list_sql.
     *
     * @covers \availability_cohort\condition::is_applied_to_user_lists()
     * @covers \availability_cohort\condition::filter_user_list()
     * @covers \availability_cohort\condition::get_user_list_sql()
     */
    public function test_filter_users(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $CFG->enableavailability = true;

        // Make a test course in a category and some users.
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $othercategory = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $roleids = $DB->get_records_menu('role', null, '', 'shortname, id');
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, $roleids['editingteacher']);
        $allusers = [$teacher->id => $teacher];
        $students = [];
        for ($i = 0; $i < 4; $i++) {
            $student = $generator->create_user();
            $students[$i] = $student;
            $generator->enrol_user($student->id, $course->id, $roleids['student']);
            $allusers[$student->id] = $student;
        }
        $info = new \core_availability\mock_info($course);
        $checker = new \core_availability\capability_checker($info->get_context());

        // Make test cohorts.
        $cohort1 = $generator->create_cohort(['contextid' => \context_coursecat::instance($category->id)->id]);
        $cohort2 = $generator->create_cohort(['contextid' => \context_system::instance()->id]);
        $cohort3 = $generator->create_cohort(['contextid' => \context_coursecat::instance($othercategory->id)->id]);

        // Assign students to cohorts as follows (teacher is not in a cohort):
        // 0: no cohorts.
        // 1: in cohort 1 (in the course's category).
        // 2: in cohort 2 (in the system context).
        // 3: in cohort 3 (in another category, so it does not count for 'any cohort').
        cohort_add_member($cohort1->id, $students[1]->id);
        cohort_add_member($cohort2->id, $students[2]->id);
        cohort_add_member($cohort3->id, $students[3]->id);

        // Test 'any cohort' condition.
        $cond = new condition((object)[]);
        $this->assertTrue($cond->is_applied_to_user_lists());
        $expected = [$students[1]->id, $students[2]->id];
        $this->assert_filtered_users($expected, $cond, false, $allusers, $info, $checker);

        // Test NOT version (there is no capability to bypass the condition, so the teacher is not included).
        $expected = [$teacher->id, $students[0]->id, $students[3]->id];
        $this->assert_filtered_users($expected, $cond, true, $allusers, $info, $checker);

        // Test specific cohort.
        $cond = new condition((object)['id' => (int)$cohort1->id]);
        $expected = [$students[1]->id];
        $this->assert_filtered_users($expected, $cond, false, $allusers, $info, $checker);

        $expected = [$teacher->id, $students[0]->id, $students[2]->id, $students[3]->id];
        $this->assert_filtered_users($expected, $cond, true, $allusers, $info, $checker);

        // Test specific cohort in another category (membership is checked regardless of the cohort's context).
        $cond = new condition((object)['id' => (int)$cohort3->id]);
        $expected = [$students[3]->id];
        $this->assert_filtered_users($expected, $cond, false, $allusers, $info, $checker);

        // Test that the user list is filtered correctly if it is checked in several chunks.
        cohort_add_member($cohort1->id, $students[3]->id);
        $cond = new class ((object)['id' => (int)$cohort1->id]) extends condition {
            /** @var int Small chunk size to split the five users into three chunks */
            protected const USERLIST_CHUNKSIZE = 2;
        };
        $expected = [$students[1]->id, $students[3]->id];
        $this->assert_filtered_users($expected, $cond, false, $allusers, $info, $checker);
        cohort_remove_member($cohort1->id, $students[3]->id);

        // Test the whole availability API with an activity which is restricted to cohort 3 OR any cohort.
        // This combines both kinds of SQL within one query and keeps the teacher as they can view hidden activities.
        $tree = \core_availability\tree::get_root_json([
            condition::get_json($cohort3->id),
            condition::get_json(),
        ], \core_availability\tree::OP_OR);
        $page = $generator->get_plugin_generator('mod_page')->create_instance(
            ['course' => $course->id, 'availability' => json_encode($tree)]
        );
        $modinfo = get_fast_modinfo($course);
        $cminfo = new \core_availability\info_module($modinfo->get_cm($page->cmid));
        $expected = [$teacher->id, $students[1]->id, $students[2]->id, $students[3]->id];
        $result = array_keys($cminfo->filter_user_list($allusers));
        sort($result);
        $this->assertEquals($expected, $result);
        [$sql, $params] = $cminfo->get_user_list_sql(true);
        $result = $DB->get_fieldset_sql($sql, $params);
        sort($result);
        $this->assertEquals($expected, $result);
    }

    /**
     * Asserts that both filter_user_list() and get_user_list_sql() return the expected users.
     *
     * @param array $expected Expected user ids, sorted ascending
     * @param condition $cond Condition to check
     * @param bool $not True if the condition is applying in negative mode
     * @param array $allusers Array of userid => object to filter
     * @param \core_availability\info $info Item we're checking
     * @param \core_availability\capability_checker $checker Capability checker
     */
    protected function assert_filtered_users(
        array $expected,
        condition $cond,
        bool $not,
        array $allusers,
        \core_availability\info $info,
        \core_availability\capability_checker $checker
    ): void {
        global $DB;

        $result = array_keys($cond->filter_user_list($allusers, $not, $info, $checker));
        sort($result);
        $this->assertEquals($expected, $result);

        [$sql, $params] = $cond->get_user_list_sql($not, $info, true);
        $result = $DB->get_fieldset_sql($sql, $params);
        sort($result);
        $this->assertEquals($expected, $result);
    }

    /**
     * Tests the constructor including error conditions. Also tests the
     * string conversion feature (intended for debugging only).
     *
     * @covers \availability_cohort\condition::__construct()
     */
    public function test_constructor(): void {
        // Invalid id (not int).
        $structure = (object)['id' => 'bourne'];
        try {
            $cond = new condition($structure);
            $this->fail();
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Invalid ->id', $e->getMessage());
        }

        // Valid (with id).
        $structure->id = 123;
        $cond = new condition($structure);
        $this->assertEquals('{cohort:#123}', (string)$cond);

        // Valid (no id).
        unset($structure->id);
        $cond = new condition($structure);
        $this->assertEquals('{cohort:any}', (string)$cond);
    }

    /**
     * Tests the save() function.
     *
     * @covers \availability_cohort\condition::save()
     */
    public function test_save(): void {
        $structure = (object)['id' => 123];
        $cond = new condition($structure);
        $structure->type = 'cohort';
        $this->assertEquals($structure, $cond->save());

        $structure = (object)[];
        $cond = new condition($structure);
        $structure->type = 'cohort';
        $this->assertEquals($structure, $cond->save());
    }
}

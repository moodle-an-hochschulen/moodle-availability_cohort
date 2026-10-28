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
 * Availability cohort - Condition
 *
 * @package     availability_cohort
 * @copyright   2018 Kathrin Osswald, Ulm University <kathrin.osswald@uni-ulm.de>
 *              based on code of availability_group 2014 The Open University
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace availability_cohort;

/**
 * Availability cohort - Condition class
 *
 * @package     availability_cohort
 * @copyright   2018 Kathrin Osswald, Ulm University <kathrin.osswald@uni-ulm.de>
 *              based on code of availability_group 2014 The Open University
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class condition extends \core_availability\condition {
    /** @var array Array from cohort id => name */
    protected static $cohortnames = [];

    /** @var int ID of cohort that this condition requires, or 0 = any cohort */
    protected $cohortid;

    /**
     * Constructor.
     *
     * @param \stdClass $structure Data structure from JSON decode
     * @throws \coding_exception If invalid data structure.
     */
    public function __construct($structure) {
        // Get cohort id.
        if (!property_exists($structure, 'id')) {
            $this->cohortid = 0;
        } else if (is_int($structure->id)) {
            $this->cohortid = $structure->id;
        } else {
            throw new \coding_exception('Invalid ->id for cohort condition');
        }
    }

    /**
     * Saving function.
     *
     * @return (object)array.
     */
    public function save() {
        $result = (object)['type' => 'cohort'];
        if ($this->cohortid) {
            $result->id = $this->cohortid;
        }
        return $result;
    }

    /**
     * Determines whether a particular item is currently available
     * according to this availability condition.
     *
     * If implementations require a course or modinfo, they should use
     * the get methods in $info.
     *
     * The $not option is potentially confusing. This option always indicates
     * the 'real' value of NOT. For example, a condition inside a 'NOT AND'
     * cohort will get this called with $not = true, but if you put another
     * 'NOT OR' cohort inside the first cohort, then a condition inside that will
     * be called with $not = false. We need to use the real values, rather than
     * the more natural use of the current value at this point inside the tree,
     * so that the information displayed to users makes sense.
     *
     * @param bool $not Set true if we are inverting the condition
     * @param info $info Item we're checking
     * @param bool $grabthelot Performance hint: if true, caches information
     *   required for all course-modules, to make the front page and similar
     *   pages work more quickly (works only for current user)
     * @param int $userid User ID to check availability for
     * @return bool True if available
     */
    public function is_available($not, \core_availability\info $info, $grabthelot, $userid) {
        global $CFG, $COURSE;
        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->dirroot . '/availability/condition/cohort/locallib.php');

        $cohortids = [];
        // Cohort condition is set to a specific cohort.
        if ($this->cohortid) {
            // User is member of the given cohort.
            $allow = cohort_is_member($this->cohortid, $userid);
        } else {
            // Cohort condition is set to "any cohort".
            $allcohorts = availability_cohort_cohort_get_cohorts(\context_course::instance($COURSE->id));
            foreach ($allcohorts["cohorts"] as $cohort) {
                $cohortids[] = $cohort->id;
            }
            $allow = availability_cohort_is_member($userid, $cohortids);
        }

        // The NOT condition applies before accessallcohorts (i.e. if you
        // set something to be available to those NOT in cohort X,
        // people with accessallcohorts can still access it even if
        // they are in cohort X).
        if ($not) {
            $allow = !$allow;
        }

        return $allow;
    }

    /**
     * Obtains a string describing this restriction (whether or not
     * it actually applies). Used to obtain information that is displayed to
     * students if the activity is not available to them, and for staff to see
     * what conditions are.
     *
     * The $full parameter can be used to distinguish between 'staff' cases
     * (when displaying all information about the activity) and 'student' cases
     * (when displaying only conditions they don't meet).
     *
     * If implementations require a course or modinfo, they should use
     * the get methods in $info.
     *
     * The special string <AVAILABILITY_CMNAME_123/> can be returned, where
     * 123 is any number. It will be replaced with the correctly-formatted
     * name for that activity.
     *
     * @param bool $full Set true if this is the 'full information' view
     * @param bool $not Set true if we are inverting the condition
     * @param info $info Item we're checking
     * @return string Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description($full, $not, \core_availability\info $info) {
        global $DB;

        if ($this->cohortid) {
            // Need to get the name for the cohort. Unfortunately this requires
            // a database query. To save queries, get all cohorts for course at
            // once in a static cache.
            if (!array_key_exists($this->cohortid, self::$cohortnames)) {
                $cohorts = $DB->get_records('cohort', ['id' => $this->cohortid], '', 'id, name');
                foreach ($cohorts as $rec) {
                    self::$cohortnames[$rec->id] = $rec->name;
                }
            }

            // If it still doesn't exist, it must have been misplaced.
            if (!array_key_exists($this->cohortid, self::$cohortnames)) {
                $name = get_string('missing', 'availability_cohort');
            } else {
                // Not safe to call format_string here as $PAGE->context might not be set yet;
                // use the special function to call it later in info::format_info().
                $name = self::description_format_string(self::$cohortnames[$this->cohortid]);
            }
        } else {
            return get_string(
                $not ? 'requires_notanycohort' : 'requires_anycohort',
                'availability_cohort'
            );
        }

        return get_string(
            $not ? 'requires_notcohort' : 'requires_cohort',
            'availability_cohort',
            $name
        );
    }

    /**
     * Get the cohort id or any cohort as string for debugging purposes.
     *
     * @return string
     */
    protected function get_debug_string() {
        return $this->cohortid ? '#' . $this->cohortid : 'any';
    }

    /**
     * Checks whether this availability condition should be included after restore or not. The
     * condition may be removed depending on restore settings, which you can get from
     * the $task object.
     *
     * Restrictions which can't be resolved anymore (the cohort was deleted in the meantime or the backup
     * comes from another site) are only removed if the cleanuponrestore setting is enabled.
     * Otherwise, they are kept and can never be fulfilled, just as orphaned restrictions after a cohort deletion.
     * This way, a restore never silently opens up an activity which was restricted before.
     *
     * @param string $restoreid Restore ID
     * @param int $courseid ID of target course
     * @param \base_logger $logger Logger for any warnings
     * @param string $name Name of this item (for use in warning messages)
     * @param \base_task $task Current restore task
     * @return bool True if the condition should be included
     */
    public function include_after_restore(
        $restoreid,
        $courseid,
        \base_logger $logger,
        $name,
        \base_task $task
    ) {
        global $DB;

        // The condition is set to "any cohort".
        // This condition does not depend on a particular cohort, so it is always kept.
        if (!$this->cohortid) {
            return true;
        }

        // We are restoring on the same instance and the cohort still exists.
        // The cohort membership is evaluated regardless of the cohort's context and visibility in is_available(),
        // so the condition is kept regardless of these properties as well.
        if ($task->is_samesite() && $DB->record_exists('cohort', ['id' => $this->cohortid])) {
            return true;
        }

        // The cohort can't be resolved anymore and the admin wants orphaned restrictions to be removed.
        if (get_config('availability_cohort', 'cleanuponrestore')) {
            $logger->process(
                'Restored item (' . $name . ') had an availability condition on a cohort which does not exist ' .
                    'on this site, the condition was removed',
                \backup::LOG_WARNING
            );
            return false;
        }

        // Otherwise, keep the condition (and let update_after_restore() take care of the cohort id).
        $logger->process(
            'Restored item (' . $name . ') has an availability condition on a cohort which does not exist ' .
                'on this site, the condition can not be fulfilled',
            \backup::LOG_WARNING
        );
        return true;
    }

    /**
     * Updates this condition after restore.
     *
     * If the backup comes from another site, the cohort id of the original site does not mean anything
     * on this site and might even point to an unrelated cohort. Thus, it is replaced by an invalid cohort id
     * which can never be fulfilled. The restore log warning about this has already been added by include_after_restore().
     *
     * @param string $restoreid Restore ID
     * @param int $courseid ID of target course
     * @param \base_logger $logger Logger for any warnings
     * @param string $name Name of this item (for use in warning messages)
     * @return bool True if there was any change
     */
    public function update_after_restore($restoreid, $courseid, \base_logger $logger, $name) {
        // The condition is set to "any cohort" or we are restoring on the same instance.
        // The cohort id remains valid (or orphaned, if the cohort was deleted in the meantime).
        if (!$this->cohortid || \core_availability\info::get_restore_task($restoreid)->is_samesite()) {
            return false;
        }

        // Otherwise, invalidate the cohort id.
        $this->cohortid = -1;
        return true;
    }

    /**
     * Wipes the static cache used to store cohort names.
     */
    public static function wipe_static_cache() {
        self::$cohortnames = [];
    }

    /**
     * Returns a JSON object which corresponds to a condition of this type.
     *
     * Intended for unit testing, as normally the JSON values are constructed
     * by JavaScript code.
     *
     * @param int $cohortid Required cohort id (0 = any cohort)
     * @return stdClass Object representing condition
     */
    public static function get_json($cohortid = 0) {
        $result = (object)['type' => 'cohort'];
        // Id is only included if set.
        if ($cohortid) {
            $result->id = (int)$cohortid;
        }
        return $result;
    }
}

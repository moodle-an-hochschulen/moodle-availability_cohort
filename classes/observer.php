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
 * Availability cohort - Event observer
 *
 * @package     availability_cohort
 * @copyright   2026 Alexander Bias <bias@alexanderbias.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace availability_cohort;

/**
 * Availability cohort - Event observer class
 *
 * @package     availability_cohort
 * @copyright   2026 Alexander Bias <bias@alexanderbias.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Observer for the cohort_deleted event.
     *
     * When a cohort is deleted, any availability restriction which requires this particular cohort would
     * otherwise become orphaned and permanently hide the affected activity or section from everyone. To avoid
     * this, we remove the restriction which refers to the deleted cohort from all availability trees on the site.
     *
     * @param \core\event\cohort_deleted $event The cohort_deleted event.
     * @return void
     */
    public static function cohort_deleted(\core\event\cohort_deleted $event): void {
        // The cleanup is an optional feature which is disabled by default. Only proceed if the admin has enabled the
        // corresponding kill switch on the plugin settings page.
        if (!get_config('availability_cohort', 'cleanuponcohortdeletion')) {
            return;
        }

        self::remove_cohort_from_availability((int)$event->objectid);
    }

    /**
     * Removes every reference to the given cohort from the availability restrictions of all course modules and
     * course sections on the site.
     *
     * @param int $cohortid The ID of the deleted cohort.
     * @return void
     */
    protected static function remove_cohort_from_availability(int $cohortid): void {
        global $CFG, $DB;

        // We need the course library for rebuild_course_cache(), which is not guaranteed to be loaded when a cohort
        // is deleted (e.g. via CLI or webservice).
        require_once($CFG->dirroot . '/course/lib.php');

        // Remember the courses whose availability data we change, so that we can rebuild their caches afterwards.
        $affectedcourses = [];

        // Start transaction.
        $transaction = $DB->start_delegated_transaction();

        // The availability restrictions are stored in the availability column of the course modules as well as of the
        // course sections. We narrow the candidates down with a simple LIKE filter and verify the actual cohort ID
        // afterwards when we walk the availability tree.
        foreach (['course_modules', 'course_sections'] as $table) {
            // Get the records which contain a 'cohort' availability constraint.
            $select = $DB->sql_like('availability', '?');
            $recordset = $DB->get_recordset_select(
                $table,
                $select,
                ['%"cohort"%'],
                '',
                'id, course AS courseid, availability'
            );

            // Iterate over the findings.
            foreach ($recordset as $record) {
                // Decode the conditions.
                $tree = json_decode($record->availability);

                // Skip records which do not hold a valid availability tree.
                if ($tree === null || !isset($tree->c) || !is_array($tree->c)) {
                    continue;
                }

                // Remove the cohort restriction from the tree and skip the record if nothing changed.
                if (!self::remove_cohort_from_tree($tree, $cohortid)) {
                    continue;
                }

                // If the tree does not hold any restriction anymore, store null instead of an empty tree.
                if (empty($tree->c)) {
                    $newvalue = null;
                } else {
                    $newvalue = json_encode($tree);
                }

                // Set the availability condition back in the DB.
                $DB->set_field($table, 'availability', $newvalue, ['id' => $record->id]);

                // And add the course to the list of affected courses.
                $affectedcourses[$record->courseid] = true;
            }
            $recordset->close();
        }

        // Commit the transaction.
        $transaction->allow_commit();

        // Rebuild the course caches of the affected courses so that the changed availability data takes effect.
        foreach (array_keys($affectedcourses) as $courseid) {
            rebuild_course_cache($courseid, true);
        }
    }

    /**
     * Recursively removes all conditions which require the given cohort from an availability tree.
     *
     * Why we manipulate the tree ourselves:
     * Moodle Core does not provide a function which removes a single condition from a stored availability tree. In the
     * regular editing workflow, the removal of a condition happens entirely client-side in the availability JavaScript
     * (core_availability/form): The browser rebuilds the *complete* tree and submits it, and the server just stores it
     * as-is (see update_moduleinfo() in course/modlib.php). The only related Core helper,
     * \core_availability\info::update_dependency_id(), merely *remaps* an ID (old to new, e.g. during restore) but
     * cannot *remove* a node. As we react to a cohort deletion completely server-side (without any form or JavaScript),
     * we therefore have to reproduce the client-side removal here ourselves.
     *
     * How an availability tree looks:
     * An availability tree is a nested structure of tree nodes and leaf conditions, e.g.
     *   {"op":"&", "c":[ {"type":"cohort","id":5}, {"type":"date", ...} ], "showc":[true,false]}
     * - A tree node holds its children in the c property and an operator in op (e.g. '&' for AND).
     * - For AND-type nodes (op '&' and '!|') there is a parallel showc array which holds the 'display' flag for each
     *   child. It must be kept in sync with the c array whenever we remove a child.
     * - A leaf condition is identified by its type property. A cohort condition additionally holds the required cohort
     *   in its id property. An 'any cohort' condition has no id and is therefore intentionally kept on cohort deletion.
     *
     * The passed tree node is modified in place. Nested subtrees which become empty are dropped as well.
     *
     * After the manipulation, a safety check verifies that we really only removed the conditions which required the
     * deleted cohort and left everything else untouched. If this check fails (which must never happen), the tree is
     * reported as unchanged so that the potentially broken result is not written back to the database.
     *
     * @param \stdClass $tree The (sub)tree node to process, holding a list of children in its c property.
     * @param int $cohortid The ID of the deleted cohort.
     * @return bool True if the tree was changed (and verified), false otherwise.
     */
    protected static function remove_cohort_from_tree(\stdClass $tree, int $cohortid): bool {
        // Keep a pristine deep copy of the original tree so that we can verify our manipulation afterwards. We must not
        // use clone here: clone would only create a shallow copy whose nested child objects are shared with $tree, so
        // the in-place manipulation below would also alter the copy. A JSON round-trip is a simple and safe way to
        // deep-clone these plain data objects.
        $original = json_decode(json_encode($tree));

        // Perform the actual removal (recursively, modifying $tree in place).
        $changed = self::remove_cohort_from_tree_recursive($tree, $cohortid);

        // If nothing was changed, there is nothing to verify and nothing to write back.
        if (!$changed) {
            return false;
        }

        // Safety check: verify that we really only removed the deleted cohort's conditions and left everything else
        // intact. If the verification fails, we must not persist the result, so we report the tree as unchanged.
        if (!self::verify_cohort_removal($original, $tree, $cohortid)) {
            debugging(
                'The availability tree manipulation after the deletion of cohort ' . $cohortid . ' could not be '
                    . 'verified and was therefore skipped. This is a bug in availability_cohort and should be reported.',
                DEBUG_DEVELOPER
            );
            return false;
        }

        return true;
    }

    /**
     * Recursively removes all conditions which require the given cohort from an availability tree.
     *
     * This is the actual worker of remove_cohort_from_tree(). It modifies the passed tree node in place, drops nested
     * subtrees which become empty and keeps the parallel showc array of each node in sync with its children.
     *
     * @param \stdClass $tree The (sub)tree node to process, holding a list of children in its c property.
     * @param int $cohortid The ID of the deleted cohort.
     * @return bool True if the tree was changed, false otherwise.
     */
    protected static function remove_cohort_from_tree_recursive(\stdClass $tree, int $cohortid): bool {
        // If the node does not hold a valid list of children, there is nothing to process, so return directly.
        if (!isset($tree->c) || !is_array($tree->c)) {
            return false;
        }

        // The showc array only exists for AND-type nodes and is parallel to the c array, so we have to keep it in sync
        // with the children whenever we remove a child below.
        $haveshowc = isset($tree->showc) && is_array($tree->showc);

        // We rebuild the list of children (and, if present, the parallel showc array) from scratch, keeping only the
        // children which should remain, and remember in $changed whether we actually dropped anything.
        $changed = false;
        $newchildren = [];
        $newshowc = [];
        foreach ($tree->c as $index => $child) {
            if (isset($child->c)) {
                // The child is a nested subtree, so we recurse into it.
                if (self::remove_cohort_from_tree_recursive($child, $cohortid)) {
                    $changed = true;
                }
                // If the nested subtree does not hold any restriction anymore, we drop it completely.
                if (empty($child->c)) {
                    $changed = true;
                    continue;
                }
                $newchildren[] = $child;
                if ($haveshowc) {
                    $newshowc[] = $tree->showc[$index];
                }
            } else if (
                isset($child->type) && $child->type === 'cohort'
                    && isset($child->id) && (int)$child->id === $cohortid
            ) {
                // The child is a condition which requires the deleted cohort, so we drop it.
                // Note: An 'any cohort' condition does not hold an id and is therefore intentionally kept.
                $changed = true;
            } else {
                // The child is any other condition, so we keep it.
                $newchildren[] = $child;
                if ($haveshowc) {
                    $newshowc[] = $tree->showc[$index];
                }
            }
        }

        // Only write the rebuilt children back if we actually changed something, to leave untouched trees byte-identical.
        if ($changed) {
            $tree->c = $newchildren;
            if ($haveshowc) {
                $tree->showc = $newshowc;
            }
        }

        return $changed;
    }

    /**
     * Verifies that the availability tree manipulation only removed the conditions which required the deleted cohort
     * and left everything else untouched.
     *
     * This is a defensive guard against bugs in remove_cohort_from_tree_recursive() and unexpected glitches in the
     * availability data. It compares the pristine original tree with the manipulated result and checks that:
     * - the result does not require the deleted cohort anymore,
     * - every other condition of the original tree is still present (and no new condition appeared),
     * - the parallel showc array of each node still matches the number of that node's children.
     *
     * @param \stdClass $original The pristine original tree.
     * @param \stdClass $result The manipulated tree.
     * @param int $cohortid The ID of the deleted cohort.
     * @return bool True if the manipulation is verified to be correct, false otherwise.
     */
    protected static function verify_cohort_removal(\stdClass $original, \stdClass $result, int $cohortid): bool {
        // Collect the leaf conditions of the original tree, separated into the ones which require the deleted cohort
        // and all other conditions.
        $originalcohortleaves = [];
        $originalotherleaves = [];
        self::collect_leaves($original, $cohortid, $originalcohortleaves, $originalotherleaves);

        // Collect the leaf conditions of the manipulated tree in the same way.
        $resultcohortleaves = [];
        $resultotherleaves = [];
        self::collect_leaves($result, $cohortid, $resultcohortleaves, $resultotherleaves);

        // The manipulated tree must not require the deleted cohort anymore.
        if (!empty($resultcohortleaves)) {
            return false;
        }

        // The manipulated tree must hold exactly the same other conditions as the original tree (none lost, none added).
        // We sort both lists before comparing them because the manipulation might change the order of the conditions.
        sort($originalotherleaves);
        sort($resultotherleaves);
        if ($originalotherleaves !== $resultotherleaves) {
            return false;
        }

        // The showc array of each node must still match the number of that node's children.
        if (!self::verify_showc_integrity($result)) {
            return false;
        }

        return true;
    }

    /**
     * Recursively collects the leaf conditions of an availability tree into two lists: the conditions which require the
     * given cohort and all other conditions. Each leaf condition is added as its JSON representation.
     *
     * @param \stdClass $tree The (sub)tree node to process.
     * @param int $cohortid The ID of the deleted cohort.
     * @param array $cohortleaves The list which collects the conditions which require the deleted cohort (by reference).
     * @param array $otherleaves The list which collects all other conditions (by reference).
     * @return void
     */
    protected static function collect_leaves(\stdClass $tree, int $cohortid, array &$cohortleaves, array &$otherleaves): void {
        if (!isset($tree->c) || !is_array($tree->c)) {
            return;
        }
        foreach ($tree->c as $child) {
            if (isset($child->c)) {
                // The child is a nested subtree, so we recurse into it.
                self::collect_leaves($child, $cohortid, $cohortleaves, $otherleaves);
            } else if (
                isset($child->type) && $child->type === 'cohort'
                    && isset($child->id) && (int)$child->id === $cohortid
            ) {
                // The child is a condition which requires the deleted cohort.
                $cohortleaves[] = json_encode($child);
            } else {
                // The child is any other condition.
                $otherleaves[] = json_encode($child);
            }
        }
    }

    /**
     * Recursively verifies that the parallel showc array of each node matches the number of that node's children.
     *
     * @param \stdClass $tree The (sub)tree node to process.
     * @return bool True if the showc arrays are consistent, false otherwise.
     */
    protected static function verify_showc_integrity(\stdClass $tree): bool {
        if (!isset($tree->c) || !is_array($tree->c)) {
            return true;
        }
        if (isset($tree->showc) && is_array($tree->showc) && count($tree->showc) !== count($tree->c)) {
            return false;
        }
        foreach ($tree->c as $child) {
            if (isset($child->c) && !self::verify_showc_integrity($child)) {
                return false;
            }
        }
        return true;
    }
}

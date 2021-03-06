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
 * For getting course total grades.
 * @author    Guy Thomas <gthomas@moodlerooms.com>
 * @copyright Copyright (c) 2017 Blackboard Inc.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace theme_snap;

use course_grade;
use context_user;
use grade_item;
use grade_grade;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/grade/report/overview/lib.php');

/**
 * For getting course total grades.
 * @author    Guy Thomas <gthomas@moodlerooms.com>
 * @copyright Copyright (c) 2017 Blackboard Inc.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_total_grade extends \grade_report_overview {

    /**
     * @var bool
     */
    protected $isstudent = false;

    /**
     * Decimal points to use for values in the report, default 2
     * @var int
     */
    public $decimals = 2;

    /**
     * Constructor. Get course grade for specific user and course.
     * @param stdClass $user
     * @param object $gpr grade plugin return tracking object
     * @param stdClass $course
     * @return str course total grade value.
     */
    public function __construct($user, $gpr, $course) {
        global $CFG;

        $this->user = $user;

        if (empty($CFG->gradebookroles)) {
            print_error('norolesdefined', 'grades');
        }

        $this->courseid  = $course->id;
        $this->course = $course;
        $this->context = \context_course::instance($course->id);
        $this->gradebookroles = $CFG->gradebookroles;

        $this->showtotalsifcontainhidden = array();

        $this->studentcourseids = array();
        $this->teachercourses = array();
        $roleids = explode(',', get_config('moodle', 'gradebookroles'));

        $this->showtotalsifcontainhidden[$course->id] = grade_get_setting($course->id, 'report_overview_showtotalsifcontainhidden', $CFG->grade_report_overview_showtotalsifcontainhidden);

        foreach ($roleids as $roleid) {
            if (user_has_role_assignment($user->id, $roleid, $this->context->id)) {
                $this->isstudent = true;
            }
        }

        // The default grade decimals is 2
        $defaultdecimals = 2;
        if (property_exists($CFG, 'grade_decimalpoints')) {
            $defaultdecimals = $CFG->grade_decimalpoints;
        }
        $this->decimals = grade_get_setting($this->courseid, 'decimalpoints', $defaultdecimals);

    }

    /**
     * Get course total grade.
     * @param bool $studentcoursesonly
     * @return array
     * @throws \coding_exception
     */
    public function get_course_total($studentcoursesonly = true) {
        global $USER;

        // Default 'empty' output.
        $output = array("value" => '-', "percentage" => '-');

        if ($studentcoursesonly && !$this->isstudent) {
            return $output;
        }

        if (!$this->course->visible && !has_capability('moodle/course:viewhiddencourses', $this->context)) {
            // The course is hidden and the user isn't allowed to see it
            return $output;
        }

        if (!has_capability('moodle/user:viewuseractivitiesreport', context_user::instance($this->user->id)) &&
            ((!has_capability('moodle/grade:view', $this->context) || $this->user->id != $USER->id) &&
                !has_capability('moodle/grade:viewall', $this->context))
        ) {
            return $output;
        }

        // Get course grade_item
        $course_item = grade_item::fetch_course_item($this->course->id);

        // Get the stored grade
        $course_grade = new grade_grade(array('itemid' => $course_item->id, 'userid' => $this->user->id));
        $course_grade->grade_item =& $course_item;
        $finalgrade = $course_grade->finalgrade;

        // Return error when grade needs updating.
        if ($course_grade->grade_item->needsupdate) {
            return array("value" => get_string('error'), "percentage" => '-');
        }

        $canviewhidden = has_capability('moodle/grade:viewhidden', $this->context);
        // Return '-' values when grade is hidden and user cannot view.
        if (!$canviewhidden && $course_grade->is_hidden()) {
            return $output;
        }

        if (!$canviewhidden and !is_null($finalgrade)) {
            if ($course_grade->is_hidden()) {
                $finalgrade = null;
            } else {
                $adjustedgrade = $this->blank_hidden_total_and_adjust_bounds($this->course->id,
                    $course_item,
                    $finalgrade);

                // We temporarily adjust the view of this grade item - because the min and
                // max are affected by the hidden values in the aggregation.
                $finalgrade = $adjustedgrade['grade'];
                $course_grade->grade_item->grademax = $adjustedgrade['grademax'];
                $course_grade->grade_item->grademin = $adjustedgrade['grademin'];
            }
        } else {
            // We must use the specific max/min because it can be different for
            // each grade_grade when items are excluded from sum of grades.
            if (!is_null($finalgrade)) {
                $course_grade->grade_item->grademin = $course_grade->get_grade_min();
                $course_grade->grade_item->grademax = $course_grade->get_grade_max();
            }
        }

        // Percentage grade for use with progressbar.js.
        $percentage = round(grade_format_gradevalue($finalgrade,
                $course_grade->grade_item,
                true, GRADE_DISPLAY_TYPE_PERCENTAGE));

        $value = grade_format_gradevalue($finalgrade, $course_grade->grade_item);
        return array("value" => $value, "percentage" => $percentage);
    }
}

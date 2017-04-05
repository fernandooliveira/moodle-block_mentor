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
 * @package    block_fn_mentor
 * @copyright  Michael Gardener <mgardener@cissq.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot.'/mod/assignment/lib.php');
require_once($CFG->dirroot.'/lib/completionlib.php');

function block_fn_mentor_get_all_students($filter = '', $selectedcoursesonly = false) {
    global $DB, $CFG;

    $studentrole = get_config('block_fn_mentor', 'studentrole');

    $params = array(0, 0, $studentrole);
    $wherecondions = '';

    if ($filter) {
        $wherecondions .= " AND (u.firstname LIKE '%".$filter."%'
                            OR u.lastname LIKE '%".$filter."%'
                            OR u.email LIKE '%".$filter."%')";
        $params[] = "%".$filter."%";
        $params[] = "%".$filter."%";
        $params[] = "%".$filter."%";
    }
    $join = '';
    $filter = '';
    if ($selectedcoursesonly) {
        if ($settingcourses = block_fn_mentor_get_setting_courses()) {
            list($sqlfilter, $paramcourses) = $DB->get_in_or_equal($settingcourses);
            $params = array_merge($params, $paramcourses);
            $join = "INNER JOIN {context} ctx ON ra.contextid = ctx.id";
            $filter = "AND ctx.instanceid {$sqlfilter}";
        }
    }

    $sql = "SELECT DISTINCT u.id,
                            u.firstname,
                            u.lastname
                       FROM {role_assignments} ra
                 INNER JOIN {user} u
                         ON ra.userid = u.id
                            $join
                      WHERE u.deleted = ?
                        AND u.suspended = ?
                        AND ra.roleid = ?
                            $wherecondions
                            $filter
                   ORDER BY u.lastname ASC";

    $everyone = $DB->get_records_sql($sql, $params);

    return $everyone;
}

function block_fn_mentor_get_students_without_mentor($filter = '') {
    global $DB, $CFG;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }

    $studentrole = get_config('block_fn_mentor', 'studentrole');
    $params = array(0, 0, $studentrole);

    $wherecondions = '';

    if ($filter) {
        $wherecondions .= " AND (u.firstname LIKE '%".$filter."%'
                            OR u.lastname LIKE '%".$filter."%'
                            OR u.email LIKE '%".$filter."%')";
        $params[] = "%".$filter."%";
        $params[] = "%".$filter."%";
        $params[] = "%".$filter."%";
    }

    $sql = "SELECT DISTINCT u.id,
                            u.firstname,
                            u.lastname
                       FROM {role_assignments} ra
                 INNER JOIN {user} u
                         ON ra.userid = u.id
                      WHERE u.deleted = ?
                        AND u.suspended = ?
                        AND ra.roleid = ?
                        $wherecondions
                   ORDER BY u.lastname ASC";

    $everyone = $DB->get_records_sql($sql, $params);

    $sqlmentor = "SELECT ra.id,
                         ra.userid AS mentorid,
                         ctx.instanceid AS studentid
                    FROM {context} ctx
              INNER JOIN {role_assignments} ra
                      ON ctx.id = ra.contextid
                   WHERE ctx.contextlevel = ?
                     AND ra.roleid = ?";

    $stuwithmentor = array();

    if ($studentswithmentor = $DB->get_records_sql($sqlmentor, array(CONTEXT_USER, $mentorroleid))) {
        foreach ($studentswithmentor as $key => $value) {
            $stuwithmentor[$value->studentid] = $value->studentid;
        }
    }

    $studentswithoutmentor = array_diff_key($everyone, $stuwithmentor);

    return $studentswithoutmentor;
}

function block_fn_mentor_is_mentee($userid) {
    global $DB, $CFG;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }

    $studentrole = get_config('block_fn_mentor', 'studentrole');

    $wherecondions = '';

    $sqlmentor = "SELECT ra.id,
                         ra.userid AS mentorid,
                         ctx.instanceid AS studentid
                    FROM {context} ctx
              INNER JOIN {role_assignments} ra
                      ON ctx.id = ra.contextid
                   WHERE ctx.contextlevel = ?
                     AND ra.roleid = ?
                     AND ctx.instanceid = ?";

    return $DB->record_exists_sql($sqlmentor, array(CONTEXT_USER, $mentorroleid, $userid));

}

function block_fn_mentor_get_mentors_without_mentee() {
    global $DB, $CFG;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_system')) {
        return false;
    }
    $sql = "SELECT DISTINCT u.id,
                            u.firstname,
                            u.lastname
                       FROM {role_assignments} ra
                 INNER JOIN {user} u
                         ON ra.userid = u.id
                      WHERE u.deleted = ?
                        AND u.suspended = ?
                        AND ra.roleid = ?
                   ORDER BY u.lastname ASC";

    $everyone = $DB->get_records_sql($sql, array(0, 0, $mentorroleid));

    if (! $mentorroleiduser = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }

    $sqlmentor = "SELECT ra.id,
                          ra.userid AS mentorid,
                          ctx.instanceid AS studentid
                     FROM {context} ctx
               INNER JOIN {role_assignments} ra
                       ON ctx.id = ra.contextid
                    WHERE ctx.contextlevel = ?
                      AND ra.roleid = ?";

    $menwithmentee = array();

    if ($mentorswithmentee = $DB->get_records_sql($sqlmentor, array(CONTEXT_USER, $mentorroleiduser))) {
        foreach ($mentorswithmentee as $key => $value) {
            $menwithmentee[$value->mentorid] = $value->mentorid;
        }
    }

    $mentorswithoutmentee = array_diff_key($everyone, $menwithmentee);

    return $mentorswithoutmentee;
}

function block_fn_mentor_get_all_mentees($studentids='') {
    global $DB;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }

    $sqlmentor = "SELECT ra.id,
                         ra.userid AS mentorid,
                         ctx.instanceid AS studentid,
                         u.firstname,
                         u.lastname
                    FROM {context} ctx
              INNER JOIN {role_assignments} ra
                      ON ctx.id = ra.contextid
              INNER JOIN {user} u
                      ON ctx.instanceid = u.id
                   WHERE ctx.contextlevel = ?
                     AND ra.roleid = ?
                ORDER BY u.lastname ASC";

    $stuwithmentor = array();

    if ($studentswithmentor = $DB->get_records_sql($sqlmentor, array(CONTEXT_USER, $mentorroleid))) {
        foreach ($studentswithmentor as $key => $value) {
            $stuwithmentor[$value->studentid] = $value;
        }
    }

    if ($studentids) {
        $stuwithmentor = array_intersect_key($stuwithmentor, $studentids);
    }
    return $stuwithmentor;
}

function block_fn_mentor_get_all_mentors() {
    global $DB;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_system')) {
        return false;
    }
    $sql = "SELECT DISTINCT u.id,
                            u.firstname,
                            u.lastname
                       FROM {role_assignments} ra
                 INNER JOIN {user} u
                         ON ra.userid = u.id
                      WHERE u.deleted = ?
                        AND u.suspended = ?
                        AND ra.roleid = ?
                   ORDER BY u.lastname ASC";

    $everyone = $DB->get_records_sql($sql, array(0, 0, $mentorroleid));

    return $everyone;
}

function block_fn_mentor_get_mentees($mentorid, $courseid=0, $studentids = '') {
    global $DB;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }
    $studentrole = get_config('block_fn_mentor', 'studentrole');

    $coursestudents = array();

    if ($courseid) {
        $sqlcoursestudents = "SELECT ra.userid AS studentid,
                                     u.firstname,
                                     u.lastname
                                FROM {context} ctx
                          INNER JOIN {role_assignments} ra
                                  ON ctx.id = ra.contextid
                          INNER JOIN {user} u
                                  ON ra.userid = u.id
                               WHERE ctx.contextlevel = ?
                                 AND ra.roleid = ?
                                 AND ctx.instanceid = ?";
        $coursestudents = $DB->get_records_sql($sqlcoursestudents, array(50, $studentrole, $courseid));
    }

    $sql = "SELECT ctx.instanceid AS studentid,
                   u.firstname,
                   u.lastname
              FROM {role_assignments} ra
        INNER JOIN {context} ctx
                ON ra.contextid = ctx.id
        INNER JOIN {user} u
                ON ctx.instanceid = u.id
             WHERE ra.roleid = ?
               AND ra.userid = ?
               AND ctx.contextlevel = ?
          ORDER BY u.lastname ASC";

    $mentees = $DB->get_records_sql($sql, array($mentorroleid, $mentorid, CONTEXT_USER));

    if ($coursestudents) {
        $mentees = array_intersect_key($mentees, $coursestudents);
    }

    if ($studentids) {
        $mentees = array_intersect_key($mentees, $studentids);
    }

    foreach ($mentees as $key => $mentee) {
        if (block_fn_mentor_isstudentinanycourse($key)) {
            $mentee->enrolled = 1;
        } else {
            $mentee->enrolled = 0;
        }
        $mentees[$key] = $mentee;
    }

    return $mentees;

}

function block_fn_mentor_get_mentors($menteeid) {
    global $DB;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }

    if (! $mentorsysroleid = get_config('block_fn_mentor', 'mentor_role_system')) {
        return false;
    }

    $sql = "SELECT ra.id,
                   ra.userid mentorid,
                   u.firstname,
                   u.lastname,
                   u.lastaccess
              FROM {context} ctx
        INNER JOIN {role_assignments} ra
                ON ctx.id = ra.contextid
        INNER JOIN {user} u
                ON ra.userid = u.id
             WHERE ctx.contextlevel = ?
               AND ra.roleid = ?
               AND ctx.instanceid = ?
               AND ra.userid IN (SELECT ra2.userid
                                   FROM {role_assignments} ra2
                             INNER JOIN {context} ctx2 ON ra2.contextid = ctx2.id
                                  WHERE ra2.roleid = ?
                                    AND ctx2.contextlevel = ?)
          ORDER BY u.lastname ASC";

    return $DB->get_records_sql($sql, array(CONTEXT_USER, $mentorroleid, $menteeid, $mentorsysroleid,  CONTEXT_SYSTEM));

}

function block_fn_mentor_isteacherinanycourse($userid=null) {
    global $DB, $USER;

    if (! $userid) {
        $userid = $USER->id;
    }
    // If this user is assigned as an editing teacher anywhere then return true.
    if ($roles = get_roles_with_capability('moodle/course:update', CAP_ALLOW)) {
        foreach ($roles as $role) {
            if ($DB->record_exists('role_assignments', array('roleid' => $role->id, 'userid' => $userid))) {
                return true;
            }
        }
    }
    return false;
}

function block_fn_mentor_isstudentinanycourse($userid=null) {
    global $DB, $USER;

    if (! $userid) {
        $userid = $USER->id;
    }
    $studentrole = get_config('block_fn_mentor', 'studentrole');
    if ($DB->record_exists_sql("SELECT 1
                                  FROM {context} ctx
                            INNER JOIN {role_assignments} ra
                                    ON ctx.id = ra.contextid
                                 WHERE ctx.contextlevel = ?
                                   AND ra.roleid = ?
                                   AND ra.userid = ?", array(50, $studentrole, $userid))) {
        return true;
    }
    return false;
}

function block_fn_mentor_has_system_role($userid, $roleid) {
    global $DB;

    $sql = "SELECT 1
              FROM {role_assignments} ra
        INNER JOIN {context} ctx ON ra.contextid = ctx.id
             WHERE ra.roleid = :rolename
               AND ctx.contextlevel = :contextlevel
               AND ra.userid = :userid";

    return $DB->record_exists_sql($sql, array('rolename' => $roleid, 'contextlevel' => CONTEXT_SYSTEM, 'userid' => $userid));

}

function block_fn_mentor_get_mentees_by_mentor($courseid=0, $filter='', $mentorid=0) {
    global $USER;

    $data = array();
    $allcoursestudents = array();

    if ($filter == 'teacher') {
        if ($courses = block_fn_mentor_get_teacher_courses()) {
            $courseids = implode(",", array_keys($courses));
            $allcoursestudents = block_fn_mentor_get_enrolled_course_users ($courseids);
        }
    }

    if ($filter == 'mentor') {
        if ($mentees = block_fn_mentor_get_mentees($USER->id, $courseid, $allcoursestudents)) {
            $data[$USER->id]['mentor'] = $USER;
            $data[$USER->id]['mentee'] = $mentees;
        }
        return $data;
    }

    if ($mentors = get_role_users(get_config('block_fn_mentor', 'mentor_role_system'),
        context_system::instance(), false, 'u.id, u.firstname, u.lastname', 'u.lastname')) {
        foreach ($mentors as $mentor) {
            if ($mentorid) {
                if ($mentorid <> $mentor->id) {
                    continue;
                }
            }
            if ($mentees = block_fn_mentor_get_mentees($mentor->id, $courseid, $allcoursestudents)) {
                $data[$mentor->id]['mentor'] = $mentor;
                $data[$mentor->id]['mentee'] = $mentees;
            }
        }
    }

    if ($filter == 'teacher') {
        if ($mentees = block_fn_mentor_get_mentees($USER->id, $courseid, array())) {
            $data[$USER->id]['mentor'] = $USER;
            $data[$USER->id]['mentee'] = $mentees;
        }
    }

    return $data;
}

function block_fn_mentor_render_mentees_by_mentor($data, $show) {
    global $DB, $CFG;

    $coursefilter = optional_param('coursefilter', 0, PARAM_INT);

    $html = '';
    foreach ($data as $mentor) {
        $html .= '<div class="mentor"><strong><img class="mentor-img" src="'.
            $CFG->wwwroot.'/blocks/fn_mentor/pix/mentor_bullet.png"> <a class="mentor-profile" href="'.
            $CFG->wwwroot.'/user/profile.php?id='.$mentor['mentor']->id.'" onclick="window.open(\''.
            $CFG->wwwroot.'/user/profile.php?id='.$mentor['mentor']->id.'\', \'\', \'width=800,height=600,toolbar=no,'.
            'location=no,menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes\'); return false;">'.
            $mentor['mentor']->firstname . ' ' . $mentor['mentor']->lastname.'</a> </strong></div>';
        foreach ($mentor['mentee'] as $mentee) {
            $gradesummary = block_fn_mentor_grade_summary($mentee->studentid, $coursefilter);
            if ($gradesummary->timecompleted && $coursefilter) {
                $menteeicon = 'complete_bullet.png';
            } else if (($gradesummary->passed > 0) && ($gradesummary->failed == 0)) {
                $menteeicon = 'mentee_green.png';
            } else if (($gradesummary->passed > 0) && ($gradesummary->failed > 0)) {
                $menteeicon = 'mentee_red_green.png';
            } else if (($gradesummary->passed == 0) && ($gradesummary->failed > 0)) {
                $menteeicon = 'mentee_red.png';
            } else if (($gradesummary->passed == 0) && ($gradesummary->failed == 0)) {
                $menteeicon = 'mentee_red.png';
            }
            if (!$show && !$mentee->enrolled) {
                continue;
            }

            if (!$mentee->enrolled) {
                $menteeicon = 'mentee_gray.png';
                $html .= '<div class="mentee gray"><img class="mentee-img" src="'.$CFG->wwwroot.'/blocks/fn_mentor/pix/'.
                    $menteeicon.'"><a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview.php?menteeid='.
                    $mentee->studentid.'" >' .$mentee->firstname . ' ' . $mentee->lastname . '</a></div>';
            } else {
                $html .= '<div class="mentee"><img class="mentee-img" src="'.$CFG->wwwroot.'/blocks/fn_mentor/pix/'.
                    $menteeicon.'"><a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview.php?menteeid='.
                    $mentee->studentid.'" >' .$mentee->firstname . ' ' . $mentee->lastname . '</a></div>';
            }

        }
    }
    return $html;
}

function block_fn_mentor_get_mentors_by_mentee($courseid=0, $filter='') {

    $data = array();
    $alcoursestudents = array();

    if ($filter == 'teacher') {
        if ($courses = block_fn_mentor_get_teacher_courses()) {
            $courseids = implode(",", array_keys($courses));
            $alcoursestudents = block_fn_mentor_get_enrolled_course_users ($courseids);
        }
    }

    if ($mentees = block_fn_mentor_get_all_mentees($alcoursestudents)) {
        foreach ($mentees as $mentee) {
            if ($mentor = block_fn_mentor_get_mentors($mentee->studentid)) {
                $data[$mentee->studentid]['mentee'] = $mentee;
                $data[$mentee->studentid]['mentor'] = $mentor;
            }
        }
    }

    return $data;
}

function block_fn_mentor_render_mentors_by_mentee($data) {
    global $DB, $CFG;

    $coursefilter = optional_param('coursefilter', 0, PARAM_INT);

    $html = '';
    foreach ($data as $mentee) {

        $gradesummary = block_fn_mentor_grade_summary($mentee['mentee']->studentid, $coursefilter);
        if ($gradesummary->timecompleted && $coursefilter) {
            $menteeicon = 'complete_bullet.png';
        } else if (($gradesummary->passed > 0) && ($gradesummary->failed == 0)) {
            $menteeicon = 'mentee_green.png';
        } else if (($gradesummary->passed > 0) && ($gradesummary->failed > 0)) {
            $menteeicon = 'mentee_red_green.png';
        } else if (($gradesummary->passed == 0) && ($gradesummary->failed > 0)) {
            $menteeicon = 'mentee_red.png';
        } else if (($gradesummary->passed == 0) && ($gradesummary->failed == 0)) {
            $menteeicon = 'mentee_red.png';
        }
        $html .= '<div class="mentee"><strong><img class="mentor-img" src="'.
            $CFG->wwwroot.'/blocks/fn_mentor/pix/'.$menteeicon.'"><a href="'.
            $CFG->wwwroot.'/blocks/fn_mentor/course_overview.php?menteeid='.$mentee['mentee']->studentid.'" >' .
            $mentee['mentee']->firstname . ' ' . $mentee['mentee']->lastname . '</strong></a></div>';

        foreach ($mentee['mentor'] as $mentor) {
            $html .= '<div class="mentor"><img class="mentee-img" src="'.
                $CFG->wwwroot.'/blocks/fn_mentor/pix/mentor_bullet.png"><a  href="'.
                $CFG->wwwroot.'/user/profile.php?id='.$mentor->mentorid.'" onclick="window.open(\''.
                $CFG->wwwroot.'/user/profile.php?id='.$mentor->mentorid.'\', \'\', \'width=800,height=600,toolbar=no,'.
                'location=no,menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes\'); '.
                'return false;" class="mentor-profile" >'.$mentor->firstname . ' ' . $mentor->lastname.'</a></div>';
        }
    }
    return $html;
}

function block_fn_mentor_render_mentees_by_student($menteeid) {
    global $DB, $CFG;

    $html = '';

    $mentee = $DB->get_record('user', array('id' => $menteeid));

    if ($mentors = block_fn_mentor_get_mentors($menteeid)) {
        $html .= '<div class="mentee"><img class="mentor-img" src="'.
            $CFG->wwwroot.'/blocks/fn_mentor/pix/mentee_red.png"><a href="'.
            $CFG->wwwroot.'/blocks/fn_mentor/course_overview.php?menteeid='.$mentee->id.'" >' .
            $mentee->firstname . ' ' . $mentee->lastname . '</a></div>';
        /*
        foreach ($mentors as $mentor) {
            $html .= '<div class="mentor"><img class="mentee-img" src="'.
                $CFG->wwwroot.'/blocks/fn_mentor/pix/mentor_bullet.png"><a class="mentor-profile" href="'.
                $CFG->wwwroot.'/user/profile.php?id='.$mentor->mentorid.'">' .$mentor->firstname . ' ' .
                $mentor->lastname . '</a></div>';
        }*/
    }
    return $html;
}

function block_fn_mentor_assignment_status($mod, $userid) {
    global $CFG, $DB, $SESSION;

    if (isset($SESSION->completioncache)) {
        unset($SESSION->completioncache);
    }

    if ($mod->modname == 'assignment') {
        if (!($assignment = $DB->get_record('assignment', array('id' => $mod->instance)))) {
            return false;
        }
        require_once($CFG->dirroot.'/mod/assignment/type/'.$assignment->assignmenttype.'/assignment.class.php');
        $assignmentclass = "assignment_$assignment->assignmenttype";
        $assignmentinstance = new $assignmentclass($mod->id, $assignment, $mod);

        if (!($submission = $assignmentinstance->get_submission($userid)) || empty($submission->timemodified)) {
            return false;
        }

        switch ($assignment->assignmenttype) {
            case "upload":
                if ($assignment->var4) { // If var4 enable then assignment can be saved.
                    if (!empty($submission->timemodified)
                            && (empty($submission->data2))
                            && (empty($submission->timemarked))) {
                        return 'saved';

                    } else if (!empty($submission->timemodified)
                            && ($submission->data2 = 'submitted')
                            && empty($submission->timemarked)) {
                        return 'submitted';
                    } else if (!empty($submission->timemodified)
                            && ($submission->data2 = 'submitted')
                            && ($submission->grade == -1)) {
                        return 'submitted';
                    }
                } else if (empty($submission->timemarked)) {
                    return 'submitted';
                }
                break;
            case "uploadsingle":
                if (empty($submission->timemarked)) {
                     return 'submitted';
                }
                break;
            case "online":
                if (empty($submission->timemarked)) {
                     return 'submitted';
                }
                break;
            case "offline":
                if (empty($submission->timemarked)) {
                     return 'submitted';
                }
                break;
        }
    } else if ($mod->modname == 'assign') {
        if (!($assignment = $DB->get_record('assign', array('id' => $mod->instance)))) {
            return false;
        }

        $filesubmissionenabled = block_fn_mentor_assign_plugin_config($assignment->id, 'assignsubmission', 'file', 'enabled');
        $onlinetextsubmissionenabled = block_fn_mentor_assign_plugin_config($assignment->id, 'assignsubmission', 'onlinetext', 'enabled');

        if (!$submission = $DB->get_records('assign_submission', array(
            'assignment' => $assignment->id, 'userid' => $userid), 'attemptnumber DESC', '*', 0, 1)) {
            if (!$filesubmissionenabled && !$onlinetextsubmissionenabled) {
                return 'waitinggrade';
            } else {
                return false;
            }
        } else {
            $submission = reset($submission);
        }

        $attemptnumber = $submission->attemptnumber;

        if (($submission->status == 'reopened') && ($submission->attemptnumber > 0)) {
            $attemptnumber = $submission->attemptnumber - 1;
        }

        // No grade assignments.
        if ($assignment->grade == 0) {
            return 'submitted';
        }

        if ($submissionisgraded = $DB->get_records('assign_grades', array(
            'assignment' => $assignment->id, 'userid' => $userid,
            'attemptnumber' => $attemptnumber), 'attemptnumber DESC', '*', 0, 1)) {

            $submissionisgraded = reset($submissionisgraded);
            if ($submissionisgraded->grade > -1) {
                if (($submission->timemodified > $submissionisgraded->timemodified)
                    || ($submission->attemptnumber > $submissionisgraded->attemptnumber)) {
                    $graded = false;
                } else {
                    $graded = true;
                }
            } else {
                $graded = false;
            }
        } else {
            $graded = false;
        }
        // No grade assignments.
        if ($assignment->grade == 0) {
            if ($submission->status == 'new') {
                return false;
            } elseif ($submission->status == 'draft') {
                return 'saved';
            } elseif ($submission->status == 'reopened') {
                return 'submitted';
            } elseif ($submission->status == 'submitted') {
                return 'submitted';
            }
        } else {
            if (!$filesubmissionenabled && !$onlinetextsubmissionenabled) {
                if ($graded) {
                    return 'submitted';
                } else {
                    return 'waitinggrade';
                }
            } elseif ($submission->status == 'draft') {
                if ($graded) {
                    return 'submitted';
                } else {
                    return 'saved';
                }
            } elseif ($submission->status == 'reopened') {
                return 'submitted';
            } elseif ($submission->status == 'submitted') {
                if ($graded) {
                    return 'submitted';
                } else {
                    return 'waitinggrade';
                }
            }
        }
    } else {
        return false;
    }
}

function block_fn_mentor_assign_plugin_config($assignmentid, $subtype = 'assignsubmission', $plugin = 'file', $setting = 'enabled') {
    global $DB;
    $dbparams = array('assignment'=>$assignmentid,
        'subtype'=>$subtype,
        'plugin'=>$plugin,
        'name'=>$setting);
    $result = $DB->get_record('assign_plugin_config', $dbparams, '*', IGNORE_MISSING);
    if ($result) {
        return $result->value;
    }

    return false;
}

function block_fn_mentor_grade_summary($studentid, $courseid=0) {
    global $DB;

    $data = new stdClass();
    $courses = array();
    $coursegrades = array();
    $nogradeassignments = 0;

    if (! $passinggrade = get_config('block_fn_mentor', 'passinggrade')) {
        $passinggrade = 50;
    }

    $gradetotal = array('attempted_grade' => 0,
                         'attempted_max' => 0,
                         'all_max' => 0);

    if ($courseid) {
        $courses[$courseid] = $courseid;
    } else {
        $courses = block_fn_mentor_get_student_courses($studentid);
    }

    if ($courses) {
        foreach ($courses as $id => $value) {

            $course = $DB->get_record('course', array('id' => $id), '*', MUST_EXIST);

            // Available modules for grading.
            $modavailable = array(
                'assign' => '1',
                'quiz' => '1',
                'assignment' => '1',
                'forum' => '1',
            );

            $context = context_course::instance($course->id);

            // Collect modules data.
            $mods = get_course_mods($course->id);

            // Skip some mods.
            foreach ($mods as $mod) {
                if (!isset($modavailable[$mod->modname])) {
                    continue;
                }

                if ($mod->groupingid) {
                    $sqlgrouiping = "SELECT 1
                                      FROM {groupings_groups} gg
                                INNER JOIN {groups_members} gm
                                        ON gg.groupid = gm.groupid
                                     WHERE gg.groupingid = ?
                                       AND gm.userid = ?";
                    if (!$DB->record_exists_sql($sqlgrouiping, array($mod->groupingid, $studentid))) {
                        continue;
                    }
                }
                // Check no grade assignments.
                if ($mod->modname == 'assign') {
                    if ($assignment = $DB->get_record('assign', array('id' => $mod->instance))) {
                        if ($assignment->grade == 0) {
                            if ($submission = $DB->get_records('assign_submission', array(
                                'assignment' => $assignment->id, 'userid' => $studentid), 'attemptnumber DESC', '*', 0, 1)
                            ) {
                                ++$nogradeassignments;
                            }
                        }
                    }
                }

                if (!$gradeitem = $DB->get_record('grade_items',
                    array('itemtype' => 'mod', 'itemmodule' => $mod->modname, 'iteminstance' => $mod->instance))) {
                    continue;
                }

                $gradetotal['all_max'] += $gradeitem->grademax;

                if ($gradegrade = $DB->get_record('grade_grades', array('itemid' => $gradeitem->id, 'userid' => $studentid))) {

                    if ($mod->modname == 'assign') {
                        if ($assigngrades = $DB->get_records('assign_grades', array(
                            'assignment' => $mod->instance, 'userid' => $studentid), 'attemptnumber DESC')) {
                            $assigngrade = reset($assigngrades);
                            if ($assigngrade->grade >= 0) {
                                // Graded.
                                $gradetotal['attempted_grade'] += $gradegrade->finalgrade;
                                $gradetotal['attempted_max'] += $gradeitem->grademax;
                            }
                        }
                    } else {
                        // Graded.
                        $gradetotal['attempted_grade'] += $gradegrade->finalgrade;
                        $gradetotal['attempted_max'] += $gradeitem->grademax;
                    }
                }
            }
        }
    }
    if ($gradetotal['attempted_max']) {
        $attempted = round(($gradetotal['attempted_grade'] / $gradetotal['attempted_max']) * 100);
    } else {
        $attempted = 0;
    }
    if ($gradetotal['all_max']) {
        $all = round(($gradetotal['attempted_grade'] / $gradetotal['all_max']) * 100);
    } else {
        $all = 0;
    }

    $data->attempted = $attempted;
    $data->all = $all;
    $data->passed = 0;
    $data->failed = 0;
    $data->timecompleted = 0;

    if ($courses) {
        foreach ($courses as $id => $value) {
            $sqlcourseaverage = "SELECT gg.id,
                                gg.rawgrademax,
                                gg.finalgrade
                           FROM {grade_items} gi
                           JOIN {grade_grades} gg
                             ON gi.id = gg.itemid
                          WHERE gi.itemtype = ?
                            AND gi.courseid = ?
                            AND gg.userid = ?";
            if ($courseaverage = $DB->get_record_sql($sqlcourseaverage, array('course', $id, $studentid))) {
                $coursegrades[$id] = ($courseaverage->finalgrade / $courseaverage->rawgrademax) * 100;

                if ($coursegrades[$id] >= $passinggrade) {
                    $data->passed++;
                } else {
                    $data->failed++;
                }
            }
            $info = new completion_info($cor = $DB->get_record('course', array('id' => $id)));
            if ($iscomplete = $info->is_course_complete($studentid)) {
                $ccompletion = $DB->get_record('course_completions', array('userid' => $studentid, 'course' => $id));
                $data->timecompleted = $ccompletion->timecompleted;
            }
        }
    }
    if (count($coursegrades)) {
        $data->allcourseaverge = round(array_sum($coursegrades) / count($coursegrades));
    } else {
        $data->allcourseaverge = 0;
    }

    if ($courseid) {

        if (isset($coursegrades[$courseid])) {
            $data->courseaverage = round($coursegrades[$courseid]);
        } else {
            $data->courseaverage = 0;
        }

        $sqlactivity = "SELECT gi.id,
                               gg.finalgrade
                          FROM {grade_items} gi
               LEFT OUTER JOIN {grade_grades} gg
                            ON gi.id = gg.itemid
                         WHERE gi.courseid = ?
                           AND gi.itemtype = ?
                           AND gg.userid = ?";
        if ($gradedavtivities = $DB->get_records_sql($sqlactivity, array($courseid, 'mod', $studentid))) {
            $numofactivities = 0;
            $numofgraded = 0;
            foreach ($gradedavtivities as $gradedavtivity) {
                $numofactivities++;
                if (is_numeric($gradedavtivity->finalgrade)) {
                    $numofgraded++;
                }
            }
            $totalnumofgraded = $nogradeassignments + $numofgraded;
            $data->numofcompleted = "$totalnumofgraded/$numofactivities";
            $data->percentageofcompleted = round(($numofgraded / $numofactivities) * 100);
        } else {
            $data->numofcompleted = "N/A";
            $data->percentageofcompleted = 0;
        }
    }

    return $data;
}

function block_fn_mentor_print_grade_summary ($courseid , $studentid) {
    $html = '';

    $gradesummary = block_fn_mentor_grade_summary($studentid, $courseid);

    $html .= '<table class="mentee-course-overview-grade_table">';
    $html .= '<tr>';
    $html .= '<td class="overview-grade-left" valign="middle">'.get_string('numofcomplete', 'block_fn_mentor').':</td>';
    $html .= '<td class="overview-grade-right grey" valign="middle">'.$gradesummary->numofcompleted.'</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td class="overview-grade-left" valign="middle">'.get_string('currentgrade', 'block_fn_mentor').':</td>';
    $class = ($gradesummary->courseaverage >= 50) ? 'green' : 'red';
    $html .= '<td class="overview-grade-right '.$class.'" valign="middle">'.$gradesummary->courseaverage.'%</td>';
    $html .= '</tr>';
    $html .= '</table>';
    return $html;
}

function block_fn_mentor_get_teacher_courses ($teacherid=0) {
    global $CFG, $DB, $USER;

    if (! $teacherid) {
        $teacherid = $USER->id;
    }

    $sql = "SELECT c.id,
                   c.fullname
              FROM {context} ctx
        INNER JOIN {role_assignments} ra
                ON ctx.id = ra.contextid
        INNER JOIN {course} c
                ON ctx.instanceid = c.id
             WHERE ctx.contextlevel = ?
               AND ra.roleid = ?
               AND ra.userid = ?";

    if ($courses = $DB->get_records_sql($sql, array(50, 3, $teacherid))) {
        return $courses;
    }
    return false;
}

function block_fn_mentor_get_student_courses ($studentid=0) {
    global $CFG, $DB, $USER;

    if (! $studentid) {
        $studentid = $USER->id;
    }

    $studentrole = get_config('block_fn_mentor', 'studentrole');

    $sql = "SELECT c.id,
                   c.fullname
              FROM {context} ctx
        INNER JOIN {role_assignments} ra
                ON ctx.id = ra.contextid
        INNER JOIN {course} c
                ON ctx.instanceid = c.id
             WHERE ctx.contextlevel = ?
               AND ra.roleid = ?
               AND ra.userid = ?";

    if ($courses = $DB->get_records_sql($sql, array(50, $studentrole, $studentid))) {
        return $courses;
    }
    return false;
}

function block_fn_mentor_get_enrolled_course_users ($courseids) {
    global $DB;

    $sql = "SELECT ue.userid
              FROM {course} course
              JOIN {enrol} en
                ON en.courseid = course.id
              JOIN {user_enrolments} ue
                ON ue.enrolid = en.id
             WHERE en.courseid IN (?)";

    if ($enrolledusers = $DB->get_records_sql($sql, array($courseids))) {
        return $enrolledusers;
    }
    return false;
}

function block_fn_mentor_single_button($url, $buttonname, $class='singlebutton', $id='singlebutton') {

    return '<div class="'.$class.'">
            <button class="'.$class.'" id="'.$id.'" url="'.$url.'">'.$buttonname.'</button>
            </div>';
}

function block_fn_mentor_get_course_category_tree($id = 0, $depth = 0) {
    global $DB, $CFG;
    $viewhiddencats = has_capability('moodle/category:viewhiddencategories', context_system::instance());
    $categories = block_fn_mentor_get_child_categories($id);
    $categoryids = array();
    foreach ($categories as $key => &$category) {
        if (!$category->visible && !$viewhiddencats) {
            unset($categories[$key]);
            continue;
        }
        $categoryids[$category->id] = $category;
        if (empty($CFG->maxcategorydepth) || $depth <= $CFG->maxcategorydepth) {
            list($category->categories, $subcategories) = block_fn_mentor_get_course_category_tree_($category->id, $depth + 1);

            foreach ($subcategories as $subid => $subcat) {
                $categoryids[$subid] = $subcat;
            }
            $category->courses = array();
        }
    }

    if ($depth > 0) {
        // This is a recursive call so return the required array.
        return array($categories, $categoryids);
    }

    if (empty($categoryids)) {
        // No categories available (probably all hidden).
        return array();
    }

    // The depth is 0 this function has just been called so we can finish it off.
    $ccselect = ", " . context_helper::get_preload_record_columns_sql('ctx');
    $ccjoin = "LEFT JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = ".CONTEXT_COURSE.")";

    list($catsql, $catparams) = $DB->get_in_or_equal(array_keys($categoryids));
    $sql = "SELECT
            c.id,c.sortorder,c.visible,c.fullname,c.shortname,c.summary,c.category
            $ccselect
            FROM {course} c
            $ccjoin
            WHERE c.category $catsql ORDER BY c.sortorder ASC";
    if ($courses = $DB->get_records_sql($sql, $catparams)) {
        // Loop throught them.
        foreach ($courses as $course) {
            if ($course->id == SITEID) {
                continue;
            }
            context_helper::preload_from_record($course);
            if (!empty($course->visible) || has_capability('moodle/course:viewhiddencourses',
                    context_course::instance($course->id))) {
                $categoryids[$course->category]->courses[$course->id] = $course;
            }
        }
    }
    return $categories;
}

function block_fn_mentor_get_course_category_tree_($id = 0, $depth = 0) {
    global $DB, $CFG;
    $categories = array();
    $categoryids = array();
    $sql = context_helper::get_preload_record_columns_sql('ctx');
    $records = $DB->get_records_sql("SELECT c.*, $sql FROM {course_categories} c ".
        "JOIN {context} ctx on ctx.instanceid = c.id AND ctx.contextlevel = ? WHERE c.parent = ? ORDER BY c.sortorder",
        array(CONTEXT_COURSECAT, $id));
    foreach ($records as $category) {
        context_helper::preload_from_record($category);
        if (!$category->visible && !has_capability('moodle/category:viewhiddencategories',
                context_coursecat::instance($category->id))) {
            continue;
        }
        $categories[] = $category;
        $categoryids[$category->id] = $category;
        if (empty($CFG->maxcategorydepth) || $depth <= $CFG->maxcategorydepth) {
            list($category->categories, $subcategories) = block_fn_mentor_get_course_category_tree_(
                $category->id, $depth + 1);
            foreach ($subcategories as $subid => $subcat) {
                $categoryids[$subid] = $subcat;
            }
            $category->courses = array();
        }
    }

    if ($depth > 0) {
        // This is a recursive call so return the required array.
        return array($categories, $categoryids);
    }

    if (empty($categoryids)) {
        // No categories available (probably all hidden).
        return array();
    }

    // The depth is 0 this function has just been called so we can finish it off.

    list($ccselect, $ccjoin) = context_instance_preload_sql('c.id', CONTEXT_COURSE, 'ctx');
    list($catsql, $catparams) = $DB->get_in_or_equal(array_keys($categoryids));
    $sql = "SELECT
            c.id,c.sortorder,c.visible,c.fullname,c.shortname,c.summary,c.category
            $ccselect
            FROM {course} c
            $ccjoin
            WHERE c.category $catsql ORDER BY c.sortorder ASC";
    if ($courses = $DB->get_records_sql($sql, $catparams)) {
        // Loop throught them.
        foreach ($courses as $course) {
            if ($course->id == SITEID) {
                continue;
            }
            context_helper::preload_from_record($course);
            if (!empty($course->visible) || has_capability('moodle/course:viewhiddencourses',
                    context_course::instance($course->id))) {
                $categoryids[$course->category]->courses[$course->id] = $course;
            }
        }
    }
    return $categories;
}

function block_fn_mentor_get_child_categories($parentid) {
    global $DB;

    $rv = array();
    $sql = context_helper::get_preload_record_columns_sql('ctx');
    $records = $DB->get_records_sql("SELECT c.*, $sql FROM {course_categories} c ".
            "JOIN {context} ctx on ctx.instanceid = c.id AND ctx.contextlevel = ? WHERE c.parent = ? ORDER BY c.sortorder",
            array(CONTEXT_COURSECAT, $parentid));
    foreach ($records as $category) {
        context_helper::preload_from_record($category);
        if (!$category->visible && !has_capability('moodle/category:viewhiddencategories',
                context_coursecat::instance($category->id))) {
            continue;
        }
        $rv[] = $category;
    }
    return $rv;
}

function block_fn_mentor_category_tree_form($structures, $categoryids='', $courseids='') {
    if ($categoryids == '0') {
        $rootcategorychecked = 'checked="checked"';
    } else {
        if ($categoryids || $courseids) {
            $rootcategorychecked = '';
        } else {
            $rootcategorychecked = 'checked="checked"';
        }
    }

    $categoryids = explode(',', $categoryids);
    $courseids = explode(',', $courseids);

    $content = '<ul id="course-category-tree" class="course-category-tree">
               <li>
               <input id="category_0" class="_checkbox" type="checkbox" '.$rootcategorychecked.' name="category_0" value="0">
               <span class="ned-form-course-category">'.get_string('allcategories', 'block_fn_mentor').'</span>';
    $content .= '<ul>';
    foreach ($structures as $structure) {
        $content .= '<li>';
        if (in_array($structure->id, $categoryids)) {
            $content .= block_fn_mentor_checkbox_checked('category_'.$structure->id, 'category_'.$structure->id,
                    '_checkbox', $structure->id) . ' <span class="ned-form-course-category">'. $structure->name . '</span>';
        } else {
            $content .= block_fn_mentor_checkbox('category_'.$structure->id, 'category_'.$structure->id,
                    '_checkbox', $structure->id) . ' <span class="ned-form-course-category">'. $structure->name . '</span>';
        }

        if ($structure->courses) {
            $content .= '<ul>';
            foreach ($structure->courses as $course) {
                if (in_array($course->id, $courseids)) {
                    $content .= html_writer::tag('li',  block_fn_mentor_checkbox_checked('course_'.$course->id,
                            'course_'.$course->id, '_checkbox', $course->id) . ' <span class="ned-form-course">'.
                        $course->fullname.'</span>');
                } else {
                    $content .= html_writer::tag('li',  block_fn_mentor_checkbox('course_'.$course->id,
                            'course_'.$course->id, '_checkbox', $course->id) . ' <span class="ned-form-course">'.
                        $course->fullname.'</span>');
                }
            }
            $content .= '</ul>';
        }
        $content .= block_fn_mentor_sub_category_tree_form($structure, $categoryids, $courseids);
        $content .= '</li>';
    }
    $content .= '</ul>';
    $content .= '</il>';
    $content .= '</ul>';
    return $content;
}

function block_fn_mentor_sub_category_tree_form($structure, $categoryids=null, $courseids=null) {
    $content = "<ul>";
    if ($structure->categories) {
        foreach ($structure->categories as $category) {
            $content .= '<li>';
            if (in_array($category->id, $categoryids)) {
                $content .= block_fn_mentor_checkbox_checked(
                        'category_'.$category->id, 'category_'.$category->id, '_checkbox', $category->id
                    ) . ' <span class="fz_form_course_category">'. $category->name.'</span>';
            } else {
                $content .= block_fn_mentor_checkbox('category_'.$category->id, 'category_'.$category->id,
                        '_checkbox', $category->id
                    ) . ' <span class="fz_form_course_category">'. $category->name.'</span>';
            }
            if ($category->courses) {
                $content .= '<ul>';
                foreach ($category->courses as $course) {
                    if (in_array($course->id, $courseids)) {
                        $content .= html_writer::tag('li', block_fn_mentor_checkbox_checked('course_'.$course->id,
                                'course_'.$course->id, '_checkbox', $course->id
                            ) . ' <span class="fz_form_course">'. $course->fullname.'</span>');
                    } else {
                        $content .= html_writer::tag('li', block_fn_mentor_checkbox('course_'.$course->id, 'course_'.
                                $course->id, '_checkbox', $course->id
                            ) . ' <span class="fz_form_course">'. $course->fullname.'</span>');
                    }
                }
                $content .= '</ul>';
            }
            $content .= block_fn_mentor_sub_category_tree_form($category, $categoryids, $courseids);
            $content .= '</li>';
        }
    }
    $content .= "</ul>";
    return $content;
}

function block_fn_mentor_button($text, $id) {
    return html_writer::tag('p',
        html_writer::empty_tag('input', array(
            'value' => $text, 'type' => 'button', 'id' => $id
        ))
    );
};

function block_fn_mentor_checkbox($name, $id , $class, $value) {
    return html_writer::empty_tag('input', array(
            'value' => $value, 'type' => 'checkbox', 'id' => $id, 'name' => $name, 'class' => $class
        )
    );
}

function block_fn_mentor_checkbox_checked($name, $id , $class, $value) {
    return html_writer::empty_tag('input', array(
            'value' => $value, 'type' => 'checkbox', 'id' => $id, 'name' => $name, 'class' => $class, 'checked' => 'checked'
        )
    );
}

function block_fn_mentor_textinput($name, $id, $class , $value = '') {
    return html_writer::empty_tag('input', array(
            'value' => $value, 'type' => 'text', 'id' => $id, 'name' => $name, 'class' => $class
        )
    );
}

function block_fn_mentor_single_button_form ($class, $url, $hiddens, $buttontext, $onclick='') {

    $hiddeninputs = '';

    if ($hiddens) {
        foreach ($hiddens as $key => $value) {
            $hiddeninputs .= '<input type="hidden" value="'.$value.'" name="'.$key.'"/>';
        }
    }

    $form = '<div class="'.$class.'">
              <form action="'.$url.'" method="post">
                <div>
                  <input type="hidden" value="'.sesskey().'" name="sesskey"/>
                  '.$hiddeninputs.'
                  <input class="singlebutton" onclick="'.$onclick.'" type="submit" value="'.$buttontext.'"/>
                </div>
              </form>
            </div>';

    return $form;
}

function block_fn_mentor_render_notification_rule_table($notification, $number) {
    global $DB;

    $menteeid      = optional_param('menteeid', 0, PARAM_INT);
    $courseid      = optional_param('courseid', 0, PARAM_INT);

    $html = '';
    $html .= '<table class="notification_rule" cellspacing="0">
                 <tr>
                    <td colspan="2" class="notification_rule_ruleno"><strong>'.
        get_string('rule', 'block_fn_mentor').' '.$number.':</strong> '.$notification->name.'</td>
                    <td colspan="2" class="notification_rule_button">';

    $html .= block_fn_mentor_single_button_form (
        'create_new_rule',
        new moodle_url('/blocks/fn_mentor/notification_send.php',
            array('id' => $notification->id, 'action' => 'send', 'sesskey' => sesskey())
        ),
        null, get_string('run_now', 'block_fn_mentor')
    );
    $html .= block_fn_mentor_single_button_form (
        'create_new_rule',
        new moodle_url('/blocks/fn_mentor/notification.php',
            array('id' => $notification->id, 'action' => 'edit')
        ), null, get_string('open', 'block_fn_mentor')
    );
    $html .= block_fn_mentor_single_button_form (
        'create_new_rule',
        new moodle_url('/blocks/fn_mentor/notification_delete.php',
            array('id' => $notification->id, 'action' => 'edit')
        ), null, get_string('delete', 'block_fn_mentor'), 'return confirm(\'Do you want to delete record?\')'
    );

    $html .= '</td>
                  </tr>
                  <tr>
                    <th class="notification_c1" nowrap="nowrap">'.get_string('apply_to', 'block_fn_mentor').'</th>
                    <th class="notification_c2" nowrap="nowrap">'.get_string('when_to_send', 'block_fn_mentor').'</th>
                    <th class="notification_c3" nowrap="nowrap">'.get_string('who_to_send', 'block_fn_mentor').'</th>
                    <th class="notification_c4" nowrap="nowrap">'.get_string('how_often', 'block_fn_mentor').'</th>
                  </tr>
                  <tr>
                    <td class="notification_rule_body notification_c1">';

    if (isset($notification->category)) {
        if ($notification->category == 0) {
            $html .= '<ul class="fn-course-category">';
            $html .= '<li>'.get_string('allcategories', 'block_fn_mentor').'</li>';
            $html .= '</ul>';
        } else if ($categories = $DB->get_records_select('course_categories', 'id IN ('.$notification->category.')')) {
            $html .= '<ul class="fn-course-category">';
            foreach ($categories as $category) {
                $html .= '<li>'.$category->name.'</li>';
            }
            $html .= '</ul>';
        }
    }

    if ($notification->course) {
        if ($courses = $DB->get_records_select('course', 'id IN ('.$notification->course.')')) {
            $html .= '<ul>';
            foreach ($courses as $course) {
                $html .= '<li>'.$course->fullname.'</li>';
            }
            $html .= '</ul>';
        }
    }
    $html .= '</td><td class="notification_rule_body notification_c2">';

    if ($notification->g2 || $notification->g4 || $notification->g6 || $notification->n1 || $notification->n2) {

        $html .= '<ul>';
        if ($notification->g2) {
            $html .= '<li>'.get_string('g2', 'block_fn_mentor').'</li>';
        }
        if ($notification->g4) {
            $html .= '<li>'.get_string('g4', 'block_fn_mentor', $notification->g4_value).'</li>';
        }
        if ($notification->g6) {
            $html .= '<li>'.get_string('g6', 'block_fn_mentor', $notification->g6_value).'</li>';
        }
        if ($notification->n1) {
            $html .= '<li>'.get_string('n1', 'block_fn_mentor', $notification->n1_value).'</li>';
        }
        if ($notification->n2) {
            $html .= '<li>'.get_string('n2', 'block_fn_mentor', $notification->n2_value).'</li>';
        }
        $html .= '</ul>';
    }

    $html .= '</td><td class="notification_rule_body notification_c3" nowrap="nowrap">';

    $mentornotificationtype = array();
    $teachernotificationtype = array();
    $studentnotificationtype = array();

    if ($notification->mentoremail || $notification->mentorsms
        || $notification->studentemail || $notification->studentsms
        || $notification->teacheremail || $notification->teachersms) {

        $html .= '<ul>';
        if ($notification->mentoremail) {
            $mentornotificationtype[] = get_string('email', 'block_fn_mentor');
        }
        if ($notification->mentorsms) {
            $mentornotificationtype[] = get_string('sms', 'block_fn_mentor');
        }
        if ($mentornotificationtype) {
            $html .= '<li>'.get_string('mentornotificationtype', 'block_fn_mentor',
                    implode(', ', $mentornotificationtype)).'</li>';
        }

        if ($notification->studentemail) {
            $studentnotificationtype[] = get_string('email', 'block_fn_mentor');
        }
        if ($notification->studentsms) {
            $studentnotificationtype[] = get_string('sms', 'block_fn_mentor');
        }
        if ($studentnotificationtype) {
            $html .= '<li>'.get_string('studentnotificationtype', 'block_fn_mentor',
                    implode(', ', $studentnotificationtype)).'</li>';
        }

        if ($notification->teacheremail) {
            $teachernotificationtype[] = get_string('email', 'block_fn_mentor');
        }
        if ($notification->teachersms) {
            $teachernotificationtype[] = get_string('sms', 'block_fn_mentor');
        }
        if ($teachernotificationtype) {
            $html .= '<li>'.get_string('teachernotificationtype', 'block_fn_mentor',
                    implode(', ', $teachernotificationtype)).'</li>';
        }

        $html .= '</ul>';
    }

    $apendedmessage = '';
    if ($notification->studentmsgenabled) {
        $apendedmessage .= get_string('dear', 'block_fn_mentor').' '.
            get_string($notification->studentgreeting, 'block_fn_mentor').', <br >'.
            $notification->studentappendedmsg.'<br />';
    }

    if ($notification->mentormsgenabled) {
        $apendedmessage .= get_string('dear', 'block_fn_mentor').' '.
            get_string($notification->mentorgreeting, 'block_fn_mentor').', <br /><br />'.
            $notification->mentorappendedmsg.'<br />';
    }

    if ($notification->teachermsgenabled) {
        $apendedmessage .= get_string('dear', 'block_fn_mentor').' '.
            get_string($notification->teachergreeting, 'block_fn_mentor').', <br /><br />'.
            $notification->teacherappendedmsg.'<br />';
    }

    $html .= '</td>
                    <td class="notification_rule_body notification_c4">'.
        get_string('period', 'block_fn_mentor', $notification->period).'</td>
                  </tr>
                </table>';
    return $html;
}

function block_fn_mentor_last_activity ($studentid) {
    global $DB;

    $lastsubmission = null;
    $lastattempt = null;
    $lastpost = null;

    // Assign.
    $sqlassign = "SELECT s.id,
                         s.timemodified
                    FROM {assign_submission} s
                   WHERE s.userid = ?
                     AND s.status = 'submitted'
                ORDER BY s.timemodified DESC";

    if ($submissions = $DB->get_records_sql($sqlassign, array($studentid))) {
        $submission = reset($submissions);
        $lastsubmission = round(((time() - $submission->timemodified) / (24 * 60 * 60)), 0);
    }

    // Quiz.
    $sqlquiz = "SELECT qa.id,
                       qa.timefinish
                  FROM {quiz_attempts} qa
                 WHERE qa.state = 'finished'
                   AND qa.userid = ?
              ORDER BY qa.timefinish DESC";

    if ($attempts = $DB->get_records_sql($sqlquiz, array($studentid))) {
        $attempt = reset($attempts);
        $lastattempt = round(((time() - $attempt->timefinish) / (24 * 60 * 60)), 0);
    }

    // Forum.
    $sqlforum = "SELECT f.id,
                        f.modified
                   FROM {forum_posts} f
                  WHERE f.userid = ?
               ORDER BY f.modified DESC";

    if ($posts = $DB->get_records_sql($sqlforum, array($studentid))) {
        $post = reset($posts);
        $lastpost = round(((time() - $post->modified) / (24 * 60 * 60)), 0);
    }

    return min($lastsubmission, $lastattempt, $lastpost);
}

function block_fn_mentor_report_outline_print_row($mod, $instance, $result) {
    global $OUTPUT, $CFG;

    $image = "<img src=\"" . $OUTPUT->pix_url('icon', $mod->modname) . "\" class=\"icon\" alt=\"$mod->modfullname\" />";

    echo "<tr>";
    echo "<td valign=\"top\">$image</td>";
    echo "<td valign=\"top\" style=\"width:300\">";

    echo "<a title=\"$mod->modfullname\"  href=\"$CFG->wwwroot/mod/$mod->modname/view.php?id=$mod->id\" ".
        "onclick=\"window.open('$CFG->wwwroot/mod/$mod->modname/view.php?id=$mod->id', '', ".
        "'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,directories=no,".
        "scrollbars=yes,resizable=yes'); return false;\" class=\"\" >".format_string($instance->name, true)."</a></td>";

    echo "<td>&nbsp;&nbsp;&nbsp;</td>";
    echo "<td valign=\"top\">";
    if (isset($result->info)) {
        echo "$result->info";
    } else {
        echo "<p style=\"text-align:center\">-</p>";
    }
    echo "</td>";
    echo "<td>&nbsp;&nbsp;&nbsp;</td>";
    if (!empty($result->time)) {
        $timeago = format_time(time() - $result->time);
        echo "<td valign=\"top\" style=\"white-space: nowrap\">".userdate($result->time)." ($timeago)</td>";
    }
    echo "</tr>";
}

function block_fn_mentor_format_time($totalsecs, $str=null) {

    $totalsecs = abs($totalsecs);

    if (!$str) {  // Create the str structure the slow way.
        $str = new stdClass();
        $str->day   = get_string('day');
        $str->days  = get_string('days');
        $str->hour  = get_string('hour');
        $str->hours = get_string('hours');
        $str->min   = get_string('min');
        $str->mins  = get_string('mins');
        $str->sec   = get_string('sec');
        $str->secs  = get_string('secs');
        $str->year  = get_string('year');
        $str->years = get_string('years');
    }

    $years     = floor($totalsecs / YEARSECS);
    $remainder = $totalsecs - ($years * YEARSECS);
    $days      = floor($remainder / DAYSECS);
    $remainder = $totalsecs - ($days * DAYSECS);
    $hours     = floor($remainder / HOURSECS);
    $remainder = $remainder - ($hours * HOURSECS);
    $mins      = floor($remainder / MINSECS);
    $secs      = $remainder - ($mins * MINSECS);

    $ss = ($secs == 1) ? $str->sec : $str->secs;
    $sm = ($mins == 1) ? $str->min : $str->mins;
    $sh = ($hours == 1) ? $str->hour : $str->hours;
    $sd = ($days == 1) ? $str->day : $str->days;
    $sy = ($years == 1) ? $str->year : $str->years;

    $oyears = '';
    $odays = '';
    $ohours = '';
    $omins = '';
    $osecs = '';

    if ($years) {
        $oyears  = $years .' '. $sy;
    }
    if ($days) {
        $odays  = $days .' '. $sd;
    }
    if ($hours) {
        $ohours = $hours .' '. $sh;
    }
    if ($mins) {
        $omins  = $mins .' '. $sm;
    }
    if ($secs) {
        $osecs  = $secs .' '. $ss;
    }

    if ($years) {
        return trim($oyears);
    }
    if ($days) {
        return trim($odays);
    }
    if ($hours) {
        return trim($ohours);
    }
    if ($mins) {
        return trim($omins);
    }
    if ($secs) {
        return $osecs;
    }
    return get_string('now');
}

function block_fn_mentor_note_print($note, $detail = NOTES_SHOW_FULL) {
    global $CFG, $USER, $DB, $OUTPUT;

    if (!$user = $DB->get_record('user', array('id' => $note->userid))) {
        debugging("User $note->userid not found");
        return;
    }
    if (!$author = $DB->get_record('user', array('id' => $note->usermodified))) {
        debugging("User $note->usermodified not found");
        return;
    }

    $context = context_course::instance($note->courseid);
    $systemcontext = context_system::instance();

    $authoring = new stdClass();
    $authoring->name = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$author->id.'&amp;course='.
        $note->courseid.'">'.fullname($author).'</a>';
    $authoring->date = userdate($note->lastmodified);

    echo '<div class="notepost '. $note->publishstate . 'notepost' .
        ($note->usermodified == $USER->id ? ' ownnotepost' : '')  .
        '" id="note-'. $note->id .'">';

    // Print note head (e.g. author, user refering to, etc).
    if ($detail & NOTES_SHOW_HEAD) {
        echo '<div class="header">';
        echo '<div class="user">';
        echo $OUTPUT->user_picture($user, array('courseid' => $note->courseid));
        echo fullname($user) . '</div>';
        echo '<div class="info">' .
            get_string('bynameondate', 'notes', $authoring) . '</div>';
        echo '</div>';
    }

    // Print note content.
    if ($detail & NOTES_SHOW_BODY) {
        echo '<div class="content">';
        echo format_text($note->content, $note->format, array('overflowdiv' => true));
        echo '</div>';
    }
    echo '</div>';
}

function block_fn_mentor_send_notifications($notificationid=null, $output=false) {
    global $DB;

    $notificationreport = '';
    $time = time();
    $studentroleid = get_config('block_fn_mentor', 'studentrole');
    $teacherroleid = get_config('block_fn_mentor', 'teacherrole');

    if ($notificationid && $notificationid != -1) {
        $notificationrules = $DB->get_records('block_fn_mentor_notific', array('id' => $notificationid));
    } else {
        $notificationrules = $DB->get_records('block_fn_mentor_notific');
    }

    if ($notificationrules) {
        $DB->execute("UPDATE {block_fn_mentor_notific_msg} SET sent = 1");
        foreach ($notificationrules as $notificationrule) {
            if (!$notificationrule->crontime) {
                $notificationrule->crontime = '2000-01-01';
            }
            $date1 = new DateTime($notificationrule->crontime);
            $now = new DateTime(date("Y-m-d"));

            $diff = $now->diff($date1)->format("%a");

            // Check period.
            if (($notificationrule->period > $diff) && !$notificationid) {
                continue;
            }

            if (!($notificationrule->g2)
                && !($notificationrule->g4 && $notificationrule->g4_value)
                && !($notificationrule->g6 && $notificationrule->g6_value)
                && !($notificationrule->n1 && $notificationrule->n1_value)
                && !($notificationrule->n2 && $notificationrule->n2_value) ) {
                continue;
            }

            $courses = array();
            $notificationmessage = array();

            $getcourses = function($category, &$courses) use (&$getcourses) {
                if ($category->courses) {
                    foreach ($category->courses as $course) {
                        $courses[] = $course->id;
                    }
                }
                if ($category->categories) {
                    foreach ($category->categories as $subcat) {
                        $getcourses($subcat, $course);
                    }
                }
            };

            // CATEGORY.
            if (!is_null($notificationrule->category ) && $notificationrule->category >= 0) {
                if ($notificationrule->category === 0) {
                    $notificationcategories = block_fn_mentor_get_child_categories(0);
                } else {
                    $notificationcategories = explode(',', $notificationrule->category);
                }
                foreach ($notificationcategories as $categoryid) {

                    if ($parentcatcourses = $DB->get_records('course', array('category' => $categoryid))) {
                        foreach ($parentcatcourses as $catcourse) {
                            $courses[] = $catcourse->id;
                        }
                    }
                    if ($categorystructure = block_fn_mentor_get_course_category_tree($categoryid)) {
                        foreach ($categorystructure as $category) {

                            if ($category->courses) {
                                foreach ($category->courses as $subcatcourse) {
                                    $courses[] = $subcatcourse->id;
                                }
                            }
                            if ($category->categories) {
                                foreach ($category->categories as $subcategory) {
                                    $getcourses($subcategory, $courses);
                                }
                            }
                        }
                    }
                }
            }

            // COURSE.
            if ($notificationrule->course) {
                $notification = explode(',', $notificationrule->course);
                $courses = array_merge($courses, $notification);
            }
            
            // PREPARE NOTIFICATION FOR EACH COURSES.
            foreach ($courses as $courseid) {
                if ($course = $DB->get_record('course', array('id' => $courseid))) {

                    $context = context_course::instance($course->id);

                    if ($students = get_enrolled_users($context, 'mod/assign:submit', 0, 'u.*', null, 0, 0, true)) {
                        foreach ($students as $student) {
                            if ($student->suspended) {
                                continue;
                            }
                            $message = "";
                            $gradesummary = block_fn_mentor_grade_summary($student->id, $course->id);
                            $lastaccess = 0;

                            $notificationmessage[$student->id][$course->id]['studentname'] = $student->firstname .
                                ' ' . $student->lastname;

                            if ($notificationrule->g2) {
                                $message .= '<li>'.get_string('g2_message', 'block_fn_mentor',
                                        array('firstname' => $student->firstname, 'g2' => $gradesummary->courseaverage)).'</li>';
                                $notificationmessage[$student->id][$course->id]['coursename'] = $course->fullname;
                                $notificationmessage[$student->id][$course->id]['message'] = $message;
                            }
                            if ($notificationrule->g4 && $notificationrule->g4_value) {
                                if ($gradesummary->courseaverage < $notificationrule->g4_value) {
                                    $message .= '<li>'.get_string('g4_message', 'block_fn_mentor',
                                            array('firstname' => $student->firstname,
                                                'g4' => $gradesummary->courseaverage,
                                                'g4_value' => $notificationrule->g3_value)
                                        ).'</li>';
                                    $notificationmessage[$student->id][$course->id]['coursename'] = $course->fullname;
                                    $notificationmessage[$student->id][$course->id]['message'] = $message;
                                }
                            }
                            if ($notificationrule->g6 && $notificationrule->g6_value) {
                                if ($gradesummary->courseaverage > $notificationrule->g6_value) {
                                    $message .= '<li>'.get_string('g6_message', 'block_fn_mentor',
                                            array('firstname' => $student->firstname,
                                                'g6' => $gradesummary->courseaverage,
                                                'g6_value' => $notificationrule->g6_value)
                                        ).'</li>';
                                    $notificationmessage[$student->id][$course->id]['coursename'] = $course->fullname;
                                    $notificationmessage[$student->id][$course->id]['message'] = $message;
                                }
                            }

                            if ($notificationrule->n1 && $notificationrule->n1_value) {
                                if ($student->lastaccess > 0) {
                                    $lastaccess = round(((time() - $student->lastaccess) / (24 * 60 * 60)), 0);
                                }
                                if ($lastaccess >= $notificationrule->n1_value) {
                                    $message .= '<li>'.get_string('n1_message', 'block_fn_mentor',
                                            array('firstname' => $student->firstname, 'n1' => $lastaccess)).'</li>';
                                    $notificationmessage[$student->id][$course->id]['coursename'] = $course->fullname;
                                    $notificationmessage[$student->id][$course->id]['message'] = $message;
                                }
                            }

                            if ($notificationrule->n2 && $notificationrule->n2_value) {
                                $lastactivity = block_fn_mentor_last_activity($student->id);
                                if (is_numeric($lastactivity)) {
                                    if ($lastactivity >= $notificationrule->n2_value) {
                                        $message .= '<li>'.get_string('n2_message', 'block_fn_mentor',
                                                array('firstname' => $student->firstname, 'n2' => $lastactivity)).'</li>';
                                        $notificationmessage[$student->id][$course->id]['coursename'] = $course->fullname;
                                        $notificationmessage[$student->id][$course->id]['message'] = $message;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // SEND EMAILS FOR EACH RULE.
            foreach ($notificationmessage as $studentid => $coursemessages) {

                // STUDENT.
                if (!$student = $DB->get_record('user', array('id' => $studentid))) {
                    continue;
                }

                foreach ($coursemessages as $courseid => $coursemessage) {
                    if (!isset($coursemessage['message'])) {
                        continue;
                    }
                    // TEACHER.
                    if ($notificationrule->teacheremail || $notificationrule->teachersms) {
                        // Course teachers.
                        $sqlteacher = "SELECT u.id,
                                              u.firstname,
                                              u.lastname
                                         FROM {context} ctx
                                   INNER JOIN {role_assignments} ra
                                           ON ctx.id = ra.contextid
                                   INNER JOIN {user} u
                                           ON ra.userid = u.id
                                        WHERE ctx.contextlevel = ?
                                          AND ra.roleid = ?
                                          AND ctx.instanceid = ?";

                        if ($teachers = $DB->get_records_sql($sqlteacher, array(50, $teacherroleid, $courseid))) {
                            foreach ($teachers as $teacher) {
                                if (!$to = $DB->get_record('user', array('id' => $teacher->id))) {
                                    continue;
                                }
                                $rec = new stdClass();
                                $rec->notificationid = $notificationrule->id;
                                $rec->type = 'teacher';
                                $rec->receiverid = $teacher->id;
                                $rec->userid = $studentid;
                                $rec->courseid = $courseid;
                                $rec->message = $coursemessage['message'];
                                $rec->timecreated = $time;
                                $rec->securitykey = md5(uniqid(rand(), true));
                                $rec->sent = 0;
                                $DB->insert_record('block_fn_mentor_notific_msg', $rec);
                            }
                        }
                    }

                    // STUDENT.
                    if ($notificationrule->studentemail || $notificationrule->studentsms) {
                        $rec = new stdClass();
                        $rec->notificationid = $notificationrule->id;
                        $rec->type = 'student';
                        $rec->receiverid = $studentid;
                        $rec->userid = $studentid;
                        $rec->courseid = $courseid;
                        $rec->message = $coursemessage['message'];
                        $rec->timecreated = $time;
                        $rec->securitykey = md5(uniqid(rand(), true));
                        $rec->sent = 0;
                        $DB->insert_record('block_fn_mentor_notific_msg', $rec);
                    }

                    // MENTOR.
                    if ($notificationrule->mentoremail || $notificationrule->mentorsms) {
                        $mentors = block_fn_mentor_get_mentors($studentid);
                        foreach ($mentors as $mentor) {
                            if (!$to = $DB->get_record('user', array('id' => $mentor->mentorid))) {
                                continue;
                            }
                            $rec = new stdClass();
                            $rec->notificationid = $notificationrule->id;
                            $rec->type = 'mentor';
                            $rec->receiverid = $mentor->mentorid;
                            $rec->userid = $studentid;
                            $rec->courseid = $courseid;
                            $rec->message = $coursemessage['message'];
                            $rec->timecreated = $time;
                            $rec->securitykey = md5(uniqid(rand(), true));
                            $rec->sent = 0;
                            $DB->insert_record('block_fn_mentor_notific_msg', $rec);
                        }
                    }
                }
            }
            $updatesql = "UPDATE {block_fn_mentor_notific} SET crontime=? WHERE id=?";
            $DB->execute($updatesql, array(date("Y-m-d"), $notificationrule->id));
        } // END OF EACH NOTIFICATION.

        $notificationreport .= block_fn_mentor_group_messages();
    }

    if ($output) {
        return $notificationreport;
    }
}

function block_fn_mentor_group_messages () {
    global $DB;

    $site = get_site();
    $supportuser = core_user::get_support_user();
    $subject = get_string('progressreportfrom', 'block_fn_mentor', format_string($site->fullname));
    $notificationreport = '';

    $sqlgroup = "SELECT n.id,
                        n.notificationid,
                        n.type,
                        n.receiverid,
                        n.userid,
                        n.courseid,
                        n.message,
                        n.securitykey,
                        n.timecreated,
                        n.sent
                   FROM {block_fn_mentor_notific_msg} n
                  WHERE n.sent = 0
                    AND n.type IN ('mentor', 'student', 'teacher')
               GROUP BY n.receiverid,
                        n.type,
                        n.notificationid,
                        n.timecreated";

    if ($groups = $DB->get_records_sql($sqlgroup)) {
        foreach ($groups as $group) {
            $emailbody = '';
            $notification = $DB->get_record('block_fn_mentor_notific', array('id' => $group->notificationid));

            if ($messages = $DB->get_records('block_fn_mentor_notific_msg',
                array('receiverid' => $group->receiverid, 'type' => $group->type, 'timecreated' => $group->timecreated,
                    'notificationid' => $group->notificationid, 'sent' => '0'
                ), 'userid ASC'
            )) {
                $appendedmessage = '';
                $greetings = '';
                if (($group->type == 'student') && $notification->studentmsgenabled) {
                    $appendedmessage = $notification->studentappendedmsg;
                }
                if (($group->type == 'mentor') && $notification->mentormsgenabled) {
                    $appendedmessage = $notification->mentorappendedmsg;
                }
                if (($group->type == 'teacher') && $notification->teachermsgenabled) {
                    $appendedmessage = $notification->teacherappendedmsg;
                }

                $emailbody .= $appendedmessage . '<hr />';

                foreach ($messages as $message) {
                    $student = $DB->get_record('user', array('id' => $message->userid));
                    $course = $DB->get_record('course', array('id' => $message->courseid));

                    $emailbody .= get_string('progressreportfrom', 'block_fn_mentor', format_string($site->fullname)).' <br />';
                    $emailbody .= get_string('student', 'block_fn_mentor').':  <strong>' .
                        $student->firstname . ' ' . $student->lastname . '</strong> <br /><hr />';

                    $emailbody .= get_string('course', 'block_fn_mentor').': ' . $course->fullname . ' <br />';
                    $emailbody .= '<ul>' . $message->message . '</ul>';

                    $menteeurl = new moodle_url('/blocks/fn_mentor/course_overview.php', array('menteeid' => $student->id));
                    $emailbody .= '<p>' . get_string('linktomentorpage', 'block_fn_mentor', $menteeurl->out()) . '</p><hr />';

                    $message->sent = 1;
                    $DB->update_record('block_fn_mentor_notific_msg', $message);
                }

                $emailbody .= get_string('automatedmessage', 'block_fn_mentor', format_string($site->fullname));

                $rec = new stdClass();
                $rec->notificationid = $group->notificationid;
                $rec->type = 'email';
                $rec->receiverid = $group->receiverid;
                $rec->message = $emailbody;
                $rec->timecreated = time();
                $rec->securitykey = md5(uniqid(rand(), true));
                $rec->sent = 0;
                $nid = $DB->insert_record('block_fn_mentor_notific_msg', $rec);

                $messageurl = new moodle_url('/blocks/fn_mentor/notification_message.php',
                    array('id' => $nid, 'key' => $rec->securitykey)
                );

                $tinyurl = block_fn_mentor_get_tiny_url($messageurl->out(false));

                $smsbody = get_string('progressreportfrom', 'block_fn_mentor', format_string($site->fullname))."\n".
                           get_string('clickhere', 'block_fn_mentor', $tinyurl);

                $sent = 0;
                if ($to = $DB->get_record('user', array('id' => $group->receiverid))) {
                    if (($group->type == 'student') && $notification->studentmsgenabled) {
                        if ($notification->studentgreeting == 'firstname') {
                            $greetings = get_string('dearfirstname', 'block_fn_mentor', $to->firstname);
                        } else if ($notification->studentgreeting == 'rolename') {
                            $greetings = get_string('dearrolename', 'block_fn_mentor',
                                get_string($group->type, 'block_fn_mentor'));
                        } else if ($notification->studentgreeting == 'sirmadam') {
                            $greetings = get_string('dearsirmadam', 'block_fn_mentor');
                        }
                    }
                    if (($group->type == 'mentor') && $notification->mentormsgenabled) {
                        if ($notification->mentorgreeting == 'firstname') {
                            $greetings = get_string('dearfirstname', 'block_fn_mentor', $to->firstname);
                        } else if ($notification->mentorgreeting == 'rolename') {
                            $greetings = get_string('dearrolename', 'block_fn_mentor',
                                get_string($group->type, 'block_fn_mentor'));
                        } else if ($notification->mentorgreeting == 'sirmadam') {
                            $greetings = get_string('dearsirmadam', 'block_fn_mentor');
                        }
                    }
                    if (($group->type == 'teacher') && $notification->teachermsgenabled) {
                        if ($notification->teachergreeting == 'firstname') {
                            $greetings = get_string('dearfirstname', 'block_fn_mentor', $to->firstname);
                        } else if ($notification->teachergreeting == 'rolename') {
                            $greetings = get_string('dearrolename', 'block_fn_mentor',
                                get_string($group->type, 'block_fn_mentor'));
                        } else if ($notification->teachergreeting == 'sirmadam') {
                            $greetings = get_string('dearsirmadam', 'block_fn_mentor');
                        }
                    }
                    $emailsent = $group->type . 'email';
                    $smssent = $group->type . 'sms';
                    if ($notification->$emailsent) {
                        $emailbody = $greetings.$emailbody;
                        if (email_to_user($to, $supportuser, $subject, '', $emailbody)) {
                            $sent = 1;
                            $notificationreport .= $to->firstname . ' ' .
                                $to->lastname . get_string('emailsent', 'block_fn_mentor') . '<br>';
                        } else {
                            $notificationreport .= '<span class="fn_mentor_error">'.$to->firstname . ' ' .
                                $to->lastname . get_string('emailerror', 'block_fn_mentor') . '</span><br>';
                        }
                    }

                    if ($notification->$smssent) {
                        if (block_fn_mentor_sms_to_user($to, $supportuser, $subject, '', $smsbody)) {
                            $sent = 1;
                            $notificationreport .= $to->firstname . ' ' . $to->lastname .
                                get_string('smssent', 'block_fn_mentor') . '<br>';
                        } else {
                            $notificationreport .= '<span class="fn_mentor_error">'.$to->firstname . ' ' .
                                $to->lastname . get_string('smserror', 'block_fn_mentor') . '</span><br>';
                        }
                    }
                    $rec->id = $nid;
                    $rec->sent = $sent;
                    $DB->update_record('block_fn_mentor_notific_msg', $rec);
                }
            }
        }
    }
    if (!$notificationreport) {
        return get_string('nomessagessent', 'block_fn_mentor').'<br>';
    }
    return $notificationreport;
}

function block_fn_mentor_sms_to_user ($user, $from, $subject, $messagetext, $messagehtml = '') {
    global $DB;

    $sqlphonenumber = "SELECT t1.shortname, t2.data
						 FROM {user_info_field} t1 , {user_info_data}  t2
					    WHERE t1.id = t2.fieldid
						  AND t1.shortname = 'mobilephone'
						  AND t2.userid = ?";

    if ($phonenumber = $DB->get_record_sql($sqlphonenumber, array($user->id))) {
        $smsnumber = $phonenumber->data;

        $sqlprovider = "SELECT t1.shortname, t2.data
     					  FROM {user_info_field} t1 , {user_info_data}  t2
					 	 WHERE t1.id = t2.fieldid
						   AND t1.shortname = 'mobileprovider'
						   AND t2.userid = ?";

        if ($phoneprovider = $DB->get_record_sql($sqlprovider, array($user->id))) {
            $smsproviderfull = $phoneprovider->data;
            $smsproviderarray = explode('~', $smsproviderfull);
            $smsprovider = $smsproviderarray[1];
            $user->email = $smsnumber . $smsprovider;

            return email_to_user($user, $from, get_string('notification', 'block_fn_mentor'), strip_tags($messagehtml), '');
        }

    }
    return false;
}

function block_fn_mentor_get_tiny_url($url) {
    $ch = curl_init();
    $timeout = 5;
    curl_setopt($ch, CURLOPT_URL, 'http://tinyurl.com/api-create.php?url='.$url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

function block_fn_mentor_get_selected_courses($category, &$filtercourses) {
    if ($category->courses) {
        foreach ($category->courses as $course) {
            $filtercourses[] = $course->id;
        }
    }
    if ($category->categories) {
        foreach ($category->categories as $subcat) {
            block_fn_mentor_get_selected_courses($subcat, $course);
        }
    }
};

function block_fn_mentor_embed ($text, $id) {
    return html_writer::tag('p',
        html_writer::empty_tag('input', array(
            'value' => $text, 'type' => 'button', 'id' => $id
        ))
    );
};

function block_fn_mentor_activity_progress($course, $menteeid, $modgradesarray) {
    global $CFG, $DB, $SESSION;

    // Count grade to pass activities.
    $sqlgradetopass = "SELECT COUNT(gi.id)
                         FROM {grade_items} gi
                        WHERE gi.courseid = ?
                          AND gi.gradepass > ?";

    $numgradetopass = $DB->count_records_sql($sqlgradetopass, array($course->id, 0));

    if (isset($SESSION->completioncache)) {
        unset($SESSION->completioncache);
    }

    $progressdata = new stdClass();
    $progressdata->content = new stdClass;
    $progressdata->content->items = array();
    $progressdata->content->icons = array();
    $progressdata->content->footer = '';
    $progressdata->completed = 0;
    $progressdata->total = 0;
    $progressdata->percentage = 0;
    $completedactivities = 0;
    $incompletedactivities = 0;
    $savedactivities = 0;
    $notattemptedactivities = 0;
    $waitingforgradeactivities = 0;

    $info = new completion_info($course);
    $progressdata->timecompleted = 0;
    if ($iscomplete = $info->is_course_complete($menteeid)) {
        $ccompletion = $DB->get_record('course_completions', array('userid' => $menteeid, 'course' => $course->id));
        $progressdata->timecompleted = $ccompletion->timecompleted;
    }

    $completion = new completion_info($course);
    $activities = $completion->get_activities();

    if ($completion->is_enabled() && !empty($completion)) {

        foreach ($activities as $activity) {
            if (!$activity->visible) {
                continue;
            }
            // Skip non tracked activities.
            if ($activity->completion == COMPLETION_TRACKING_NONE) {
                continue;
            }
            if (! isset($modgradesarray[$activity->modname])) {
                continue;
            }
            // Don't count it if you can't see it.
            $mcontext = context_module::instance($activity->id);
            if (!$activity->visible && !has_capability('moodle/course:viewhiddenactivities', $mcontext)) {
                continue;
            }
            $instance = $DB->get_record($activity->modname, array("id" => $activity->instance));
            $item = $DB->get_record('grade_items',
                array("itemtype" => 'mod', "itemmodule" => $activity->modname, "iteminstance" => $activity->instance)
            );

            $libfile = $CFG->dirroot . '/mod/' . $activity->modname . '/lib.php';

            if (file_exists($libfile)) {
                require_once($libfile);
                $gradefunction = $activity->modname . "_get_user_grades";

                if ((($activity->modname != 'forum') || ($instance->assessed > 0))
                    && isset($modgradesarray[$activity->modname])) {

                    if (function_exists($gradefunction)) {

                        if (($activity->modname == 'quiz') || ($activity->modname == 'forum')) {

                            if ($grade = $gradefunction($instance, $menteeid)) {
                                if ($item->gradepass > 0) {
                                    if ($grade[$menteeid]->rawgrade >= $item->gradepass) {
                                        // Passed
                                        ++$completedactivities;
                                    } else {
                                        // Failed
                                        ++$incompletedactivities;
                                    }
                                } else {
                                    // Graded
                                    ++$completedactivities;
                                }
                            } else {
                                // Ungraded
                                ++$notattemptedactivities;
                            }
                        } else if ($modstatus = block_fn_mentor_assignment_status($activity, $menteeid, true)) {
                            switch ($modstatus) {
                                case 'submitted':
                                    if ($instance->grade == 0) {
                                        // Graded
                                        ++$completedactivities;
                                    } elseif ($grade = $gradefunction($instance, $menteeid)) {
                                        if ($item->gradepass > 0) {
                                            if ($grade[$menteeid]->rawgrade >= $item->gradepass) {
                                               // Passed
                                                ++$completedactivities;
                                            } else {
                                                // Fail.
                                                ++$incompletedactivities;
                                            }
                                        } else {
                                            // Graded
                                            ++$completedactivities;
                                        }
                                    }
                                    break;

                                case 'saved':
                                    // Saved
                                    ++$savedactivities;
                                    break;

                                case 'waitinggrade':
                                    // Waiting for grade
                                    ++$waitingforgradeactivities;
                                    break;
                            }
                        } else {
                            // Ungraded
                            ++$notattemptedactivities;
                        }
                    }
                }
            }
        }

        if ($incompletedactivities == 0) {
            $completed = get_string('completed', 'block_fn_mentor');
            $incompleted = get_string('incompleted', 'block_fn_mentor');
        } else {
            $completed = get_string('completed2', 'block_fn_mentor');
            $incompleted = get_string('incompleted2', 'block_fn_mentor');
        }
        $draft = get_string('draft', 'block_fn_mentor');
        $notattempted = get_string('notattempted', 'block_fn_mentor');
        $waitingforgrade = get_string('waitingforgrade', 'block_fn_mentor');

        // Completed.
        $progressdata->content->items[] = '<a  href="' . $CFG->wwwroot . '/blocks/fn_mentor/listactivities.php?id=' .
            $course->id . '&menteeid=' . $menteeid . '&show=completed' . '&navlevel=top" onclick="window.open(\''.
            $CFG->wwwroot.'/blocks/fn_mentor/listactivities.php?id='.$course->id.'&menteeid='.$menteeid.
            '&show=completed'.'&navlevel=top\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,'.
            'status=no,directories=no,scrollbars=yes,resizable=yes\'); return false;" class="">' .
            $completedactivities . ' '.$completed.'</a>';

        $progressdata->content->icons[] = '<img src="' . $CFG->wwwroot .
            '/blocks/fn_mentor/pix/completed.gif" class="icon" alt="">';

        // Incomplete.
        if ($numgradetopass && $incompletedactivities > 0) {
            $progressdata->content->items[] = '<a  href="' . $CFG->wwwroot . '/blocks/fn_mentor/listactivities.php?id=' .
                $course->id . '&menteeid=' . $menteeid . '&show=incompleted' . '&navlevel=top" onclick="window.open(\''.
                $CFG->wwwroot.'/blocks/fn_mentor/listactivities.php?id='.$course->id.'&menteeid='.$menteeid.
                '&show=incompleted'.'&navlevel=top\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,'.
                'copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes\'); return false;" class="">' .
                $incompletedactivities . ' '.$incompleted.'</a>';

            $progressdata->content->icons[] = '<img src="' . $CFG->wwwroot .
                '/blocks/fn_mentor/pix/incomplete.gif" class="icon" alt="">';
        }

        // Draft.
        if ($savedactivities > 0) {
            $progressdata->content->items[] = '<a  href="' . $CFG->wwwroot . '/blocks/fn_mentor/listactivities.php?id=' .
                $course->id . '&menteeid=' . $menteeid . '&show=draft' . '&navlevel=top" onclick="window.open(\''.
                $CFG->wwwroot.'/blocks/fn_mentor/listactivities.php?id='.$course->id.'&menteeid='.$menteeid.
                '&show=draft'.'&navlevel=top\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,'.
                'status=no,directories=no,scrollbars=yes,resizable=yes\'); return false;" class="">' .
                $savedactivities . ' '.$draft.'</a>';

            $progressdata->content->icons[] = '<img src="' . $CFG->wwwroot .
                '/blocks/fn_mentor/pix/saved.gif" class="icon" alt="">';
        }

        // Not Attempted.
        $progressdata->content->items[] = '<a  href="' . $CFG->wwwroot . '/blocks/fn_mentor/listactivities.php?id=' .
            $course->id . '&menteeid=' . $menteeid . '&show=notattempted' . '&navlevel=top" onclick="window.open(\''.
            $CFG->wwwroot.'/blocks/fn_mentor/listactivities.php?id='.$course->id.'&menteeid='.$menteeid.
            '&show=notattempted'.'&navlevel=top\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,'.
            'copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes\'); return false;" class="">' .
            $notattemptedactivities . ' '.$notattempted.'</a>';

        $progressdata->content->icons[] = '<img src="' . $CFG->wwwroot .
            '/blocks/fn_mentor/pix/notattempted.gif" class="icon" alt="">';

        // Waiting for grade.
        $progressdata->content->items[] = '<a  href="' . $CFG->wwwroot . '/blocks/fn_mentor/listactivities.php?id=' .
            $course->id . '&menteeid=' . $menteeid . '&show=waitingforgrade' . '&navlevel=top" onclick="window.open(\''.
            $CFG->wwwroot.'/blocks/fn_mentor/listactivities.php?id='.$course->id.'&menteeid='.$menteeid.
            '&show=waitingforgrade'.'&navlevel=top\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,'.
            'copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes\'); return false;" class="">' .
            $waitingforgradeactivities . ' '.$waitingforgrade.'</a>';

        $progressdata->content->icons[] = '<img src="' . $CFG->wwwroot .
            '/blocks/fn_mentor/pix/unmarked.gif" class="icon" alt="">';

        $progressdata->completed = $completedactivities + $incompletedactivities;
        $progressdata->total = $completedactivities + $incompletedactivities + $savedactivities + $notattemptedactivities + $waitingforgradeactivities;

        $sql = "SELECT gg.id,
                       gg.rawgrademax,
                       gg.finalgrade
                  FROM {grade_items} gi
                  JOIN {grade_grades} gg
                    ON gi.id = gg.itemid
                 WHERE gi.itemtype = ?
                   AND gi.courseid = ?
                   AND gg.userid = ?";
        if ($courseaverage = $DB->get_record_sql($sql, array('course', $course->id, $menteeid))) {
            $progressdata->percentage = ($courseaverage->finalgrade / $courseaverage->rawgrademax) * 100;
        }

    } else {
        $progressdata->content->items[] = get_string('completionnotenabled', 'block_fn_mentor');
        $progressdata->content->icons[] = '<img src="' . $CFG->wwwroot .
            '/blocks/fn_mentor/pix/warning.gif" class="icon" alt="">';
    }

    return $progressdata;
}

function block_fn_mentor_simplegradebook($course, $menteeuser, $modgradesarray) {
    global $CFG, $DB;

    $unsubmitted = 0;

    $cobject = new stdClass();
    $cobject->course = $course;

    $simplegradebook = array();
    $weekactivitycount = array();
    $simplegradebook[$menteeuser->id]['name'] = $menteeuser->firstname.' '.substr($menteeuser->lastname, 0, 1).'.';

    // Collect modules data.
    $modnames = get_module_types_names();
    $modnamesplural = get_module_types_names(true);
    $modinfo = get_fast_modinfo($course->id);

    $mods = $modinfo->get_cms();

    $modnamesused = $modinfo->get_used_module_names();

    $modarray = array($mods, $modnames, $modnamesplural, $modnamesused);

    $cobject->mods = &$mods;
    $cobject->modnames = &$modnames;
    $cobject->modnamesplural = &$modnamesplural;
    $cobject->modnamesused = &$modnamesused;
    $cobject->sections = &$sections;

    // FIND CURRENT WEEK.
    $courseformatoptions = course_get_format($course)->get_format_options();
    $coursenumsections = $courseformatoptions['numsections'];
    $courseformat = course_get_format($course)->get_format();

    $timenow = time();
    $weekdate = $course->startdate;
    $weekdate += 7200;

    $weekofseconds = 604800;
    $courseenddate = $course->startdate + ($weekofseconds * $coursenumsections);

    // Calculate the current week based on today's date and the starting date of the course.
    $currentweek = ($timenow > $course->startdate) ? (int) ((($timenow - $course->startdate) / $weekofseconds) + 1) : 0;
    $currentweek = min($currentweek, $coursenumsections);

    // Search through all the modules, pulling out grade data.
    $sections = $modinfo->get_section_info_all();

    $upto = count($sections);

    for ($i = 0; $i < $upto; $i++) {
        $numberofitem = 0;
        if (isset($sections[$i])) {
            $section = $sections[$i];
            if ($section->sequence) {
                $sectionmods = explode(",", $section->sequence);
                foreach ($sectionmods as $sectionmod) {
                    if (empty($mods[$sectionmod])) {
                        continue;
                    }

                    $mod = $mods[$sectionmod];
                    // Skip non tracked activities.
                    if ($mod->completion == COMPLETION_TRACKING_NONE) {
                        continue;
                    }

                    if (! isset($modgradesarray[$mod->modname])) {
                        continue;
                    }
                    // Don't count it if you can't see it.
                    $mcontext = context_module::instance($mod->id);
                    if (!$mod->visible && !has_capability('moodle/course:viewhiddenactivities', $mcontext)) {
                        continue;
                    }

                    $instance = $DB->get_record($mod->modname, array("id" => $mod->instance));
                    $item = $DB->get_record('grade_items', array("itemtype" => 'mod', "itemmodule" => $mod->modname,
                            "iteminstance" => $mod->instance)
                    );

                    $libfile = $CFG->dirroot . '/mod/' . $mod->modname . '/lib.php';
                    if (file_exists($libfile)) {
                        require_once($libfile);
                        $gradefunction = $mod->modname . "_get_user_grades";

                        if ((($mod->modname != 'forum') || ($instance->assessed > 0))
                            && isset($modgradesarray[$mod->modname])) {

                            if (function_exists($gradefunction)) {
                                ++$numberofitem;

                                $image = "<a target='_blank' href=\"$CFG->wwwroot/mod/$mod->modname/view.php?id=$mod->id\" ".
                                    "title=\"$instance->name\"><img border=0 valign=absmiddle ".
                                    "src=\"$CFG->wwwroot/mod/$mod->modname/pix/icon.png\" height=16 ".
                                    "width=16 ALT=\"$mod->modfullname\"></a>";

                                $weekactivitycount[$i]['mod'][] = $image;
                                $weekactivitycount[$i]['modname'][] = $instance->name;
                                foreach ($simplegradebook as $key => $value) {

                                    if (($mod->modname == 'quiz')||($mod->modname == 'forum')) {

                                        if ($grade = $gradefunction($instance, $key)) {
                                            if ($item->gradepass > 0) {
                                                if ($grade[$key]->rawgrade >= $item->gradepass) {
                                                    $simplegradebook[$key]['grade'][$i][$mod->id] = 'marked.gif'; // Passed.
                                                    $simplegradebook[$key]['avg'][] = array(
                                                        'grade' => $grade[$key]->rawgrade,
                                                        'grademax' => $item->grademax
                                                    );
                                                } else {
                                                    $simplegradebook[$key]['grade'][$i][$mod->id] = 'incomplete.gif'; // Fail.
                                                    $simplegradebook[$key]['avg'][] = array(
                                                        'grade' => $grade[$key]->rawgrade,
                                                        'grademax' => $item->grademax
                                                    );
                                                }
                                            } else {
                                                // Graded (grade-to-pass is not set).
                                                $simplegradebook[$key]['grade'][$i][$mod->id] = 'graded_.gif';
                                                $simplegradebook[$key]['avg'][] = array(
                                                    'grade' => $grade[$key]->rawgrade,
                                                    'grademax' => $item->grademax
                                                );
                                            }
                                        } else {
                                            $simplegradebook[$key]['grade'][$i][$mod->id] = 'ungraded.gif';
                                            if ($unsubmitted) {
                                                $simplegradebook[$key]['avg'][] = array(
                                                    'grade' => 0, 'grademax' => $item->grademax
                                                );
                                            }
                                        }
                                    } else if ($modstatus = block_fn_mentor_assignment_status($mod, $key, true)) {
                                        switch ($modstatus) {
                                            case 'submitted':
                                                if ($instance->grade == 0) {
                                                    $simplegradebook[$key]['grade'][$i][$mod->id] = 'graded_.gif';
                                                } elseif ($grade = $gradefunction($instance, $key)) {
                                                    if ($item->gradepass > 0) {
                                                        if ($grade[$key]->rawgrade >= $item->gradepass) {
                                                            $simplegradebook[$key]['grade'][$i][$mod->id] = 'marked.gif';// Passed.
                                                            $simplegradebook[$key]['avg'][] = array(
                                                                'grade' => $grade[$key]->rawgrade, 'grademax' => $item->grademax
                                                            );
                                                        } else {
                                                            // Fail.
                                                            $simplegradebook[$key]['grade'][$i][$mod->id] = 'incomplete.gif';
                                                            $simplegradebook[$key]['avg'][] = array(
                                                                'grade' => $grade[$key]->rawgrade, 'grademax' => $item->grademax
                                                            );
                                                        }
                                                    } else {
                                                        // Graded (grade-to-pass is not set).
                                                        $simplegradebook[$key]['grade'][$i][$mod->id] = 'graded_.gif';
                                                        $simplegradebook[$key]['avg'][] = array(
                                                            'grade' => $grade[$key]->rawgrade, 'grademax' => $item->grademax
                                                        );
                                                    }
                                                }
                                                break;

                                            case 'saved':
                                                $simplegradebook[$key]['grade'][$i][$mod->id] = 'saved.gif';
                                                break;

                                            case 'waitinggrade':
                                                $simplegradebook[$key]['grade'][$i][$mod->id] = 'unmarked.gif';
                                                break;
                                        }
                                    } else {
                                        $simplegradebook[$key]['grade'][$i][$mod->id] = 'ungraded.gif';
                                        if ($unsubmitted) {
                                            $simplegradebook[$key]['avg'][] = array('grade' => 0, 'grademax' => $item->grademax);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        $weekactivitycount[$i]['numofweek'] = $numberofitem;
    }

    return array($simplegradebook, $weekactivitycount, $courseformat);
}

function block_fn_mentor_generate_report(progress_bar $progressbar = null) {
    global $DB;
    $inprogress = get_config('block_fn_mentor', 'inprogress');
    $reportdate = get_config('block_fn_mentor', 'reportdate');

    $modgradesarray = array(
        'assign' => 'assign.submissions.fn.php',
        'quiz' => 'quiz.submissions.fn.php',
        'assignment' => 'assignment.submissions.fn.php',
        'forum' => 'forum.submissions.fn.php',
    );

    if ($inprogress && ((time() - $reportdate) < 10 * 60)) {
        return;
    } else {
        set_config('inprogress', 1, 'block_fn_mentor');
    }

    $time = time();
    if (!$students = block_fn_mentor_get_all_students('', true)) {
        return;
    }
    $numberofstudents = count($students);
    $counter = 0;
    $sql = "TRUNCATE TABLE {block_fn_mentor_report_data}";
    $DB->execute($sql);

    foreach ($students as $student) {
        $counter++;
        if ($enrolledcourses = enrol_get_all_users_courses($student->id, true , 'id,fullname,shortname', 'fullname ASC')) {
            foreach ($enrolledcourses as $key => $enrolledcourse) {
                $course = $DB->get_record('course', array('id' => $enrolledcourse->id));
                $progressdata = block_fn_mentor_activity_progress($course, $student->id, $modgradesarray);
                $progressdata->completed;
                $progressdata->total;
                $percentageofcompletion = 0;

                if ($progressdata->total) {
                    $percentageofcompletion = ($progressdata->completed / $progressdata->total) * 100;
                }
                $groupids = '';
                $sql = "SELECT g.id
                          FROM {groups} g
                          JOIN {groups_members} gm
                            ON g.id = gm.groupid
                         WHERE g.courseid = ?
                           AND gm.userid = ?";
                if ($groups = $DB->get_records_sql($sql, array(SITEID, $student->id))) {
                    $groupids = implode(',', array_keys($groups));
                }

                $mentorids = '';
                if ($mentors = block_fn_mentor_get_mentors($student->id)) {
                    $arrmentor = array();
                    foreach ($mentors as $mentor) {
                        $arrmentor[] = $mentor->mentorid;
                    }
                    $mentorids = implode(',', $arrmentor);
                }

                $rec = new stdClass();
                $rec->userid = $student->id;
                $rec->courseid = $course->id;
                $rec->completionrate = $percentageofcompletion;
                $rec->passinggrade = $progressdata->percentage;
                $rec->mentors = $mentorids;
                $rec->groups = $groupids;
                $rec->timemodified = time();
                $rec->id = $DB->insert_record('block_fn_mentor_report_data', $rec);
            }
        }
        if (!is_null($progressbar)) {
            $donepercent = floor($counter / $numberofstudents * 90);
            $progressbar->update_full($donepercent, "$counter of $numberofstudents students");
        }
    }

    $filter = '';
    $params = array();
    if ($settingcourses = block_fn_mentor_get_setting_courses()) {
        list($sqlfilter, $params) = $DB->get_in_or_equal($settingcourses);
        $filter = " AND rd.courseid {$sqlfilter}";
    }

    // Pivot part.
    $sql = "SELECT DISTINCT rd.courseid FROM {block_fn_mentor_report_data} rd WHERE rd.deleted = 0".$filter;
    $fieldscreate = '';
    $fieldsdata = '';
    if ($fields = $DB->get_records_sql($sql, $params)) {
        foreach ($fields as $field) {
            $fieldscreate .= " `completion".$field->courseid."` decimal(10,2) NOT NULL DEFAULT '0.00',\n";
            $fieldscreate .= " `passing".$field->courseid."` decimal(10,2) DEFAULT NULL,\n";
            $fieldsdata .= " MAX(CASE WHEN td.courseid=".
                $field->courseid." THEN td.completionrate ELSE '-1' END) completion".$field->courseid.",\n";
            $fieldsdata .= " MAX(CASE WHEN td.courseid=".
                $field->courseid." THEN td.passinggrade ELSE '-1' END) passing".$field->courseid.",\n";
        }
    }
    $sqldrop = "DROP TABLE {block_fn_mentor_report_pvt}";

    $sqlcreate = "CREATE TABLE `{block_fn_mentor_report_pvt}` (
          `id` bigint(11) NOT NULL AUTO_INCREMENT,
          `userid` bigint(11) NOT NULL,
          `mentors` text,
          `groups` text,
          `courses` text,
          ".$fieldscreate."
          PRIMARY KEY (`id`),
          UNIQUE KEY `ix_user` (`userid`) USING BTREE
        ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8";

    $sqldata = "SELECT td.id, td.groups, td.mentors, GROUP_CONCAT(td.courseid) courses, ".
        $fieldsdata." td.userid FROM {block_fn_mentor_report_data} td GROUP BY td.userid";

    $DB->execute($sqldrop);
    $DB->execute($sqlcreate);
    purge_all_caches();
    if ($rows = $DB->get_records_sql($sqldata)) {
        $numofrecords = count($rows);
        $counter = 0;
        foreach ($rows as $row) {
            ++$counter;
            $DB->insert_record('block_fn_mentor_report_pvt', $row);
            if (!is_null($progressbar)) {
                $donepercent = 90 + floor($counter / $numofrecords * 10);
                $progressbar->update_full($donepercent, "$counter of $numofrecords records");
            }
        }
    }
    if (!is_null($progressbar)) {
        $progressbar->update_full(100, get_string('completed', 'block_fn_mentor'));
    }
    set_config('reportdate', time(), 'block_fn_mentor');
    set_config('inprogress', 0, 'block_fn_mentor');
    return;
}

function block_fn_mentor_footer() {
    global $OUTPUT;

    $output = '';

    $pluginman = core_plugin_manager::instance();
    $pluginfo = $pluginman->get_plugin_info('block_fn_mentor');

    $output = html_writer::div(
        html_writer::div(
            html_writer::link(
                'http://ned.ca/mentor-manager',
                get_string('pluginname', 'block_fn_mentor'),
                array('target' => '_blank')
            ),
            'mentormanagercontainer-footer-left'
        ).
        html_writer::div(
            get_string('version', 'block_fn_mentor').': '.
            html_writer::span($pluginfo->versiondb, 'mentormanager-version'),
            'mentormanagercontainer-footer-center'
        ).
        html_writer::div(
            html_writer::link(
                'http://ned.ca',
                html_writer::img($OUTPUT->pix_url('ned_26', 'block_fn_mentor'), 'NED'),
                array('target' => '_blank')
            ),
            'mentormanagercontainer-footer-right'
        ),
        'mentormanagercontainer-footer'
    );
    return $output;
}

function block_fn_mentor_get_setting_courses () {
    global $DB;

    $filtercourses = array();

    if ($configcategory = get_config('block_fn_mentor', 'category')) {
        $selectedcategories = explode(',', $configcategory);
        foreach ($selectedcategories as $categoryid) {

            if ($parentcatcourses = $DB->get_records('course', array('category' => $categoryid))) {
                foreach ($parentcatcourses as $catcourse) {
                    $filtercourses[] = $catcourse->id;
                }
            }
            if ($categorystructure = block_fn_mentor_get_course_category_tree($categoryid)) {
                foreach ($categorystructure as $category) {

                    if ($category->courses) {
                        foreach ($category->courses as $subcatcourse) {
                            $filtercourses[] = $subcatcourse->id;
                        }
                    }
                    if ($category->categories) {
                        foreach ($category->categories as $subcategory) {
                            block_fn_mentor_get_selected_courses($subcategory, $filtercourses);
                        }
                    }
                }
            }
        }
    }

    if ($configcourse = get_config('block_fn_mentor', 'course')) {
        $selectedcourses = explode(',', $configcourse);
        $filtercourses = array_merge($filtercourses, $selectedcourses);
    }

    return $filtercourses;
}

function block_fn_mentor_teacher_link ($userid, $lastaccess) {
    global $DB, $CFG;

    if (!$user = $DB->get_record('user', array('id' => $userid))) {
        return '';
    }
    return '<div><a onclick="window.open(\'' . $CFG->wwwroot . '/user/profile.php?id=' .
    $user->id . '\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,'.
    'status=no,directories=no,scrollbars=yes,resizable=yes\'); return false;" href="' . $CFG->wwwroot .
    '/user/profile.php?id=' . $user->id . '">' . $user->firstname . ' ' . $user->lastname .
    '</a><a onclick="window.open(\'' . $CFG->wwwroot . '/message/index.php?id=' . $user->id .
    '\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,'.
    'directories=no,scrollbars=yes,resizable=yes\'); return false;" href="' . $CFG->wwwroot .
    '/user/profile.php?id=' . $user->id . '"><img src="' . $CFG->wwwroot .
    '/blocks/fn_mentor/pix/email.png"></a><br />' .
    '<span class="mentee-lastaccess">' . $lastaccess . '</span></div>';
}

function block_fn_mentor_modal_win($modalid, $linktext, $coursename, $sidelable, $list) {

    return '<br>
        <a href="#'.$modalid.'" role="button" data-toggle="modal">'.$linktext.'</a>
        <div id="'.$modalid.'" class="modal hide fade" tabindex="-1" role="dialog" aria-labelledby="'.$modalid.'Label" aria-hidden="true">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
            <h3 id="'.$modalid.'Label">'.$coursename.'</h3>
          </div>
          <div class="modal-body">
            <table class="modaltbl">
              <tr>
                <td valign="top">'.$sidelable.'</td>
                <td valign="top">'.$list.'</td>
              </tr>
            </table>
          </div>
        </div>';
}

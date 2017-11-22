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
// Connect to an external DB for log files.
require_once($CFG->dirroot.'/blocks/fn_mentor/classes/ext_db/db_connect.php');

define('BLOCK_FN_MENTOR_MESSAGE_SEND_ALL', 0);
define('BLOCK_FN_MENTOR_MESSAGE_SEND_APPENDED', 1);

function block_fn_mentor_get_all_students($filter = '', $selectedcoursesonly = false, $sessionfilter = false) {
    global $DB;

    $studentrole = get_config('block_fn_mentor', 'studentrole');
    $wherecondions = '';

    $params = array();
    $params['deleted'] = 0;
    $params['suspended'] = 0;
    $params['roleid'] = $studentrole;

    if ($filter) {
        $wherecondions = " AND CONCAT(u.firstname, ' ', u.lastname) LIKE '%".$filter."%'";
    }

    $join = '';
    $filter = '';
    if ($selectedcoursesonly) {
        if ($settingcourses = block_fn_mentor_get_setting_courses()) {
            list($sqlfilter, $paramcourses) = $DB->get_in_or_equal($settingcourses, SQL_PARAMS_NAMED, 'crs');
            $params = array_merge($params, $paramcourses);
            $join = "INNER JOIN {context} ctx ON ra.contextid = ctx.id";
            $filter = "AND ctx.instanceid {$sqlfilter}";
        }
    }
    $extrasql = '';
    if ($sessionfilter) {
        $ufiltering = new fn_user_filtering(null, null, null, 'mentee');
        list($extrasql, $extraparams) = $ufiltering->get_sql_filter('', $params);
        if ($extrasql) {
            $extrasql = 'AND '.$extrasql;
            $params = $extraparams;
        }
    }

    $sql = "SELECT u.id,
                   u.firstname,
                   u.lastname,
                   u.email
              FROM {role_assignments} ra
        INNER JOIN {user} u
                ON ra.userid = u.id
                   $join
             WHERE u.deleted = :deleted
               AND u.suspended = :suspended
               AND ra.roleid = :roleid
                   $extrasql
                   $wherecondions
                   $filter
          GROUP BY ra.userid
          ORDER BY u.lastname ASC";

    $everyone = $DB->get_records_sql($sql, $params);

    return $everyone;
}

function block_fn_mentor_get_students_without_mentor($filter = '', $sessionfilter = false) {
    global $DB;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }

    $studentroleid = get_config('block_fn_mentor', 'studentrole');

    $wherecondions = '';

    if ($filter) {
        $wherecondions = " AND CONCAT(u.firstname, ' ', u.lastname) LIKE '%".$filter."%'";
    }

    $extrasql = '';
    if ($sessionfilter) {
        $ufiltering = new fn_user_filtering(null, null, null, 'mentee');
        list($extrasql, $params) = $ufiltering->get_sql_filter();
        if ($extrasql) {
            $extrasql = 'AND '.$extrasql;
        }
    } else {
        $params = array();
    }

    $params['contextlevel'] = CONTEXT_USER;
    $params['roleid2'] = $mentorroleid;
    $params['deleted'] = 0;
    $params['suspended'] = 0;
    $params['roleid'] = $studentroleid;
    $params['numofmentor'] = 0;

    $sql = "SELECT u.id, u.firstname, u.lastname,
                   (SELECT COUNT(1)
                      FROM {context} ctx
                      JOIN {role_assignments} ra2
                        ON ctx.id = ra2.contextid
                     WHERE ctx.contextlevel = :contextlevel
                       AND ra2.roleid = :roleid2
                       AND ctx.instanceid = ra.userid) numofmentor
              FROM {role_assignments} ra
              JOIN {user} u
                ON ra.userid = u.id
             WHERE u.deleted = :deleted
               AND u.suspended = :suspended
               AND ra.roleid = :roleid
                   $extrasql
                   $wherecondions
          GROUP BY ra.userid
            HAVING numofmentor = :numofmentor
          ORDER BY u.lastname ASC";

    return $DB->get_records_sql($sql, $params);
}

function block_fn_mentor_is_mentee($userid) {
    global $DB;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }

    $sql = "SELECT ra.id,
                   ra.userid mentorid,
                   ctx.instanceid studentid
              FROM {context} ctx
        INNER JOIN {role_assignments} ra
                ON ctx.id = ra.contextid
             WHERE ctx.contextlevel = ?
               AND ra.roleid = ?
               AND ctx.instanceid = ?";

    return $DB->record_exists_sql($sql, array(CONTEXT_USER, $mentorroleid, $userid));
}

function block_fn_mentor_is_mentor($userid) {
    global $DB;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_system')) {
        return false;
    }

    $sql = "SELECT ra.id,
                   ra.userid mentorid,
                   ctx.instanceid studentid
              FROM {context} ctx
        INNER JOIN {role_assignments} ra
                ON ctx.id = ra.contextid
             WHERE ra.roleid = ?
               AND ra.userid = ?";

    return $DB->record_exists_sql($sql, array($mentorroleid, $userid));

}

function block_fn_mentor_get_mentors_without_mentee($filter = '', $sessionfilter = false) {
    global $DB;

    if (!$mentorroleid = get_config('block_fn_mentor', 'mentor_role_system')) {
        return false;
    }

    if (!$muserroleid = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }

    $wherecondions = '';

    if ($filter) {
        $wherecondions = " AND CONCAT(u.firstname, ' ', u.lastname) LIKE '%" . $filter . "%'";
    }

    $extrasql = '';
    if ($sessionfilter) {
        $ufiltering = new fn_user_filtering(null, null, null, 'mentor');
        list($extrasql, $params) = $ufiltering->get_sql_filter();
        if ($extrasql) {
            $extrasql = 'AND '.$extrasql;
        }
    } else {
        $params = array();
    }

    $params['contextlevel'] = CONTEXT_USER;
    $params['roleid2'] = $muserroleid;
    $params['deleted'] = 0;
    $params['suspended'] = 0;
    $params['roleid'] = $mentorroleid;
    $params['numofmentee'] = 0;

    $sql = "SELECT u.id, u.firstname, u.lastname,
                   (SELECT COUNT(1)
                      FROM {context} ctx
                      JOIN {role_assignments} ra2
                        ON ctx.id = ra2.contextid
                     WHERE ctx.contextlevel = :contextlevel
                       AND ra2.roleid = :roleid2
                       AND ra2.userid = ra.userid) numofmentee
              FROM {role_assignments} ra
              JOIN {user} u
                ON ra.userid = u.id
             WHERE u.deleted = :deleted
               AND u.suspended = :suspended
               AND ra.roleid = :roleid
                   $extrasql
                   $wherecondions
          GROUP BY ra.userid
            HAVING numofmentee = :numofmentee
          ORDER BY u.lastname ASC";

    return $DB->get_records_sql($sql, $params);


}

function block_fn_mentor_get_mentors_with_mentee() {
    global $DB;

    if (! $mentorroleiduser = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }

    $sql = "SELECT u.id, 
                   CONCAT(u.firstname, u.lastname) fullname 
              FROM {context} ctx 
        INNER JOIN {role_assignments} ra 
                ON ctx.id = ra.contextid 
        INNER JOIN {user} u 
                ON ra.userid = u.id 
             WHERE ctx.contextlevel = ? 
               AND ra.roleid = ? 
               AND u.deleted = ? 
          GROUP BY u.id
          ORDER BY u.lastname ASC";

    if ($mentors = $DB->get_records_sql_menu($sql, array(CONTEXT_USER, $mentorroleiduser, 0))) {
        return $mentors;
    }

    return array();
}

function block_fn_mentor_get_mentors_without_groups($filter = '', $sessionfilter = false) {
    global $DB;

    if (!$mentorroleid = get_config('block_fn_mentor', 'mentor_role_system')) {
        return false;
    }

    $wherecondions = '';

    if ($filter) {
        $wherecondions = " AND CONCAT(u.firstname, ' ', u.lastname) LIKE '%" . $filter . "%'";
    }

    $extrasql = '';
    if ($sessionfilter) {
        $ufiltering = new fn_user_filtering(null, null, null, 'mentor');
        list($extrasql, $params) = $ufiltering->get_sql_filter();
        if ($extrasql) {
            $extrasql = 'AND '.$extrasql;
        }
    } else {
        $params = array();
    }

    $params['deleted'] = 0;
    $params['suspended'] = 0;
    $params['roleid'] = $mentorroleid;
    $params['numofgroups'] = 0;

    $sql = "SELECT u.id,
                   u.firstname,
                   u.lastname,
                   (SELECT Count(1) FROM {block_fn_mentor_group_mem} gm
                     WHERE gm.userid = u.id AND gm.role = 'M') numofgroups
              FROM {role_assignments} ra
        INNER JOIN {user} u
                ON ra.userid = u.id
             WHERE u.deleted = :deleted
               AND u.suspended = :suspended
               AND ra.roleid = :roleid
                   $extrasql
                   $wherecondions
          GROUP BY ra.userid
            HAVING numofgroups = :numofgroups
          ORDER BY u.lastname ASC";

    return $DB->get_records_sql($sql, $params);
}

function block_fn_mentor_get_all_mentees($studentids='', $groupid=0) {
    global $DB;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }
    if ($groupid && $groupmetees = block_fn_mentor_get_group_members($groupid, 'U')) {
        list($insql, $params) = $DB->get_in_or_equal(array_keys($groupmetees), SQL_PARAMS_NAMED, 'usr');
        $insql = "AND ctx.instanceid ".$insql;
    } else {
        $insql = '';
        $params = array();
    }

    $params['roleid'] = $mentorroleid;
    $params['contextlevel'] = CONTEXT_USER;

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
                   WHERE ctx.contextlevel = :contextlevel
                     AND ra.roleid = :roleid
                         {$insql}
                ORDER BY u.lastname ASC";

    $stuwithmentor = array();

    if ($studentswithmentor = $DB->get_records_sql($sqlmentor, $params)) {
        foreach ($studentswithmentor as $key => $value) {
            $stuwithmentor[$value->studentid] = $value;
        }
    }

    if ($studentids) {
        $stuwithmentor = array_intersect_key($stuwithmentor, $studentids);
    }
    return $stuwithmentor;
}

function block_fn_mentor_get_all_mentors($filter = '', $sessionfilter = false, $filtertype = 'mentor') {
    global $DB;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_system')) {
        return false;
    }

    $filtersql = '';
    if ($filter) {
        $filtersql = "AND CONCAT(u.firstname, ' ', u.lastname) LIKE '%{$filter}%'";
    }

    $extrasql = '';
    if ($sessionfilter) {
        $ufiltering = new fn_user_filtering(null, null, null, $filtertype);
        list($extrasql, $params) = $ufiltering->get_sql_filter();
        if ($extrasql) {
            $extrasql = 'AND '.$extrasql;
        }
    } else {
        $params = array();
    }

    $params['deleted'] = 0;
    $params['suspended'] = 0;
    $params['roleid'] = $mentorroleid;

    $sql = "SELECT u.id,
                   u.firstname,
                   u.lastname,
                   u.email
              FROM {role_assignments} ra
        INNER JOIN {user} u
                ON ra.userid = u.id
             WHERE u.deleted = :deleted
               AND u.suspended = :suspended
               AND ra.roleid = :roleid
                   $filtersql
                   $extrasql
          GROUP BY u.id
          ORDER BY u.lastname ASC";

    $everyone = $DB->get_records_sql($sql, $params);

    return $everyone;
}

function block_fn_mentor_get_all_users($filter = '', $sessionfilter = false) {
    global $DB;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_system')) {
        return false;
    }

    $filtersql = '';
    if ($filter) {
        $filtersql = "AND CONCAT(u.firstname, ' ', u.lastname) LIKE '%{$filter}%'";
    }

    $extrasql = '';
    if ($sessionfilter) {
        $ufiltering = new fn_user_filtering(null, null, null, 'mentor');
        list($extrasql, $params) = $ufiltering->get_sql_filter();
        if ($extrasql) {
            $extrasql = 'AND '.$extrasql;
        }
    } else {
        $params = array();
    }

    $params['deleted'] = 0;
    $params['suspended'] = 0;
    $params['userid'] = 1;
    $params['roleid'] = $mentorroleid;
    $params['contextlevel'] = CONTEXT_SYSTEM;

    $sql = "SELECT u.id,
                   u.firstname,
                   u.lastname
              FROM {user} u
             WHERE u.deleted = :deleted
               AND u.suspended = :suspended
               AND u.id > :userid
                   $extrasql
                   $filtersql
               AND u.id NOT IN (SELECT ra.userid
                                  FROM {role_assignments} ra
                                  JOIN {context} cx
                                    ON ra.contextid = cx.id
                                 WHERE ra.roleid = :roleid
                                   AND cx.contextlevel = :contextlevel)
          ORDER BY u.lastname ASC";

    $everyone = $DB->get_records_sql($sql, $params);

    return $everyone;
}

function block_fn_mentor_get_mentees($mentorid, $courseid=0, $studentids = '', $groupid=0, $sessionfilter = false) {
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
    if ($groupid && $groupmetees = block_fn_mentor_get_group_members($groupid, 'U')) {
        list($insql, $params) = $DB->get_in_or_equal(array_keys($groupmetees), SQL_PARAMS_NAMED, 'usr');
        $insql = "AND ctx.instanceid ".$insql;
    } else {
        $insql = '';
        $params = array();
    }


    $extrasql = '';
    $extraparams = array();
    if ($sessionfilter) {
        $ufiltering = new fn_user_filtering(null, null, null, 'mentee');
        list($extrasql, $extraparams) = $ufiltering->get_sql_filter();
        if ($extrasql) {
            $extrasql = 'AND '.$extrasql;
            $params += $extraparams;
        }
    }

    $params['roleid'] = $mentorroleid;
    $params['userid'] = $mentorid;
    $params['contextlevel'] = CONTEXT_USER;

    $sql = "SELECT ctx.instanceid AS studentid,
                   u.firstname,
                   u.lastname
              FROM {role_assignments} ra
        INNER JOIN {context} ctx
                ON ra.contextid = ctx.id
        INNER JOIN {user} u
                ON ctx.instanceid = u.id
             WHERE ra.roleid = :roleid
               AND ra.userid = :userid
               AND ctx.contextlevel = :contextlevel
                   $extrasql
                   {$insql}
          ORDER BY u.lastname ASC";

    $mentees = $DB->get_records_sql($sql, $params);

    if ($coursestudents) {
        $mentees = array_intersect_key($mentees, $coursestudents);
    }

    if ($studentids) {
        $mentees = array_intersect_key($mentees, $studentids);
    }


    if ($gm = $DB->record_exists('block_fn_mentor_group_mem', array('teamleader' => 1, 'userid' => $mentorid, 'role' => 'M'))) {
        $sql = "SELECT gm2.userid studentid, u.firstname, u.lastname 
                  FROM {block_fn_mentor_group_mem} gm 
                  JOIN {block_fn_mentor_group_mem} gm2 
                    ON gm.groupid = gm2.groupid
                   AND gm2.role = 'U' 
                  JOIN {user} u 
                    ON gm2.userid = u.id
                 WHERE gm.userid = :mentorid 
                   AND gm.role = 'M' 
                   AND gm.teamleader = 1
                       $extrasql";
        if ($groupmentees = $DB->get_records_sql($sql, $extraparams + array('mentorid' => $mentorid))) {
            $mentees = $mentees + $groupmentees;
        }
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


function block_fn_mentor_get_group_mentees($mentorid, $groupid) {
    global $DB;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }

    $sql = "SELECT ctx.instanceid AS studentid,
                   u.firstname,
                   u.lastname
              FROM {role_assignments} ra
        INNER JOIN {context} ctx
                ON ra.contextid = ctx.id
        INNER JOIN {user} u
                ON ctx.instanceid = u.id
        INNER JOIN {block_fn_mentor_group_mem} gm
                ON u.id = gm.userid
             WHERE ra.roleid = ?
               AND ra.userid = ?
               AND ctx.contextlevel = ?
               AND gm.role = ?
               AND gm.groupid = ?
          ORDER BY u.lastname ASC";

    $mentees = $DB->get_records_sql($sql, array($mentorroleid, $mentorid, CONTEXT_USER, 'U', $groupid));

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

function block_fn_mentor_get_mentees_by_mentor($courseid=0, $filter='', $mentorid=0, $groupid=0) {
    global $USER, $DB;

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

    if ($groupid && $groupmentors = block_fn_mentor_get_group_members($groupid, 'M')) {
        list($extrawheretest, $whereorsortparams) = $DB->get_in_or_equal(array_keys($groupmentors), SQL_PARAMS_NAMED, 'usr');
        $extrawheretest = 'u.id '.$extrawheretest;
    } else {
        $extrawheretest = '';
        $whereorsortparams = array();
    }

    if ($mentors = get_role_users(get_config('block_fn_mentor', 'mentor_role_system'),
        context_system::instance(), false, 'u.id, u.firstname, u.lastname', 'u.lastname', true, '', '', '',
        $extrawheretest, $whereorsortparams)) {
        foreach ($mentors as $mentor) {
            if ($mentorid) {
                if ($mentorid <> $mentor->id) {
                    continue;
                }
            }
            if ($mentees = block_fn_mentor_get_mentees($mentor->id, $courseid, $allcoursestudents, $groupid)) {
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
        $html .= '<div class="mentor"><strong><a class="mentor-profile" href="'.
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
                $menteeicon = 'mentee_orange.png';
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
            $menteeicon = 'mentee_orange.png';
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
    }
    return $html;
}

/* Add modules to check for course_overview.php,
 * course_overview_single.php, simple_gradebook.php,
 * listactivities.php, lib functions to make it easier
 * to add extra mods.
 * @author Sheilla Rindahl <snorton@erdc.k12.mn.us>
 * @return array modlist
 */
function get_graded_mods() {
	$modlist = array(
    'assign' => 'assign.submissions.fn.php',
    'quiz' => 'quiz.submissions.fn.php',
    'assignment' => 'assignment.submissions.fn.php',
    'forum' => 'forum.submissions.fn.php',
	// Add extra Moodle mods 
	'lesson' => 'lesson.submissions.fn.php',
	'scorm' => 'scorm.submissions.fn.php',
	'lti' => 'lti.submissions.fn.php',
	// Add extra Moodle plugins graded... 
	// The below functions are used fn_marking and need to be developed if using fn_marking plugin.
	'hsuforum' => 'hsuforum.submissions.fn.php',
	'journal' => 'journal.submissions.fn.php',
	'hvp' => 'hvp.submissions.fn.php',
	'collaborate' => 'collaborate.submissions.fn.php',
    );
	return $modlist;
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

function block_fn_mentor_assign_plugin_config($assignmentid, $subtype = 'assignsubmission',
                                              $plugin = 'file', $setting = 'enabled') {
    global $DB;
    $dbparams = array(
        'assignment' => $assignmentid,
        'subtype' => $subtype,
        'plugin' => $plugin,
        'name' => $setting
    );
    $result = $DB->get_record('assign_plugin_config', $dbparams, '*', IGNORE_MISSING);
    if ($result) {
        return $result->value;
    }

    return false;
}

function block_fn_mentor_quiz_status($mod, $userid) {
	//SRINDHL Add manually graded quizzes in the waiting for grade counts.
	 global $DB, $SESSION;
    if (isset($SESSION->completioncache)) {
        unset($SESSION->completioncache);
    }
    if ($mod->modname == 'quiz') {
		
		if (!($quiz = $DB->get_record('quiz', array('id' => $mod->instance)))) {
            return false;
        }
	    $attempts = $DB->get_records('quiz_attempts', array(
            'quiz' => $quiz->id, 'userid' => $userid), 'attempt DESC', '*', 0, 1);
	    if ($attempts) {
			foreach($attempts as $id=>$attempt) {
				
				if ($attemptisgraded = $DB->get_records('quiz_grades', array(
					'quiz' => $quiz->id, 'userid' => $userid))) {

					$attemptisgraded = reset($attemptisgraded);
					if ($attemptisgraded->grade > -1) {
						if (($attempt->timemodified > $attemptisgraded->timemodified)) {
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
						if (($quiz->grade == 0) && ($attempt->state == 'finished')) {
							return 'submitted';
						}
						
						if ($attempt->sumgrades === NULL) {
							if ($attempt->state == 'abandoned') {
								return false;
							} elseif ($attempt->state == 'overdue') {
								return false;
							} elseif ($attempt->state == 'inprogress') {
								return 'saved';
							} elseif ($attempt->state == 'finished') {
								return 'waitinggrade';
							}
						} else {
							if ($attempt->state == 'inprogress') {
								if ($graded) {
									return 'submitted';
								} else {
									return 'saved';
								}
							} elseif ($attempt->state == 'finished') {
								if ($graded) {
									return 'submitted';
								}
							}
						}
			}
		} else {
			return false;
		}
	} else {
		return false;
	}
}


function block_fn_mentor_lesson_status($mod, $userid, $course) {
	//SRINDHL Add manually graded essays in the waiting for grade counts.
	 global $CFG, $DB, $SESSION;
	 require_once("$CFG->libdir/gradelib.php");
    if (isset($SESSION->completioncache)) {
        unset($SESSION->completioncache);
    }
    if ($mod->modname == 'lesson') {
		if (!($lesson = $DB->get_record('lesson', array('id' => $mod->instance)))) {
            return false;
        }	
        // Check to see if it an incomplete attempt.
        $sql = "SELECT *
                      FROM {lesson_timer}
                     WHERE lessonid = :lessonid
                       AND userid = :userid
                     ORDER by starttime desc";
        $params = array('lessonid' => $lesson->id, 'userid' => $userid);	
        $timer = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);	
	   // Get any grades for the lesson. 
	   $grades = grade_get_grades($course->id, 'mod', 'lesson', $lesson->id, $userid);  
	   if (((empty($grades->items[0]->grades)) && (!$timer)) || ($lesson->practice == 1)) { 	   
		   return false;
	   } else if ((empty($grades->items[0]->grades)) && (!empty($timer))) {			  
		 return 'saved';
	   } else {
            // Detrmine the status.
            if ($timer) {

               $grade = reset($grades->items[0]->grades);
				// No grade lessons.
			   if (($lesson->grade == 0) || ($lesson->practice == 1)) {
				   if($timer->completed == 1) {
					   return 'submitted';
				   } else {
					   return 'saved';
				   }
			   }
				if($timer->completed == 1) {					
					if ($grade->grade) {
						//var_dump($grade);
						$attempts = $DB->get_records('lesson_attempts', array(
						   'lessonid' => $lesson->id, 'userid' => $userid), 'answerid DESC');
						if($attempts) {
						   $i = 0;
						   $count = count($attempts);
						   $attempt = array_values($attempts);
							for($i = 0; $i < $count; ++$i) {
								
								// check for ungraded lesson essay answers.
								$useranswer = $attempt[$i]->useranswer;
								if((strpos($useranswer, 'stdClass') !== false) && (strpos($useranswer, 'graded') !== false))
								if($answer = unserialize($useranswer)) {
									if($answer->graded == 0) {
										return 'waitinggrade';   
									} 
								}
								continue;
							}
							
						} 
						return 'submitted';
		            } 
			    } else {
				       return 'saved';
		        }
			} else {
				return false;
			}
		}
		return false;
	}
		return false;
	
}

function block_fn_mentor_journal_status($mod, $userid, $course) {
	//SRINDHL Add journal entries in the waiting for grade counts.
	 global $CFG, $DB, $SESSION;
    if (isset($SESSION->completioncache)) {
        unset($SESSION->completioncache);
    }
    if ($mod->modname == 'journal') {
		
		if (!($journal = $DB->get_record('journal', array('id' => $mod->instance)))) {
            return false;
        }
	    $entries = $DB->get_records('journal_entries', array(
            'journal' => $journal->id, 'userid' => $userid), 'id DESC', '*', 0, 1);
		// Get any grades for the lesson. 
	   $grades = grade_get_grades($course->id, 'mod', 'journal', $journal->id, $userid);  
	    if ($entries) {
			$grade = reset($grades->items[0]->grades);
			foreach($entries as $id=>$attempt) {
				if ($grade->grade) {
					if ($grade->grade > -1) {
						if (($attempt->modified > $grade->dategraded)) {
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
							
				// No grade journals.
				if ($journal->grade == 0) {
					return 'submitted';
				}
				
				if ($attempt->rating === NULL || $grade->grade === NULL) {
						return 'waitinggrade';
				} else {
						if ($graded) {
							return 'submitted';
						}
				}
			}
		} else {
			return false;
		}
	} else {
		return false;
	}
}


/* Add functionality for getting Scorm data
*/
function get_scorm_activity($course, $userid, $mod) {
	global $CFG;
	require_once($CFG->dirroot.'/mod/scorm/lib.php');
	$scorm = new stdClass;
	$scorm->id = $mod->instance;
	$user = new stdClass;
	$user->id = $userid;
	$scormgrade = scorm_get_user_grades($scorm, $userid);
	$scormoutline = scorm_user_outline($course, $user, $mod, $scorm);
	$scormactivity = array();
	$scormactivity['grade'] = $scormgrade;
	$scormactivity['outline'] = $scormoutline;
	return $scormactivity;
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

    $gradetotal = array(
        'attempted_grade' => 0,
        'attempted_max' => 0,
        'all_max' => 0
    );

    if ($courseid) {
        $courses[$courseid] = $courseid;
    } else {
        $courses = block_fn_mentor_get_student_courses($studentid);
    }

    if ($courses) {
        foreach ($courses as $id => $value) {

            $course = $DB->get_record('course', array('id' => $id), '*', MUST_EXIST);

            // Available modules for grading.
            $modavailable = get_graded_mods();

            $context = context_course::instance($course->id);

            // Collect modules data.
            $mods = get_course_mods($course->id);

            // Skip some mods.
            foreach ($mods as $mod) {
                if (!isset($modavailable[$mod->modname])) {
                    continue;
                }
                // Skip non tracked activities.
                if ($mod->completion == COMPLETION_TRACKING_NONE) {
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
                 //Prevent division by zero.
				 if($courseaverage->rawgrademax > 0) {
					$coursegrades[$id] = ($courseaverage->finalgrade / $courseaverage->rawgrademax) * 100;
				} else {
					//Prevent division by zero.
					$coursegrades[$id] = 0;
				}

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
			//added to separate completed vs total.
			$data->numofgraded = $totalnumofgraded;
			$data->numofactivities = $numofactivities;
            $data->numofcompleted = "$totalnumofgraded/$numofactivities";
            $data->percentageofcompleted = round(($numofgraded / $numofactivities) * 100);
        } else {
            //added to separate completed vs total.
			$data->numofgraded = "N/A";
			$data->numofactivities = 0;
			$data->numofcompleted = "N/A";
            $data->percentageofcompleted = 0;
        }
    }

    return $data;
}

function block_fn_mentor_quality_grade ($studentid, $courseid=0) {
	//Add BlueSky's quality grade calculation to Grade window.
	global $DB;

    $data = new stdClass();
    $courses = array();
    $coursegrades = array();
    $nogradeassignments = 0;
	$qualitygrades = array();

    if (! $passinggrade = get_config('block_fn_mentor', 'passinggrade')) {
        $passinggrade = 50;
    }

    $gradetotal = array(
        'attempted_grade' => 0,
        'attempted_max' => 0,
        'all_max' => 0
    );

    if ($courseid) {
        $courses[$courseid] = $courseid;
    } else {
        $courses = block_fn_mentor_get_student_courses($studentid);
    }

    if ($courses) {
        foreach ($courses as $id => $value) {

            $course = $DB->get_record('course', array('id' => $id), '*', MUST_EXIST);

            // Available modules for grading.
            $modavailable = get_graded_mods();

            $context = context_course::instance($course->id);

            // Collect modules data.
            $mods = get_course_mods($course->id);

            // Skip some mods.
            foreach ($mods as $mod) {
                if (!isset($modavailable[$mod->modname])) {
                    continue;
                }
                // Skip non tracked activities.
                if ($mod->completion == COMPLETION_TRACKING_NONE) {
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
				$sqlcourseaverage = "SELECT gg.id, round(gg.finalgrade,2) as finalgrade, round(gg.rawgrademax,2) as rawgrademax
									   FROM {grade_grades} gg
									   JOIN {grade_items} gi
										 ON gg.itemid = gi.id
									  WHERE gi.itemtype = ?
										AND gi.courseid = ?
										AND gg.userid = ?
										AND gi.gradetype <> '0'
										AND gi.aggregationcoef <> '1%'
										AND gg.finalgrade IS NOT NULL";
				
				if ($records = $DB->get_records_sql($sqlcourseaverage, array('mod', $id, $studentid))) {
					$quality = new stdClass;
					$quality->finalgrade = 0;
					$quality->rawgrademax = 0;
					foreach ($records as $record) {
						foreach ($record as $key => $value) {
							if($key != 'id') {
								$quality->$key += $value;
							}
						}
					};
				if ($quality->rawgrademax == 0) {
					$qualitygrades[$id] = 0;
				} else {
					$qualitygrades[$id] = ($quality->finalgrade / $quality->rawgrademax) * 100;
				}

					if ($qualitygrades[$id] >= $passinggrade) {
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
    if (count($qualitygrades)) {
        $data->allcourseaverge = round(array_sum($qualitygrades) / count($qualitygrades));
    } else {
        $data->allcourseaverge = 0;
    }
	
	if ($courseid) {

        if (isset($qualitygrades[$courseid])) {
            $data->courseaverage = round($qualitygrades[$courseid]);
        } else {
            $data->courseaverage = 0;
        }
	}

    return $data;
	
}

function block_fn_mentor_print_grade_summary ($courseid , $studentid) {
    global $OUTPUT;

    $html = '';
    $courseaverage = block_fn_mentor_get_user_course_average($studentid, $courseid);
    $gradesummary = block_fn_mentor_grade_summary($studentid, $courseid);
	//Add Quzlity Grade to print results.
	$qualitysummary = block_fn_mentor_quality_grade ($studentid, $courseid);
	// Use the configured passing grade.
	if (! $passinggrade = get_config('block_fn_mentor', 'passinggrade')) {
        $passinggrade = 50;
    }

    $html .= '<table class="mentee-course-overview-grade_table">';
    $html .= '<tr>';
    $html .= '<td class="overview-grade-left" valign="middle">'.get_string('numofcomplete', 'block_fn_mentor').':</td>';
    $html .= '<td class="overview-grade-right grey" valign="middle">'.$gradesummary->numofcompleted.'</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td class="overview-grade-left" valign="middle">'.get_string('currentgrade', 'block_fn_mentor').':</td>';
    if ($courseaverage == false) {
        $class = 'red';
        $nocoursetotalmsg = get_string('nocoursetotal', 'block_fn_mentor');
    } else {
        // Use the configured passing grade.
		$class = ($gradesummary->courseaverage >= $passinggrade) ? 'green' : 'red';
        $nocoursetotalmsg = '';
    }
    $html .= '<td class="overview-grade-right '.$class.'" valign="middle">'.$gradesummary->courseaverage.'%</td>';
    $html .= '</tr>';
    if ($courseaverage == false) {
        $warningimg = '<img class="actionicon" width="16" height="16" alt="" src="'.$OUTPUT->pix_url('i/warning', '').'"> ';
        $html .= '<tr>';
        $html .= '<td colspan="2" class="overview-grade-right-warning" valign="middle">'.$warningimg.get_string('nocoursetotal', 'block_fn_mentor').'</td>';
        $html .= '</tr>';
    }
	// add Quality grade to grade window.
	$html .= '<tr>';
	$html .= '<td class="overview-grade-left" valign="middle">'.get_string('qualitygrade', 'block_fn_mentor').':</td>';
    if ($courseaverage == false) {
        $class = 'red';
        $nocoursetotalmsg = get_string('nocoursetotal', 'block_fn_mentor');
    } else {
        $class = ($qualitysummary->courseaverage >= $passinggrade) ? 'green' : 'red';
        $nocoursetotalmsg = '';
    }
    $html .= '<td class="overview-grade-right '.$class.'" valign="middle">'.$qualitysummary->courseaverage.'%</td>';
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

function block_fn_mentor_radio($name, $id , $class, $value) {
    return html_writer::empty_tag('input', array(
            'value' => $value, 'type' => 'radio', 'id' => $id, 'name' => $name, 'class' => $class
        )
    );
}

function block_fn_mentor_radio_checked($name, $id , $class, $value) {
    return html_writer::empty_tag('input', array(
            'value' => $value, 'type' => 'radio', 'id' => $id, 'name' => $name, 'class' => $class, 'checked' => 'checked'
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

    $menteeid = optional_param('menteeid', 0, PARAM_INT);
    $courseid = optional_param('courseid', 0, PARAM_INT);

    $html = '<table class="notification_rule" cellspacing="0">
                 <tr>
                    <td colspan="4" class="notification_rule_ruleno"><strong>'.
        get_string('rule', 'block_fn_mentor').' '.$number.':</strong> '.$notification->name.'</td>
                 </tr>
                 <tr>
                    <td colspan="4" class="notification_rule_button">';

    $html .= block_fn_mentor_single_button_form (
        'create_new_rule',
        new moodle_url('/blocks/fn_mentor/notification_send.php',
            array('id' => $notification->id, 'action' => 'send', 'sesskey' => sesskey())
        ),
        null, get_string('run_now', 'block_fn_mentor')
    );
    $html .= block_fn_mentor_single_button_form (
        'create_new_rule',
        new moodle_url('/blocks/fn_mentor/notification_send.php',
            array('id' => $notification->id, 'action' => 'list', 'sesskey' => sesskey())
        ),
        null, get_string('listrecipients', 'block_fn_mentor')
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
                    <th class="notification_c1" nowrap="nowrap">'.get_string('applyto', 'block_fn_mentor').'</th>
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

    if ($notification->g2 || $notification->g4 || $notification->g6 || $notification->n1 || $notification->n2 || $notification->consecutive) {

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
        if ($notification->consecutive) {
            $html .= '<li>'.get_string('consecutive', 'block_fn_mentor', $notification->consecutive_value).'</li>';
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
	// added extra activity types, lesson, journal, scorm, LTI, HSU forum.
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
	
	// Lessons.
    $sqllesson = "SELECT l.id,
                       l.completed
                  FROM {lesson_grades} l
                 WHERE l.userid = ?
              ORDER BY l.completed DESC";

    if ($lattempts = $DB->get_records_sql($sqllesson, array($studentid))) {
        $lattempt = reset($lattempts);
        $llastattempt = round(((time() - $lattempt->completed) / (24 * 60 * 60)), 0);
    }
	
	// Journals.
    $sqljournal = "SELECT j.id,
                       j.modified
                  FROM {journal_entries} j
                 WHERE j.userid = ?
              ORDER BY j.modified DESC";

    if ($entries = $DB->get_records_sql($sqljournal, array($studentid))) {
        $entry = reset($entries);
        $jlastentry = round(((time() - $entry->completed) / (24 * 60 * 60)), 0);
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
	
	// HSUForum.
    $sqlhforum = "SELECT f.id,
                        f.modified
                   FROM {hsuforum_posts} f
                  WHERE f.userid = ?
               ORDER BY f.modified DESC";

    if ($hposts = $DB->get_records_sql($sqlhforum, array($studentid))) {
        $hpost = reset($hposts);
        $lasthsupost = round(((time() - $hpost->modified) / (24 * 60 * 60)), 0);
    }
	
	//Scorm.
	$sqlscorm = "SELECT sc.id,
                        sc.timemodified
                   FROM {scorm_scoes_track} sc
                  WHERE sc.userid = ?
               ORDER BY sc.timemodified DESC";

    if ($scattempts = $DB->get_records_sql($sqlscorm, array($studentid))) {
        $scattempt = reset($scattempts);
        $sclastattempt = round(((time() - $scattempt->timemodified) / (24 * 60 * 60)), 0);
    }
	
	// LTI.
    $sqllti = "SELECT lti.id,
                         lti.datesubmitted
                    FROM {lti_submission} lti
                   WHERE lti.userid = ?
                ORDER BY lti.datesubmitted DESC";

    if ($ltisubmissions = $DB->get_records_sql($sqllti, array($studentid))) {
        $ltisubmission = reset($ltisubmissions);
        $ltilastsubmission = round(((time() - $ltisubmission->datesubmitted) / (24 * 60 * 60)), 0);
    }

    return min($lastsubmission, $lastattempt, $llastattempt, $lastpost, $lasthsupost, $jlastentry, $sclastattempt, $ltilastsubmission);
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

function block_fn_mentor_format_time($time, $lastaccess, $str=null) {
	/* adjusted the function to include never access, 
	* make sure to change all instances of function to three 
	* params in course_overview.php and 
	* course_overview_single.php */
    
	if($lastaccess == 0) {
		$never = true;
		return 'Never';
		// No calulations necessary.
	} else {
		// Go ahead and calculate the last access
		$totalsecs = abs($time - $lastaccess);

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

function block_fn_mentor_send_notifications($notificationid=null, $output=false, $list=false) {
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

    if ($list) {
        $notificationtbl = 'block_fn_mentor_notific_list';
    } else {
        $notificationtbl = 'block_fn_mentor_notific_msg';
    }

    if ($notificationrules) {
        $DB->execute("UPDATE {" . $notificationtbl . "} SET sent = 1 WHERE notificationid = ? AND sent = 0", array($notificationid));
        foreach ($notificationrules as $notificationrule) {
            if ($list) {
                $DB->execute("DELETE FROM {" . $notificationtbl . "} WHERE notificationid = ?", array($notificationid));
            }

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
                && !($notificationrule->n2 && $notificationrule->n2_value)
            ) {
                continue;
            }

            $courses = array();
            $notificationmessage = array();

            $getcourses = function ($category, &$courses) use (&$getcourses) {
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
            if (!is_null($notificationrule->category) && $notificationrule->category >= 0) {
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
                                $message .= '<li>' . get_string('g2_message', 'block_fn_mentor',
                                        array('firstname' => $student->firstname, 'g2' => $gradesummary->courseaverage)) . '</li>';
                                $notificationmessage[$student->id][$course->id]['coursename'] = $course->fullname;
                                $notificationmessage[$student->id][$course->id]['message'] = $message;
                            }
                            if ($notificationrule->g4 && $notificationrule->g4_value) {
                                if ($gradesummary->courseaverage < $notificationrule->g4_value) {
                                    $message .= '<li>' . get_string('g4_message', 'block_fn_mentor',
                                            array('firstname' => $student->firstname,
                                                'g4' => $gradesummary->courseaverage,
                                                'g4_value' => $notificationrule->g3_value)
                                        ) . '</li>';
                                    $notificationmessage[$student->id][$course->id]['coursename'] = $course->fullname;
                                    $notificationmessage[$student->id][$course->id]['message'] = $message;
                                }
                            }
                            if ($notificationrule->g6 && $notificationrule->g6_value) {
                                if ($gradesummary->courseaverage > $notificationrule->g6_value) {
                                    $message .= '<li>' . get_string('g6_message', 'block_fn_mentor',
                                            array('firstname' => $student->firstname,
                                                'g6' => $gradesummary->courseaverage,
                                                'g6_value' => $notificationrule->g6_value)
                                        ) . '</li>';
                                    $notificationmessage[$student->id][$course->id]['coursename'] = $course->fullname;
                                    $notificationmessage[$student->id][$course->id]['message'] = $message;
                                }
                            }

                            if ($notificationrule->n1 && $notificationrule->n1_value) {
                                $lastaccess = 0;
                                if ($student->lastaccess > 0) {
                                    $lastaccess = round(((time() - $student->lastaccess) / (24 * 60 * 60)), 0);
                                }
                                if ($lastaccess >= $notificationrule->n1_value) {
                                    $message .= '<li>' . get_string('n1_message', 'block_fn_mentor',
                                            array('firstname' => $student->firstname, 'n1' => $lastaccess)) . '</li>';
                                    $notificationmessage[$student->id][$course->id]['coursename'] = $course->fullname;
                                    $notificationmessage[$student->id][$course->id]['message'] = $message;
                                }
                            }

                            if ($notificationrule->n2 && $notificationrule->n2_value) {
                                $lastactivity = block_fn_mentor_last_activity($student->id);
                                if (is_numeric($lastactivity)) {
                                    if ($lastactivity >= $notificationrule->n2_value) {
                                        $message .= '<li>' . get_string('n2_message', 'block_fn_mentor',
                                                array('firstname' => $student->firstname, 'n2' => $lastactivity)) . '</li>';
                                        $notificationmessage[$student->id][$course->id]['coursename'] = $course->fullname;
                                        $notificationmessage[$student->id][$course->id]['message'] = $message;
                                    }
                                }
                            }

                            // Consecutive.
                            if ($notificationrule->consecutive && $notificationrule->consecutive_value) {
                                if (block_fn_mentor_concurrent_login($student->id, $notificationrule->consecutive_value)) {
                                    $message .= '';
                                    $notificationmessage[$student->id][$course->id]['coursename'] = $course->fullname;
                                    $notificationmessage[$student->id][$course->id]['message'] = $message;
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
                                $DB->insert_record($notificationtbl, $rec);
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
                        $DB->insert_record($notificationtbl, $rec);
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
                            $DB->insert_record($notificationtbl, $rec);
                        }
                    }
                }
            }
            if (!$list) {
                $updatesql = "UPDATE {block_fn_mentor_notific} SET crontime=? WHERE id=?";
                $DB->execute($updatesql, array(date("Y-m-d"), $notificationrule->id));
            }
        } // END OF EACH NOTIFICATION.
        if (!$list) {
            $notificationreport .= block_fn_mentor_group_messages($list);
        }
    }

    if ($output) {
        return $notificationreport;
    }
}

function block_fn_mentor_group_messages($list=false) {
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

            $params = array(
                'receiverid' => $group->receiverid,
                'type' => $group->type,
                'timecreated' => $group->timecreated,
                'notificationid' => $group->notificationid,
                'sent' => 0
            );
            if ($messages = $DB->get_records('block_fn_mentor_notific_msg', $params, 'userid ASC')) {
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
                    if ($notification->messagecontent != BLOCK_FN_MENTOR_MESSAGE_SEND_APPENDED) {
                        $student = $DB->get_record('user', array('id' => $message->userid));
                        $course = $DB->get_record('course', array('id' => $message->courseid));

                        $emailbody .= get_string('progressreportfrom', 'block_fn_mentor', format_string($site->fullname)) . ' <br />';
                        $emailbody .= get_string('student', 'block_fn_mentor') . ':  <strong>' .
                            $student->firstname . ' ' . $student->lastname . '</strong> <br /><hr />';

                        $emailbody .= get_string('course', 'block_fn_mentor') . ': ' . $course->fullname . ' <br />';
                        $emailbody .= '<ul>' . $message->message . '</ul>';

                        $menteeurl = new moodle_url('/blocks/fn_mentor/course_overview.php', array('menteeid' => $student->id));
                        $emailbody .= '<p>' . get_string('linktomentorpage', 'block_fn_mentor', $menteeurl->out()) . '</p><hr />';
                    }

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
                    if ($notification->$emailsent && !$list) {
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

                    if ($notification->$smssent && !$list) {
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
    if (!$notificationreport ||  $list) {
        return get_string('nomessagessent', 'block_fn_mentor').'<br>';
    }
    return $notificationreport;
}

function block_fn_mentor_sms_to_user ($user, $from, $subject, $messagetext, $messagehtml = '') {
    global $DB;

    $sqlphonenumber = "SELECT t1.shortname, t2.data
						 FROM {user_info_field} t1 , {user_info_data}  t2
					    WHERE t1.id = t2.fieldid
						  AND t1.shortname = ?
						  AND t2.userid = ?";

    $sqlprovider = "SELECT t1.shortname, t2.data
         			  FROM {user_info_field} t1 , {user_info_data}  t2
		     	 	 WHERE t1.id = t2.fieldid
					   AND t1.shortname = ?
					   AND t2.userid = ?";

    for ($i =1; $i <= 2; $i++) {
        if ($phonenumber = $DB->get_record_sql($sqlphonenumber, array('mobilephone'.$i, $user->id))) {
            $smsnumber = $phonenumber->data;
            if ($phoneprovider = $DB->get_record_sql($sqlprovider, array('mobileprovider'.$i, $user->id))) {
                $smsproviderfull = $phoneprovider->data;
                $smsproviderarray = explode('~', $smsproviderfull);
                $smsprovider = $smsproviderarray[1];
                $user->email = $smsnumber . $smsprovider;
                email_to_user($user, $from, get_string('notification', 'block_fn_mentor'), strip_tags($messagehtml), '');
            }
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

function get_enrollment_dates($menteeid, $enrolledcourse) {
	//  Add for enrollment period dates.
	global $DB;
	$data = new stdClass;
		$sql = "SELECT ue.timestart, ue.timeend
                          FROM {user_enrolments} ue
                          JOIN {enrol} e
                            ON e.id = ue.enrolid
                         WHERE ue.userid = ?
                           AND e.courseid = ?";
		if ($enrolinfo = $DB->get_records_sql($sql, array($menteeid, $enrolledcourse))) {
			// If there are more than one enrollment record remove any records without a start or end date.
			if (count($enrolinfo) > 1) {
				foreach($enrolinfo as $key => $value) {
					if($value->timestart == 0 || $value->timeend == 0) {
						unset($enrolinfo[$key]);
					}
				}
			}

			foreach ($enrolinfo as $enrol) { 
				    $data->enrolstart = $enrol->timestart;
				    $data->enrolend = $enrol->timeend;
			}
		} else {
			 $data->enrolstart = 0;
			 $data->enrolend = 0;
		}
		
		return $data;
}


function get_session_times($enrolledcourse) {
	//  Connect to external database logs to get session times for each course. Compare course id number to get times. Returns string.
	$extdb = new Database();
	$sessiondata = $extdb->get_extdb_sessions($enrolledcourse);
	return $sessiondata;
}

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
			//  Only count if it is a graded item.
            if (!$item = $DB->get_record('grade_items',
                array("itemtype" => 'mod', "itemmodule" => $activity->modname, "iteminstance" => $activity->instance))) {
					continue;
				}
            
            $libfile = $CFG->dirroot . '/mod/' . $activity->modname . '/lib.php';
            //  Check to see if the mod has an internal grade function, if not use Moodle get grades function. (For extra modules).
			if (file_exists($libfile)) {
				$gradefunction = $activity->modname . "_get_user_grades";
				if(!function_exists($gradefunction)) {
					$gradefunction = "grade_get_grades";
				} else {
					require_once($libfile);
				}
		    }
            //  Add the advanced forums to check.
			if ((($activity->modname != 'forum') || ($activity->modname != 'hsuforum') || ($instance->assessed > 0))
				&& isset($modgradesarray[$activity->modname])) {

				if (function_exists($gradefunction)) {
                    //  Check to see if this module is using their own get grades method or switch to the standard Moodle get grades function.
					if (strpos($gradefunction, $activity->modname) !== false) {
						
                        if ($grade = $gradefunction($instance, $menteeid)) {
							//  Reordered loop to pass through special cases first before using the standard method.
							if ($modstatus = block_fn_mentor_assignment_status($activity, $menteeid, true)) {
							    switch ($modstatus) {
									case 'submitted':
										if ($instance->grade == 0) {
											// Graded
											++$completedactivities;
										} else if ($grade = $gradefunction($instance, $menteeid)) {
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
						    } else if  ($modstatus = block_fn_mentor_quiz_status($activity, $menteeid, true)) {	
							//  Check for special quiz statuses.
								switch ($modstatus) {
									case 'submitted':
										if ($instance->grade == 0) {
											// Graded
											++$completedactivities;
										} else if ($grade = $gradefunction($instance, $menteeid)) {
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
					        } else if ($modstatus = block_fn_mentor_lesson_status($activity, $menteeid, $course, true)) {
							//  Check for special lesson statuses.
						        switch ($modstatus) {
									case 'submitted':
										if ($instance->grade == 0) {
											// Graded
											++$completedactivities;
										} else if ($grade = $gradefunction($instance, $menteeid)) {
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
					        } else if ($modstatus = block_fn_mentor_journal_status($activity, $menteeid, $course, true)) {
							//  Check for special journal statuses.
								switch ($modstatus) {
									case 'submitted':
										if ($instance->grade == 0) {
											// Graded
											++$completedactivities;
										} else if ($grade = $gradefunction($instance, $menteeid)) {
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
							} else if ($grade) {
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
							
						} else if ($activity->modname == 'quiz') {
							// Count waiting for grade quizzes without a grade from grade function.
							$modstatus = block_fn_mentor_quiz_status($activity, $menteeid, true);
							   // Waiting for grade
							   if($modstatus == 'waitinggrade') {
								   ++$waitingforgradeactivities;
							   } else {
								   // Ungraded
							       ++$notattemptedactivities;
							   }
							
						} else if ($activity->modname == 'lesson') {
							// Count saved lessons that don't yet have a grade
							
							$modstatus = block_fn_mentor_lesson_status($activity, $menteeid, $course, true);
							   // Saved
							   if($modstatus == 'saved') {
								    ++$savedactivities;
							   } else {
								 // Ungraded
							     ++$notattemptedactivities;
							   }
						} else if ($activity->modname == 'journal') {
							$modstatus = block_fn_mentor_journal_status($activity, $menteeid, $course, true);
							// Saved
							   if($modstatus == 'waitinggrade') {
								    ++$waitingforgradeactivities;
							   } else {
								 // Ungraded
							     ++$notattemptedactivities;
							   }
						} else {
							// Ungraded
							++$notattemptedactivities;
						}
						
					}  else { 
					//  Add other graded module types.
					   if ($grade = $gradefunction($course->id, 'mod', $activity->modname, $activity->instance, $menteeid)) {
							$usergrade = $grade->items[0]->grades[$menteeid];
							if ($usergrade->grade !== null && $usergrade->dategraded !== null) {
								if ($item->gradepass > 0) {
									if ($usergrade->grade >= $item->gradepass) {
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
					   }
					} 
				} // end check for grade function
			} // end check for graded modules
        } // end foreach activity

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
		//SRINDHAL. Added to include waiting for grade activities in progress calculations with configuration.
		$progressdata->completewaiting = $completedactivities + $incompletedactivities + $waitingforgradeactivities;

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
			// Prevent division by zero.
            if ($courseaverage->rawgrademax > 0) {
			   $progressdata->percentage = ($courseaverage->finalgrade / $courseaverage->rawgrademax) * 100;
		   } else {
			   $progressdata->percentage = 100;
		   }
        }

    } else {
        $progressdata->content->items[] = get_string('completionnotenabled', 'block_fn_mentor');
        $progressdata->content->icons[] = '<img src="' . $CFG->wwwroot .
            '/blocks/fn_mentor/pix/warning.gif" class="icon" alt="">';
    }

    return $progressdata;
}

function block_fn_mentor_calculate_activity_progress ($course, $menteeid) {
	//  Calculate the progress in course using dates for progress bar.
    global $DB;
	
	$data = new stdClass;
    $modgradesarray = get_graded_mods();
	$usewaiting = get_config('block_fn_mentor', 'includewaitinggrade');
	
	$progressdata = block_fn_mentor_activity_progress($course, $menteeid, $modgradesarray);

	if ($usewaiting) {
		$data->activitycomplete = $progressdata->completewaiting;
	} else {
		$data->activitycomplete = $progressdata->completed;
	}
	$data->activitytotal = $progressdata->total;
	if ($data->activitytotal > 0 ) {
		$data->activpercentcomplete = round(($data->activitycomplete  / $data->activitytotal) * 100, 0);
	} else {
		$data->activpercentcomplete = 100;
	}
	$data->timecompleted = $progressdata->timecompleted;
	$time = time();
	$weekofseconds = 604800;
	$sql = "SELECT ue.timestart, ue.timeend
                          FROM {user_enrolments} ue
                          JOIN {enrol} e
                            ON e.id = ue.enrolid
                         WHERE ue.userid = ?
                           AND e.courseid = ?";
	if ($enrolinfo = $DB->get_records_sql($sql, array($menteeid, $course->id))) {
		if (count($enrolinfo) > 1) {
			foreach($enrolinfo as $key => $value) {
				if($value->timestart == 0 || $value->timeend == 0) {
					unset($enrolinfo[$key]);
				}
			}
		}
		foreach ($enrolinfo as $enrol) {
			$data->enrolstart = $enrol->timestart;
			$data->enrolend = $enrol->timeend;
		}
		
		if ($data->enrolstart == 0 || $data->enrolend == 0){
			// FIND CURRENT WEEK.
			$courseformat = course_get_format($course);
			$courseformatoptions = $courseformat->get_format_options();
			$coursenumsections = $courseformatoptions['numsections'];
			
	 
			$weekdate = $course->startdate;
			$weekdate += 7200;
			$courseenddate = $course->startdate + ($weekofseconds * $coursenumsections);
			// Calculate the current week based on today's date and the starting date of the course.
			$currentweek = ($time > $course->startdate) ? (int) ((($time - $course->startdate) / $weekofseconds) + 1) : 0;
			$currentweek = min($currentweek, $coursenumsections);
			$data->totalweeks = (int)(($courseenddate - $course->startdate) / $weekofseconds);
			$data->currentweek = $currentweek;
			if ($data->activitytotal == 0) {
				$data->activitiesperweek = 0;
			} else {
				$data->activitiesperweek = round(($data->activitytotal / $data->totalweeks), 1, PHP_ROUND_HALF_UP);
			}
		} else {
			$data->totalweeks = (int)(($data->enrolend - $data->enrolstart) / $weekofseconds);
			$data->currentweek = ($time > $data->enrolstart) ? (int) ((($time - $data->enrolstart) / $weekofseconds) + 1) : 0;
			if ($data->activitytotal == 0) {
				$data->activitiesperweek = 0;
			} else if ($data->currentweek > $data->totalweeks) {
				$data->activitiesperweek = $data->activitytotal;
			} else {
				$data->activitiesperweek = round(($data->activitytotal / $data->totalweeks), 1, PHP_ROUND_HALF_UP);
			}
		}
		
	} 
	$data->currenttimepercentage = (($data->currentweek / $data->totalweeks) < 1) ? (int) (round(($data->currentweek / $data->totalweeks) * 100)) : 100;

	return $data;
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
						//  Check to see if this module is using their own get grades method or switch to the standard Moodle get grades function.					   
						$gradefunction = $mod->modname . "_get_user_grades";
						if(!function_exists($gradefunction)) {
							$gradefunction = "grade_get_grades";
						} else {
							require_once($libfile);
						}
					}
					if($item) {
					//  Added advanced forum check.
                        if (((($mod->modname != 'forum') || ($mod->modname != 'hsuforum')) || ($instance->assessed > 0))
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
									
                                    if (strpos($gradefunction, $mod->modname) !== false) {
									//  Run through special cases first before applying standard grade checks.	
										$grade = $gradefunction($instance, $key);
										
										if ($modstatus = block_fn_mentor_assignment_status($mod, $key, true)) {
											
											switch ($modstatus) {
												case 'submitted':
													if ($instance->grade == 0) {
														$simplegradebook[$key]['grade'][$i][$mod->id] = 'graded_.gif';
													} else if ($grade) {
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
                                        } else if($modstatus = block_fn_mentor_quiz_status($mod, $key, true)) {
										   // Check for manually graded quizzes.
										   switch ($modstatus) {
												case 'submitted':
													if ($instance->grade == 0) {
														$simplegradebook[$key]['grade'][$i][$mod->id] = 'graded_.gif';
													} else if ($grade) {
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
									    } else if ($modstatus = block_fn_mentor_lesson_status($mod, $key, $course, true)) {
										    // Check for manually graded lesson essays.											
										   switch ($modstatus) {
												case 'submitted':
													if ($instance->grade == 0) {
														$simplegradebook[$key]['grade'][$i][$mod->id] = 'graded_.gif';
													} else if ($grade) {
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
										 
									    } else if ($modstatus = block_fn_mentor_journal_status($mod, $key, $course, true)) {
											// Check ungraded submitted journal entries.													
										   switch ($modstatus) {
												case 'submitted':
													if ($instance->grade == 0) {
														$simplegradebook[$key]['grade'][$i][$mod->id] = 'graded_.gif';
													} else if ($grade) {
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
										} else if ($grade) {
											if ($item) {
												
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
												
											}
										} else {
											
                                            $simplegradebook[$key]['grade'][$i][$mod->id] = 'ungraded.gif';
                                            if ($unsubmitted) {
                                                $simplegradebook[$key]['avg'][] = array(
                                                    'grade' => 0, 'grademax' => $item->grademax
                                                );
                                            }
                                        } 
                                    } else {
										
										if($grade = grade_get_grades($course->id, 'mod', $mod->modname, $mod->instance, $key)) {
											$usergrade = $grade->items[0]->grades[$menteeuser->id];
											if ($usergrade->grade) {
												if ($item->gradepass > 0) {
													if ($usergrade->grade >= $item->gradepass) {
															$simplegradebook[$key]['grade'][$i][$mod->id] = 'marked.gif'; // Passed.
															$simplegradebook[$key]['avg'][] = array(
																'grade' => $usergrade->grade,
																'grademax' => $item->grademax
															);
														} else {
															$simplegradebook[$key]['grade'][$i][$mod->id] = 'incomplete.gif'; // Fail.
															$simplegradebook[$key]['avg'][] = array(
																'grade' => $usergrade->grade,
																'grademax' => $item->grademax
															);
														}
														
												} else {
													// Graded (grade-to-pass is not set).
													$simplegradebook[$key]['grade'][$i][$mod->id] = 'graded_.gif';
													$simplegradebook[$key]['avg'][] = array(
														'grade' => $usergrade->grade,
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
										} 
										
									} 
									
                                } //END. foreach
                            } //END. gradefunction exists 
                        }
				    } //END. if grade book item
                }
            }
        }
		
        $weekactivitycount[$i]['numofweek'] = $numberofitem;
    }

    return array($simplegradebook, $weekactivitycount, $courseformat);
}

function block_fn_mentor_print_activity_progress ($course , $studentid) {
	// Print a Course Progress Bar to the page.
	global $OUTPUT;
	
	$html = '';
	$progressbardata = block_fn_mentor_calculate_activity_progress ($course, $studentid);

	if ($progressbardata->currentweek > $progressbardata->totalweeks) {
		// account for past course dates that are a long time in the past.
		$suggestedpacenum = $progressbardata->activitytotal;
	} else {
		$suggestedpacenum = round($progressbardata->activitiesperweek * $progressbardata->currentweek, 1);
	}
	$studentcompletednum = $progressbardata->activitycomplete;
	
	if ($progressbardata->activpercentcomplete < $progressbardata->currenttimepercentage) {
		$color = 'bar-danger';
	} else {
		$color = 'bar-success';
	}
	$html .= '<div class="container-fluid">';
	//$html .= '<h4>Track Progress</h4>';
    $html .= '<div class="row-fluid"><br>';
	if($progressbardata->timecompleted > 0) {
		// Course completed
		$html .= '<h5>Course Complete</h5>';
		$html .= '<div class="progress progress-striped">';
		$html .= '<div class="bar bar-success" role="progressbar" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100" style="width:100%">';
		$html .= '100%';
		$html .= '</div>'; // end progress bar div
		$html .= '</div>'; // end progress div
	} else if ($progressbardata->activitytotal == 0) {
		// No activities to complete
		$html .= '<div class="alert alert-info"><p>'.get_string('progressnoactivities', 'block_fn_mentor').'</p></div>';
		
	} else if ($progressbardata->activitytotal > 0 && $progressbardata->activpercentcomplete == 0) {
		// Has not completed any activities yet.
		$html .= '<h5>'.get_string('progresssuggestedpace', 'block_fn_mentor').' (activities)</h5>';
		$html .= '<div class="progress progress-striped">';
		$html .= '<div class="bar bar-success" role="progressbar" aria-valuenow="'.$suggestedpacenum.'" aria-valuemin="0" aria-valuemax="'.$progressbardata->activitytotal.'" style="width:'.$progressbardata->currenttimepercentage.'%">';
		$html .= $suggestedpacenum;
		$html .= '</div>'; // end progress bar div
		$html .= '</div>'; // end progress div
		$html .= '<h5>'.get_string('progressstudentpace', 'block_fn_mentor').' (activities)</h5>';
		$html .= '<div class="alert alert-danger"><p>'.get_string('progressnoactivitiescomp', 'block_fn_mentor').'</p></div>';
	} else {
		$html .= '<h5>'.get_string('progresssuggestedpace', 'block_fn_mentor').' (activities)</h5>';
		$html .= '<div class="progress progress-striped">';
		$html .= '<div class="bar bar-success" role="progressbar" aria-valuenow="'.$suggestedpacenum.'" aria-valuemin="0" aria-valuemax="'.$progressbardata->activitytotal.'" style="width:'.$progressbardata->currenttimepercentage.'%">';
		$html .= $suggestedpacenum;
		$html .= '</div>'; // end progress bar div
		$html .= '</div>'; // end progress div
		$html .= '<h5>'.get_string('progressstudentpace', 'block_fn_mentor').' (activities)</h5>';
		$html .= '<div class="progress progress-striped active">';
		$html .= '<div class="bar '.$color.'" role="progressbar" aria-valuenow="'.$studentcompletednum.'" aria-valuemin="0" aria-valuemax="'.$progressbardata->activitytotal.'" style="width:'.$progressbardata->activpercentcomplete.'%">';
		$html .= $studentcompletednum;
		$html .= '</div>'; // end progress bar div
		$html .= '</div>'; // end progress div
	}
	$html .= '</div>'; //end row
    $html .= '</div>'; // end container
	
    return $html;
}

function block_fn_mentor_generate_report(progress_bar $progressbar = null) {
    global $DB;
    $inprogress = get_config('block_fn_mentor', 'inprogress');
    $reportdate = get_config('block_fn_mentor', 'reportdate');

    $modgradesarray = get_graded_mods();

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

function block_fn_mentor_get_user_course_average($userid, $courseid) {
    global $DB;

    if ($gradeitem = $DB->get_record('grade_items', array('courseid' => $courseid, 'itemtype' => 'course'))) {
        if ($gradeitem->gradetype == GRADE_TYPE_NONE) {
            return false;
        }
        if ($grade = $DB->get_record('grade_grades', array('itemid' => $gradeitem->id, 'userid' => $userid))) {
            return $grade;
        }
        return -1;
    }
    return false;
}

function block_fn_mentor_assign_action_btn($text, $id) {
    $attributes = array(
        'value' => $text, 'type' => 'button', 'id' => $id, 'class' => 'btn btn-secondary assign-group-button'
    );

    return html_writer::tag('p',
        html_writer::empty_tag('input', $attributes)
    );
};

function block_fn_mentor_add_group_member($userid, $groupid, $role) {
    global $DB;

    $user = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);

    if ($user->deleted) {
        return false;
    }

    if ($DB->record_exists('block_fn_mentor_group', array('id' => $groupid))) {
        if (!$DB->record_exists('block_fn_mentor_group_mem', array('groupid' => $groupid, 'userid' => $userid, 'role' => $role))) {
            $member = new stdClass();
            $member->groupid = $groupid;
            $member->userid = $userid;
            $member->timeadded = time();
            $member->role = $role;
            return $DB->insert_record('block_fn_mentor_group_mem', $member);
        }
    }

    return false;
}

function block_fn_mentor_remove_group_member($userid, $groupid, $role) {
    global $DB;

    $user = $DB->get_record('user', array('id'=>$userid), '*', MUST_EXIST);

    if ($user->deleted) {
        return false;
    }

    if ($DB->record_exists('block_fn_mentor_group', array('id' => $groupid))) {
        $params = array(
            'groupid' => $groupid,
            'userid' => $userid,
            'role' => $role
        );
        return $DB->delete_records('block_fn_mentor_group_mem', $params);
    } else {
        return false;
    }
};

function block_fn_mentor_get_group_members($groupid, $role) {
    global $DB;

    if ($role == 'M') {
        $select = "IF(gm.teamleader='1', CONCAT('[GT] ', u.firstname, ' ', u.lastname), CONCAT(u.firstname, ' ', u.lastname)) fullname";
    } else {
        $select = "CONCAT( u.firstname, ' ', u.lastname) fullname";
    }

    $sql = "SELECT u.id,
                   $select
              FROM {block_fn_mentor_group_mem} gm
              JOIN {user} u
                ON gm.userid = u.id
             WHERE gm.groupid = ?
               AND gm.role = ?
               AND u.deleted = ?
          ORDER BY u.lastname ASC";

    return $DB->get_records_sql_menu($sql, array($groupid, $role, 0));
};

function  block_fn_mentor_get_group_by_idnumber($idnumber) {
    global $DB;

    if (empty($idnumber)) {
        return false;
    }
    if ($group = $DB->get_record('block_fn_mentor_group', array('idnumber' => $idnumber))) {
        return $group;
    }
    return false;
}

function block_fn_mentor_users_has_active_enrollment() {
    global $DB;

    $sql = "SELECT u.id, u.firstname, u.lastname, u.email
              FROM {course} c
              JOIN {enrol} en 
                ON en.courseid = c.id
              JOIN {user_enrolments} ue 
                ON ue.enrolid = en.id
              JOIN {user} u 
                ON ue.userid = u.id
          GROUP BY u.id
          ORDER BY u.lastname ASC";

    return $DB->get_records_sql_menu($sql);
}

function block_fn_mentor_max_number_of_mentor($userids) {
    global $DB;

    if (! $mentorroleid = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }

    list($sqlfilter, $params) = $DB->get_in_or_equal($userids);

    $params[] = CONTEXT_USER;
    $params[] = $mentorroleid;

    $sql = "SELECT MAX(numofmentor) maxmentor
              FROM (SELECT COUNT(ra2.id) numofmentor,
                           ctx.instanceid menteeid
                      FROM {context} ctx
                      JOIN {role_assignments} ra2 
                        ON ctx.id = ra2.contextid
                     WHERE ctx.instanceid {$sqlfilter} 
                       AND ctx.contextlevel = ? 
                       AND ra2.roleid = ?
                  GROUP BY ctx.instanceid) counts";

    $rec =  $DB->get_record_sql($sql, $params);
    return $rec->maxmentor;
}

function block_fn_mentor_get_assigned_mentors($userid) {
    global $DB;

    if (!$muserroleid = get_config('block_fn_mentor', 'mentor_role_user')) {
        return false;
    }

    $sql = "SELECT u.*
              FROM {role_assignments} ra
              JOIN {context} cx ON ra.contextid = cx.id
              JOIN {user} u ON ra.userid = u.id
             WHERE cx.contextlevel = ? 
               AND ra.roleid = ?
               AND cx.instanceid = ? 
               AND u.deleted = ?
               AND u.suspended = ?";

    return $DB->get_records_sql($sql, array(CONTEXT_USER, $muserroleid, $userid, 0, 0));
}

function block_fn_mentor_assign_mentor_to_user($mentorid, $userid) {
    global $DB;
    $roleid = get_config('block_fn_mentor', 'mentor_role_user');

    $mentor = $DB->get_record('user', array('id' => $mentorid, 'deleted' => 0));
    $user = $DB->get_record('user', array('id' => $userid, 'deleted' => 0));

    if ($roleid && $mentor && $user) {
        if (block_fn_mentor_is_mentor($mentor->id)) {
            $usercontext = context_user::instance($user->id);
            return role_assign($roleid, $mentor->id, $usercontext->id);
        }
    }
    return false;
}

function block_fn_mentor_uu_validate_user_upload_columns(csv_import_reader $cir, $stdfields, $profilefields, moodle_url $returnurl) {
    $columns = $cir->get_columns();
    if (empty($columns)) {
        $cir->close();
        $cir->cleanup();
        print_error('cannotreadtmpfile', 'error', $returnurl);
    }
    if (count($columns) < 2) {
        $cir->close();
        $cir->cleanup();
        print_error('csvfewcolumns', 'error', $returnurl);
    }

    // test columns
    $processed = array();
    foreach ($columns as $key=>$unused) {
        $field = $columns[$key];
        $lcfield = core_text::strtolower($field);
        if (in_array($field, $stdfields) or in_array($lcfield, $stdfields)) {
            // standard fields are only lowercase
            $newfield = $lcfield;

        } else if (in_array($field, $profilefields)) {
            // exact profile field name match - these are case sensitive
            $newfield = $field;

        } else if (in_array($lcfield, $profilefields)) {
            // hack: somebody wrote uppercase in csv file, but the system knows only lowercase profile field
            $newfield = $lcfield;

        } else if (preg_match('/^(cohort|mentor_group|site_group|mentor)\d*$/', $lcfield)) {
            // special fields for enrolments
            $newfield = $lcfield;

        } else {
            $cir->close();
            $cir->cleanup();
            print_error('invalidfieldname', 'error', $returnurl, $field);
        }
        if (in_array($newfield, $processed)) {
            $cir->close();
            $cir->cleanup();
            print_error('duplicatefieldname', 'error', $returnurl, $newfield);
        }
        $processed[$key] = $newfield;
    }

    return $processed;
}
function block_fn_mentor_concurrent_login($userid, $numofday) {
    global $DB;

    $time = time();

    $sql = "SELECT log.id
              FROM {logstore_standard_log} log
             WHERE log.userid = ?
               AND log.timecreated >= ?
               AND log.timecreated <= ?";

    for ($i=0; $i < $numofday; $i++) {
        $timestart = usergetmidnight($time - ($i * 24 * 60 * 60));
        $timeend = $timestart + 24*60*60-1;

        if (!$DB->record_exists_sql($sql, array($userid, $timestart, $timeend))) {
            return false;
        }
    }
    return true;
}
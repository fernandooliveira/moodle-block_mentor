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

require_once('../../config.php');
require_once($CFG->dirroot.'/blocks/fn_mentor/lib.php');
require_once($CFG->dirroot.'/notes/lib.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/grade/lib.php');
require_once($CFG->dirroot.'/grade/report/user/lib.php');

// Parameters.
$menteeid      = optional_param('menteeid', 0, PARAM_INT);
$courseid      = optional_param('courseid', 0, PARAM_INT);
$groupid  = optional_param('groupid', 0, PARAM_INT);
$navpage       = optional_param('page', 'overview', PARAM_TEXT);

// Array of functions to call for grading purposes for modules. New mod array function for extra mods.
$modgradesarray = get_graded_mods();

$allownotes = get_config('block_fn_mentor', 'allownotes');

require_login(null, false);

// COURSES.
if (!$enrolledcourses = enrol_get_all_users_courses($menteeid, 'id,fullname,shortname', null, 'fullname ASC')) {
    print_error('error_enrolled_course', 'block_fn_mentor');
}

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

if ($enrolledcourses && $filtercourses) {
    foreach ($enrolledcourses as $key => $enrolledcourse) {
        if (!in_array($enrolledcourse->id, $filtercourses)) {
            unset($enrolledcourses[$key]);
        }
    }
}

// Select enrolled course.
if (! isset($enrolledcourses[$courseid])) {
    $ecourse = reset($enrolledcourses);
    $courseid = $ecourse->id;
}
if ($courseid) {
   //$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
   //  Need course object, use Moodle standard function for proper format.
   $course = get_course($courseid);
} else {
    print_error('unspecifycourseid', 'error');
}

// PERMISSION.
$isadmin   = has_capability('block/fn_mentor:manageall', context_system::instance());
$ismentor  = block_fn_mentor_has_system_role($USER->id, get_config('block_fn_mentor', 'mentor_role_system'));
$isteacher = block_fn_mentor_isteacherinanycourse($USER->id);
$isstudent = block_fn_mentor_isstudentinanycourse($USER->id);
$menteecanview = get_config('block_fn_mentor', 'menteecanview'); // Use the setting students can view 										 

if ($allownotes && $ismentor) {
    $allownotes = true;
} else if ($isadmin || $isteacher ) {
    $allownotes = true;
} else {
    $allownotes = false;
}

$mentees = array();

if ($isadmin) {
    $mentees = block_fn_mentor_get_all_mentees('', $groupid);
} else if ($isteacher) {
    if ($menteesbymentor = block_fn_mentor_get_mentees_by_mentor(0, $filter = 'teacher')) {
        foreach ($menteesbymentor as $menteebymentor) {
            if ($menteebymentor['mentee']) {
                foreach ($menteebymentor['mentee'] as $key => $value) {
                    $mentees[$key] = $value;
                }
            }
        }
    }
} else if ($ismentor) {
    $mentees = block_fn_mentor_get_mentees($USER->id, 0, '', $groupid);
}

// Pick a mentee if not selected.
if ((!$menteeid && $mentees) || (!in_array($menteeid, array_keys($mentees)))) {
	// Account for student views 
	if($isstudent && $menteecanview) {
		$menteeid = $USER->id;
   } else {	   
    $var = reset($mentees);
    $menteeid = $var->studentid;
   }
}

$menteeuser = $DB->get_record('user', array('id' => $menteeid), '*', MUST_EXIST);

if (($USER->id <> $menteeid) && !$isadmin && !in_array($menteeid, array_keys($mentees))) {
    print_error('invalidpermission', 'block_fn_mentor');
}
if (($isstudent) && ($USER->id <> $menteeid)  && (!$isteacher && !$isadmin && !$ismentor)) {
    print_error('invalidpermission', 'block_fn_mentor');
}

$messages = array();

$title = get_string('page_title_assign_mentor', 'block_fn_mentor');
$heading = $SITE->fullname;

$PAGE->set_url('/blocks/fn_mentor/course_overview_single.php');

if ($pagelayout = get_config('block_fn_mentor', 'pagelayout')) {
    $PAGE->set_pagelayout($pagelayout);
} else {
    $PAGE->set_pagelayout('course');
}

$PAGE->set_context(context_system::instance());
$PAGE->set_title($title);
$PAGE->set_heading($heading);
$PAGE->set_cacheable(true);
$PAGE->requires->css('/blocks/fn_mentor/css/styles.css');
$PAGE->requires->js('/blocks/fn_mentor/textrotate.js');
$PAGE->requires->js_function_call('textrotate_init', null, true);

$PAGE->navbar->add(get_string('pluginname', 'block_fn_mentor'),
    new moodle_url('/blocks/fn_mentor/course_overview_single.php', array('menteeid' => $menteeid)));

echo $OUTPUT->header();

echo '<div id="mentee-course-overview-page">';
// LEFT.
echo '<div id="mentee-course-overview-left">';

$lastaccess = '';
if ($menteeuser->lastaccess) {
	 // Extra param for last access.
    $lastaccess .= get_string('lastaccess').get_string('labelsep', 'langconfig').
        block_fn_mentor_format_time(time(), $menteeuser->lastaccess);
} else {
    $lastaccess .= get_string('lastaccess').get_string('labelsep', 'langconfig').get_string('never');
}

// Groups menu.
if ($isadmin) {
    $groups = $DB->get_records('block_fn_mentor_group', null, 'name ASC');
} else if ($ismentor || $isstudent) { //  Added $isstudent for student views
    $sql = "SELECT g.id, g.name
              FROM {block_fn_mentor_group} g
              JOIN {block_fn_mentor_group_mem} gm
                ON g.id = gm.groupid
             WHERE gm.role = ?
               AND gm.userid = ?
          ORDER BY g.name ASC";
    $groups = $DB->get_records_sql($sql, array('M', $USER->id));
}

$groupmenu = array();
$groupmenuurl = array();
$groupmenuhtml = '';

$groupmenuurl[0] = $CFG->wwwroot.'/blocks/fn_mentor/course_overview_single.php?menteeid='.$menteeid.'&groupid=0';
$groupmenu[$groupmenuurl[0]] = get_string('allmentorgroups', 'block_fn_mentor');

if ($groups) {
    foreach ($groups as $group) {
        $groupmenuurl[$group->id] = $CFG->wwwroot . '/blocks/fn_mentor/course_overview_single.php?menteeid=' . $menteeid . '&groupid=' . $group->id;
        $groupmenu[$groupmenuurl[$group->id]] = $group->name;
    }

    if ((!$isstudent) || ($isadmin || $ismentor)) {
        $groupmenuhtml = html_writer::tag('form',
            html_writer::img($OUTPUT->pix_url('i/group'), get_string('group', 'block_fn_mentor')) . ' ' .
            html_writer::select(
                $groupmenu, 'groupfilter', $groupmenuurl[$groupid], null,
                array('onChange' => 'location=document.jump2.groupfilter.options[document.jump2.groupfilter.selectedIndex].value;')
            ),
            array('id' => 'groupFilterForm', 'name' => 'jump2')
        );

        $groupmenuhtml = '<div class="mentee-course-overview-block-filter">' . $groupmenuhtml . ' </div>';
    }
}

// Student menu.
$studentmenu = array();
$studentmenuurl = array();


if ($showallstudents = get_config('block_fn_mentor', 'showallstudents')) {
    $studentmenuurl[0] = $CFG->wwwroot . '/blocks/fn_mentor/all_students.php';
    $studentmenu[$studentmenuurl[0]] = get_string('allstudents', 'block_fn_mentor');
}

if ($mentees) {
    foreach ($mentees as $mentee) {
        $studentmenuurl[$mentee->studentid] = $CFG->wwwroot.'/blocks/fn_mentor/course_overview_single.php?menteeid='.$mentee->studentid.'&groupid='.$groupid;
        $studentmenu[$studentmenuurl[$mentee->studentid]] = $mentee->firstname .' '.$mentee->lastname;
    }
}

$studentmenuhtml = '';

if ((!$isstudent) || ($isadmin || $ismentor  || $isteacher)) {
    $studentmenuhtml = html_writer::tag('form',
        html_writer::img($OUTPUT->pix_url('i/user'), get_string('user')).' '.
        html_writer::select(
            $studentmenu, 'studentfilter', $studentmenuurl[$menteeid], null,
            array('onChange' => 'location=document.jump1.studentfilter.options[document.jump1.studentfilter.selectedIndex].value;')
        ),
        array('id' => 'studentFilterForm', 'name' => 'jump1')
    );

    $studentmenuhtml = '<div class="mentee-course-overview-block-filter">'.$studentmenuhtml.'</div>';
}

// BLOCK-1.
echo $groupmenuhtml.$studentmenuhtml.'
      <div class="mentee-course-overview-block">
          <div class="mentee-course-overview-block-title">
              '.get_string('student', 'block_fn_mentor').'
          </div>
          <div class="mentee-course-overview-block-content">'.
    $OUTPUT->container($OUTPUT->user_picture($menteeuser, array('courseid' => $COURSE->id)), "userimage").
    $OUTPUT->container('<a href="'.$CFG->wwwroot.'/user/view.php?id='.$menteeuser->id.'&course=1" onclick="window.open(\''.
        $CFG->wwwroot.'/user/view.php?id='.$menteeuser->id.'&course=1\', \'\', \'width=800,height=600,toolbar=no,location=no,'.
        'menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes\'); return false;" class="" >'.
        fullname($menteeuser, true).'</a>&nbsp;&nbsp;<a href="'.$CFG->wwwroot.'/message/index.php?id='.$menteeuser->id .
        '"><img src="'.$CFG->wwwroot.'/blocks/fn_mentor/pix/email.png"></a>', "userfullname").
    '<span class="mentee-lastaccess">'.$lastaccess.'</span>' .
    '
          </div>
      </div>';



$courselist = "";

if ($courseid == 0) {
    $courselist .= '<div class="allcourses active"><a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview.php?menteeid='.
        $menteeid.'&groupid=' . $groupid.'&courseid=0">'.get_string('allcourses', 'block_fn_mentor').'</a></div>';
} else {
    $courselist .= '<div class="allcourses"><a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview.php?menteeid='.
        $menteeid.'&groupid=' . $groupid.'&courseid=0">'.get_string('allcourses', 'block_fn_mentor').'</a></div>';
}
foreach ($enrolledcourses as $enrolledcourse) {
    $course_fullname = format_string($enrolledcourse->fullname); //allow mlang filters to process language strings
    
    if ($courseid == $enrolledcourse->id) {
        $courselist .= '<div class="courselist active"><img class="mentees-course-bullet" src="'.
            $CFG->wwwroot.'/blocks/fn_mentor/pix/b.gif"><a href="'.$CFG->wwwroot.
            '/blocks/fn_mentor/course_overview_single.php?menteeid='.$menteeid.'&groupid=' . $groupid.
            '&courseid='.$enrolledcourse->id.'">'.$course_fullname.'</a></div>';
    } else {
        $courselist .= '<div class="courselist"><img class="mentees-course-bullet" src="'.$CFG->wwwroot.
            '/blocks/fn_mentor/pix/b.gif"><a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview_single.php?menteeid='.
            $menteeid.'&groupid=' . $groupid.'&courseid='.$enrolledcourse->id.'">'.$course_fullname.'</a></div>';
    }
}

echo '<div class="mentee-course-overview-block">
          <div class="mentee-course-overview-block-title">
              '.get_string('courses', 'block_fn_mentor').'
          </div>
          <div class="mentee-course-overview-block-content">
              '.$courselist.'
          </div>
      </div>';


echo '</div>';

$classoverview = '';
$classgrade = '';
$classactivity = '';
$classnote = '';
$classnotification = '';

if ($navpage == 'overview') {
    $classoverview = ' class="active"';
} else if ($navpage == 'grade') {
    $classgrade = ' class="active"';
} else if ($navpage == 'outline') {
    $classactivity = ' class="active"';
} else if ($navpage == 'note') {
    $classnote = ' class="active"';
} else if ($navpage == 'notification') {
    $classnotification = ' class="active"';
}

// CENTER.
$course_fullname = format_string($course->fullname); //allow mlang filters to process language strings
echo '<div id="mentee-course-overview-center-single" class="block">'.
    '<div id="mentee-course-overview-center-menu-container">';
echo '<div class="mentee-course-overview-center-course-title"><a  href="'.$CFG->wwwroot.'/course/view.php?id='.
    $course->id.'" onclick="window.open(\''.$CFG->wwwroot.'/course/view.php?id='.$course->id.
    '\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,directories=no,'.
    'scrollbars=yes,resizable=yes\'); return false;" class="" >'.$course_fullname.'</a></div>';

echo '<div class="mentee-course-overview-center-course-menu">
          <table class="mentee-menu">
            <tr>
                <td'.$classoverview.'><a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview_single.php?menteeid='.
    $menteeid.'&groupid=' . $groupid.'&courseid='.$courseid.'">Overview</a></td>
                <td'.$classgrade.'><a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview_single.php?page=grade&menteeid='.
    $menteeid.'&groupid=' . $groupid.'&courseid='.$courseid.'">Grades</a></td>
                <td'.$classactivity.'><a href="'.$CFG->wwwroot.
    '/blocks/fn_mentor/course_overview_single.php?page=outline&menteeid='.
    $menteeid.'&groupid=' . $groupid.'&courseid='.$courseid.'">Activity</a></td>';

echo '</tr>
          </table>
          </div>';

echo '</div>';

if ($navpage == 'overview') {
    $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

    echo '<div class="mentee-course-overview-center_course">';

    $context = context_course::instance($course->id);

    $progressdata = block_fn_mentor_activity_progress($course, $menteeid, $modgradesarray);

    $progress = '';

    foreach ($progressdata->content->items as $key => $value) {
        $progress .= '<div class="overview-progress-list">'.$progressdata->content->icons[$key] .
            $progressdata->content->items[$key] . '</div>';
    }
	//  Get enrollment dates.
	$enroldata = get_enrollment_dates($menteeid, $enrolledcourse->id);

    echo '<table class="mentee-course-overview-center_table">';
    echo '<tr>';

    echo '<td valign="top" class="mentee-grey-border">';
	// Enrollment dates.
	if ($enroldata->enrolstart && $enroldata->enrolend){
	echo '<div class="overview-teacher">';
	echo '<table class="mentee-teacher-table">';
	echo '<tr><td class="mentee-teacher-table-label" valign="top"><span>'.get_string('enroldates', 'block_fn_mentor').':</span></td>';
	echo '<td valign="top"><small>'.date('n/j/Y', $enroldata->enrolstart).' - '.date('n/j/Y', $enroldata->enrolend).'</small></td>';
	echo '</tr>';
	echo '</table>';
	echo '</div>';
	}
	// Session times. (External DB).

	$session = get_session_times($enrolledcourse->id);

	if ($session){
		echo '<div class="overview-teacher">';
		echo '<table class="mentee-teacher-table">';
		echo '<tr><td class="mentee-teacher-table-label" valign="top"><span>'.get_string('sessions', 'block_fn_mentor').':</span></td>';
		echo '<td valign="top"><small>'.$session['meeting'].'</small></td>';
		echo '</tr>';
		echo '</table>';
		echo '</div>';
	} else {
		echo '<div class="overview-teacher">';
		echo '<table class="mentee-teacher-table">';
		echo '<tr><td class="mentee-teacher-table-label" valign="top"><span>'.get_string('sessions', 'block_fn_mentor').':</span></td>';
		echo '<td valign="top"><small>'.get_string('sessionsnone', 'block_fn_mentor').'</small></td>';
		echo '</tr>';
		echo '</table>';
		echo '</div>';
	}
    echo '<div class="overview-teacher">';
    echo '<table class="mentee-teacher-table">';
    // Course teachers.
    $sqltecher = "SELECT u.id,
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
                     AND ctx.instanceid = ?";

    if ($teachers = $DB->get_records_sql($sqltecher, array(50, 3, $course->id))) {
        echo '<tr><td class="mentee-teacher-table-label" valign="top"><span>'.
            get_string('teacher', 'block_fn_mentor').': </span></td><td valign="top">';

        foreach ($teachers as $teacher) {
			 // Extra param for last access.
            $lastaccess = get_string('lastaccess').get_string('labelsep', 'langconfig').
                block_fn_mentor_format_time(time(), $teacher->lastaccess);

            echo '<div><a onclick="window.open(\''.$CFG->wwwroot.'/user/profile.php?id='.$teacher->id.
                '\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,directories=no,'.
                'scrollbars=yes,resizable=yes\'); return false;" href="'.$CFG->wwwroot.'/user/profile.php?id='.
                $teacher->id.'">' . $teacher->firstname.' '.$teacher->lastname.'</a><a onclick="window.open(\''.
                $CFG->wwwroot.'/message/index.php?id='.$teacher->id.'\', \'\', \'width=800,height=600,toolbar=no,location=no,'.
                'menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes\'); return false;" href="'.
                $CFG->wwwroot.'/user/profile.php?id='.$teacher->id.'"><img src="'.
                $CFG->wwwroot.'/blocks/fn_mentor/pix/email.png"></a><br />'.
                '<span class="mentee-lastaccess">'.$lastaccess.'</span></div>';
        }

    }
    echo '</table>';
    echo '</div>';

    echo '<div class="overview-mentor">';
    echo '<table class="mentee-teacher-table">';
    if ($mentors = block_fn_mentor_get_mentors($menteeuser->id)) {
        echo '<tr><td class="mentee-teacher-table-label" valign="top"><span>';
        echo (get_config('mentor', 'blockname')) ? get_config('mentor',
            'blockname') : get_string('mentor', 'block_fn_mentor').': ';
        echo '</span></td><td valign="top">';
        foreach ($mentors as $mentor) {
			 // Extra param for last access.
            $lastaccess = get_string('lastaccess').get_string('labelsep',
                    'langconfig'). block_fn_mentor_format_time(time(), $mentor->lastaccess);

            echo '<div><a onclick="window.open(\''.$CFG->wwwroot.'/user/profile.php?id='.$mentor->mentorid.
                '\', \'\', \'width=620,height=450,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,directories=no,'.
                'scrollbars=yes,resizable=yes\'); return false;" href="'.$CFG->wwwroot.'/user/profile.php?id='.
                $mentor->mentorid.'">' . $mentor->firstname.' '.$mentor->lastname.'</a><a onclick="window.open(\''.
                $CFG->wwwroot.'/message/index.php?id='.$mentor->mentorid.'\', \'\', \'width=620,height=450,toolbar=no,'.
                'location=no,menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,'.
                'resizable=yes\'); return false;" href="'.$CFG->wwwroot.'/user/profile.php?id='.$mentor->mentorid.'"><img src="'.
                $CFG->wwwroot.'/blocks/fn_mentor/pix/email.png"></a><br />'.
                '<span class="mentee-lastaccess">'.$lastaccess.'</span></div>';
        }
        echo '</td></tr>';
    }
    echo '</table>';
    echo '</div>';
    echo '</td>';
    // Progress.
    echo '<td valign="top" style="height: 100%;" class="mentee-blue-border">';


    echo '<table style="height: 100%; width: 100%;">';
    echo '<tr>';
    echo '<td class="overview-progress blue">';
    echo get_string('progress', 'block_fn_mentor');
    echo '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="vertical-textd" valign="middle">';
    echo $progress;
    echo '</td>';
    echo '</tr>';
	//  Progress bar.
		echo '<tr>';
		echo '<td>';
		$progressbar = block_fn_mentor_print_activity_progress ($course , $menteeuser->id);
		echo $progressbar;
		echo '</td>';
		echo '</tr>';
		// End
    echo '</table>';



    echo '</td>';
    // Grade.
    echo '<td valign="top" style="height: 100%;" class="mentee-blue-border">';

    echo '<table style="height: 100%; width: 100%;">';
    echo '<tr>';
    echo '<td class="overview-progress blue">';
    echo get_string('grade', 'block_fn_mentor');
    echo '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="vertical-textd" valign="middle">';
    echo block_fn_mentor_print_grade_summary ($course->id , $menteeuser->id);
    echo '</td>';
    echo '</tr>';
    echo '</table>';

    echo '</td>';

    echo '</tr>';
    echo '</table>';

    echo '</div>'; 
	// Mentee course overview center course.
	

    // SIMPLE GRADE BOOK.
    echo '<table class="simple-gradebook">';
    echo '<tr>';
    echo '<td class="blue">'.get_string('submitted_activities', 'block_fn_mentor');
    echo '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="white mentee-blue-border" align="middle">';

    list($simplegradebook, $weekactivitycount, $courseformat) = block_fn_mentor_simplegradebook(
        $course, $menteeuser, $modgradesarray
    );
    echo '<div class="tablecontainer">';
    $gradebook = reset($simplegradebook);
    if (isset($gradebook['grade'])) {
        // TABLE.
        echo "<table class='simplegradebook'>";
        echo "<tr>";
        foreach ($weekactivitycount as $key => $value) {
            if ($value['numofweek']) {
                foreach ($value['mod'] as $index => $imagelink) {
                    $longactivityname = $value['modname'][$index];
                    $displayname= shorten_text($value['modname'][$index], 30);
                    $formattedactivityname = format_string($displayname, true, array('context' => $context));
                    echo '<th scope="col" align="center">'.
                        '<span class="completion-activityname">'.
                        $formattedactivityname.'</span></th>';
                }
            }
        }
        echo "</tr>";
        echo "<tr>";
        foreach ($weekactivitycount as $key => $value) {
            if ($value['numofweek']) {
                foreach ($value['mod'] as $imagelink) {
                    echo '<td class="mod-icon">'.$imagelink.'</td>';
                }
            }
        }
        echo "</tr>";
        $counter = 0;
        foreach ($simplegradebook as $studentid => $studentreport) {
            $counter++;
            if ($counter % 2 == 0) {
                $studentclass = "even";
            } else {
                $studentclass = "odd";
            }
            echo '<tr>';

            $gradetot = 0;
            $grademaxtot = 0;
            $avg = 0;

			//  Moved to align table view.								
			/*if (!isset($studentreport['avg'])) {
                echo '<td class="red"> - </td>';
            }*/							
            foreach ($studentreport['grade'] as $sgrades) {
                foreach ($sgrades as $sgrade) {
                    echo '<td class="'.$studentclass.' icon">'.'<img src="' . $CFG->wwwroot . '/blocks/fn_mentor/pix/'.
                        $sgrade.'" height="16" width="16" alt="">'.'</td>';
                }
            }
			if (!isset($studentreport['avg'])) {
                echo '<td class="red"> - </td>';
            }
            echo '</tr>';
        }

        echo "</table>";
    } else {
        echo '<div class="mentees-error">'.get_string('no_activities', 'block_fn_mentor').'</div>';
    }



    echo "</div>";
        echo '</td>';
    echo '</tr>';
    echo '</table>';

    // NOTES.
    if ($view = has_capability('block/fn_mentor:viewcoursenotes', context_system::instance()) && $allownotes) {
        echo '<table class="simple-gradebook">';
        echo '<tr>';
        echo '<td class="blue">'.get_string('notes', 'block_fn_mentor');
        echo '<div class="fz_popup_wrapper_single">
                  <a  href="'.$CFG->wwwroot.'/notes/index.php?user='.$menteeuser->id.'"
                                        onclick="window.open(\''.$CFG->wwwroot.'/notes/index.php?user='.
            $menteeuser->id.'\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,'.
            'directories=no,scrollbars=yes,resizable=yes\'); return false;" class="" ><img src="'.
            $CFG->wwwroot.'/blocks/fn_mentor/pix/popup_icon.gif"></a>
              </div>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td class="white mentee-blue-border">';

        if ($courseid && $view) {
            $sqlnotes = "SELECT p.*, c.fullname
                           FROM {post} p
                     INNER JOIN {course} c
                             ON p.courseid = c.id
                          WHERE p.module = 'notes'
                            AND p.userid = ?
                            AND p.courseid = ?
                            AND p.publishstate IN ('site', 'public')
                          UNION
                         SELECT p.*, c.fullname
                           FROM {post} p
                     INNER JOIN {course} c
                             ON p.courseid = c.id
                          WHERE p.module = 'notes'
                            AND p.userid = ?
                            AND p.courseid = ?
                            AND p.usermodified = ?
                            AND p.publishstate IN ('draft')
                       ORDER BY lastmodified DESC";

            if ($notes = $DB->get_records_sql($sqlnotes, array(
                $menteeuser->id, $courseid, $menteeuser->id, $courseid, $USER->id), 0, 5)) {
                foreach ($notes as $note) {
                    $ccontext = context_course::instance($note->courseid);
                    $cfullname = format_string($note->fullname, true, array('context' => $ccontext));
                    $header = '<h3 class="notestitle"><a href="' . $CFG->wwwroot . '/course/view.php?id=' .
                        $note->courseid . '">' . $cfullname . '</a></h3>';
                    echo $header;
                    block_fn_mentor_note_print($note, NOTES_SHOW_FULL);
                }
                // Show all notes.
                echo '<a  href="'.$CFG->wwwroot.'/notes/index.php?user='.$menteeuser->id.'" onclick="window.open(\''.
                    $CFG->wwwroot.'/notes/index.php?user='.$menteeuser->id.'\', \'\', \'width=800,height=600,toolbar=no,'.
                    'location=no,menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes\'); '.
                    'return false;" class="" >'.get_string('show_all_notes', 'block_fn_mentor').'</a>';
            } else {
                // Add a note.
                echo '<a  href="'.$CFG->wwwroot.'/notes/index.php?user='.$menteeuser->id.'" onclick="window.open(\''.
                    $CFG->wwwroot.'/notes/index.php?user='.$menteeuser->id.'\', \'\', \'width=800,height=600,toolbar=no,'.
                    'location=no,menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes\'); '.
                    'return false;" class="" >'.get_string('add_a_note', 'block_fn_mentor').'</a>';
            }
        }

        echo '</td>';
        echo '</tr>';
        echo '</table>';
    }
} else if ($navpage == 'grade') {
    echo '<div class="mentee-grade_report">';
    // Basic access checks.
    if (!$course = $DB->get_record('course', array('id' => $courseid))) {
        print_error('nocourseid');
    }

    grade_report_user_profilereport($course, $menteeuser, true);

    echo '</div>';

} else if ($navpage == 'outline') {

    require_once($CFG->dirroot.'/report/outline/locallib.php');

    $userid   = required_param('menteeid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $mode     = optional_param('mode', 'outline', PARAM_ALPHA);

    if ($mode !== 'complete' and $mode !== 'outline') {
        $mode = 'outline';
    }

    $user = $DB->get_record('user', array('id' => $userid, 'deleted' => 0), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

    $coursecontext   = context_course::instance($course->id);
    $personalcontext = context_user::instance($user->id);

    $manager = get_log_manager();
    if (method_exists($manager, 'legacy_add_to_log')) {
        $manager->legacy_add_to_log($course->id, 'course',
            'report outline', "report/outline/user.php?id=$user->id&course=$course->id&mode=$mode", $course->id
        );
    }

    $stractivityreport = get_string('activityreport');

    $modinfo = get_fast_modinfo($course);
    $sections = $modinfo->get_section_info_all();
    $itemsprinted = false;

    foreach ($sections as $i => $section) {

        if ($section->uservisible) {
            // Check the section has modules/resources, if not there is nothing to display.
            if (!empty($modinfo->sections[$i])) {
                $itemsprinted = true;
                echo '<div class="section">';
                echo '<h2>';
                echo get_section_name($course, $section);
                echo "</h2>";

                echo '<div class="content">';

                if ($mode == "outline") {
                    echo "<table cellpadding=\"4\" cellspacing=\"0\">";
                }

                foreach ($modinfo->sections[$i] as $cmid) {
                    $mod = $modinfo->cms[$cmid];

                    $instance = $DB->get_record("$mod->modname", array("id" => $mod->instance));
                    $libfile = "$CFG->dirroot/mod/$mod->modname/lib.php";

                    if (file_exists($libfile)) {
                        require_once($libfile);

                        switch ($mode) {
                            case "outline":
                                $useroutline = $mod->modname."_user_outline";
                                if (function_exists($useroutline)) {
                                    $output = $useroutline($course, $user, $mod, $instance);
                                    block_fn_mentor_report_outline_print_row($mod, $instance, $output);
                                }
                                break;
                            case "complete":
                                $usercomplete = $mod->modname."_user_complete";
                                if (function_exists($usercomplete)) {
                                    $image = $OUTPUT->pix_icon(
                                        'icon', $mod->modfullname, 'mod_'.$mod->modname, array('class' => 'icon')
                                    );
                                    echo "<h4>$image $mod->modfullname: ".
                                        "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/$mod->modname/view.php?id=$mod->id\">".
                                        format_string($instance->name, true)."</a></h4>";

                                    ob_start();

                                    echo "<ul>";
                                    $usercomplete($course, $user, $mod, $instance);
                                    echo "</ul>";

                                    $output = ob_get_contents();
                                    ob_end_clean();

                                    if (str_replace(' ', '', $output) != '<ul></ul>') {
                                        echo $output;
                                    }
                                }
                                break;
                        }
                    }
                }

                if ($mode == "outline") {
                    echo "</table>";
                }
                echo '</div>';
                echo '</div>';
            }
        }
    }
} else if ($navpage == 'notes') {

    require_once($CFG->dirroot.'/notes/lib.php');

    // Retrieve parameters.
    $courseid     = optional_param('courseid', SITEID, PARAM_INT);
    $userid       = optional_param('menteeid', 0, PARAM_INT);
    $filtertype   = optional_param('filtertype', '', PARAM_ALPHA);
    $filterselect = optional_param('filterselect', 0, PARAM_INT);

    if (empty($CFG->enablenotes)) {
        print_error('notesdisabled', 'notes');
    }

    $url = new moodle_url('/notes/index.php');
    if ($courseid != SITEID) {
        $url->param('course', $courseid);
    }
    if ($userid !== 0) {
        $url->param('user', $userid);
    }
    $PAGE->set_url($url);

    // Tabs compatibility.
    switch($filtertype) {
        case 'course':
            $courseid = $filterselect;
            break;
        case 'site':
            $courseid = SITEID;
            break;
    }

    if (empty($courseid)) {
        $courseid = SITEID;
    }

    // Locate course information.
    $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

    // Locate user information.
    if ($userid) {
        $user = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);
        $filtertype = 'user';
        $filterselect = $user->id;

        if ($user->deleted) {
            echo $OUTPUT->header();
            echo $OUTPUT->heading(get_string('userdeleted'));
            echo $OUTPUT->footer();
            die;
        }

    } else {
        $filtertype = 'course';
        $filterselect = $course->id;
    }

    // Output HTML.
    if ($course->id == SITEID) {
        $coursecontext = context_system::instance();
    } else {
        $coursecontext = context_course::instance($course->id);
    }

    $systemcontext = context_system::instance();

    add_to_log($courseid, 'notes', 'view', 'index.php?course='.$courseid.'&amp;user='.$userid, 'view notes');

    $strnotes = get_string('notes', 'notes');

    if ($userid) {
        echo $OUTPUT->heading(fullname($user).': '.$strnotes);
    } else {
        echo $OUTPUT->heading(format_string($course->shortname, true, array('context' => $coursecontext)).': '.$strnotes);
    }

    $strsitenotes = get_string('sitenotes', 'notes');
    $strcoursenotes = get_string('coursenotes', 'notes');
    $strpersonalnotes = get_string('personalnotes', 'notes');
    $straddnewnote = get_string('addnewnote', 'notes');

    echo $OUTPUT->box_start();

    if ($courseid != SITEID) {
        $context = context_user::instance($userid);
        $addid = has_capability('moodle/notes:manage', $context) ? $courseid : 0;
        $view = has_capability('moodle/notes:view', $context);
        $fullname = format_string($course->fullname, true, array('context' => $context));
        note_print_notes('<a name="sitenotes"></a>' . $strsitenotes, $addid, $view, 0, $userid, NOTES_STATE_SITE, 0);
        note_print_notes('<a name="coursenotes"></a>' . $strcoursenotes. ' ('.$fullname.')',
            $addid, $view, $courseid, $userid, NOTES_STATE_PUBLIC, 0
        );
        note_print_notes('<a name="personalnotes"></a>' . $strpersonalnotes, $addid,
            $view, $courseid, $userid, NOTES_STATE_DRAFT, $USER->id
        );

    } else {
        $view = has_capability('moodle/notes:view', context_system::instance());
        note_print_notes('<a name="sitenotes"></a>' . $strsitenotes, 0, $view, 0, $userid, NOTES_STATE_SITE, 0);
        echo '<a name="coursenotes"></a>';

        if (!empty($userid)) {
            $courses = enrol_get_users_courses($userid);
            foreach ($courses as $c) {
                $ccontext = context_course::instance($c->id);
                $cfullname = format_string($c->fullname, true, array('context' => $ccontext));
                $header = '<a href="' . $CFG->wwwroot . '/course/view.php?id=' . $c->id . '">' . $cfullname . '</a>';
                if (has_capability('moodle/notes:manage', context_course::instance($c->id))) {
                    $addid = $c->id;
                } else {
                    $addid = 0;
                }
                note_print_notes($header, $addid, $view, $c->id, $userid, NOTES_STATE_PUBLIC, 0);
            }
        }
    }

    echo $OUTPUT->box_end();

}

echo '</div>'; // Mentee course overview center single.
echo '</div>'; // Mentee course overview page.

echo block_fn_mentor_footer();

echo $OUTPUT->footer();
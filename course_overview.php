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
require_once($CFG->dirroot . '/blocks/fn_mentor/lib.php');
require_once($CFG->dirroot . '/notes/lib.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/grade/lib.php');

// Parameters.
$menteeid = optional_param('menteeid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$groupid  = optional_param('groupid', 0, PARAM_INT);

// Array of functions to call for grading purposes for modules. (- new function to add additional).
$modgradesarray = get_graded_mods();

require_login(null, false);

// PERMISSION.
$isadmin   = has_capability('block/fn_mentor:manageall', context_system::instance());
$ismentor  = block_fn_mentor_has_system_role($USER->id, get_config('block_fn_mentor', 'mentor_role_system'));
$isteacher = block_fn_mentor_isteacherinanycourse($USER->id);
$isstudent = block_fn_mentor_isstudentinanycourse($USER->id);
$menteecanview = get_config('block_fn_mentor', 'menteecanview'); // Use the setting students can view for student views.									 

$allownotes = get_config('block_fn_mentor', 'allownotes');

if ($allownotes && $ismentor) {
    $allownotes = true;
} else if ($isadmin || $isteacher ) {
    $allownotes = true;
} else {
    $allownotes = false;
}


// Find Mentees.
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

if (($USER->id <> $menteeid) && !$isadmin && !in_array($menteeid, array_keys($mentees))) {
    print_error('invalidpermission', 'block_fn_mentor');
}
if (($isstudent) && ($USER->id <> $menteeid)  && (!$isteacher && !$isadmin && !$ismentor)) {
    print_error('invalidpermission', 'block_fn_mentor');
}

$menteeuser = $DB->get_record('user', array('id' => $menteeid), '*', MUST_EXIST);

$title = get_string('page_title_assign_mentor', 'block_fn_mentor');
$heading = $SITE->fullname;

$PAGE->set_url('/blocks/fn_mentor/course_overview.php');

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

$PAGE->navbar->add(get_string('pluginname', 'block_fn_mentor'),
    new moodle_url('/blocks/fn_mentor/course_overview.php', array('menteeid' => $menteeid))
);

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
} else if ($ismentor) {
    $sql = "SELECT g.id, g.name
              FROM {block_fn_mentor_group} g
              JOIN {block_fn_mentor_group_mem} gm
                ON g.id = gm.groupid
             WHERE gm.role = ?
               AND gm.userid = ?
          ORDER BY g.name ASC";
    $groups = $DB->get_records_sql($sql, array('M', $USER->id));
} else if ($isstudent) {
	//  Account for student views.
	$groups = '';
}

$groupmenu = array();
$groupmenuurl = array();
$groupmenuhtml = '';

$groupmenuurl[0] = $CFG->wwwroot.'/blocks/fn_mentor/course_overview.php?menteeid='.$menteeid.'&groupid=0';
$groupmenu[$groupmenuurl[0]] = get_string('allmentorgroups', 'block_fn_mentor');

if ($groups) {
    foreach ($groups as $group) {
        $groupmenuurl[$group->id] = $CFG->wwwroot . '/blocks/fn_mentor/course_overview.php?menteeid=' . $menteeid . '&groupid=' . $group->id;
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
        $studentmenuurl[$mentee->studentid] = $CFG->wwwroot.'/blocks/fn_mentor/course_overview.php?menteeid='.$mentee->studentid.'&groupid='.$groupid;
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
    $OUTPUT->container('<a  href="'.$CFG->wwwroot.'/user/view.php?id='.$menteeuser->id.'&course=1" '.
        'onclick="window.open(\''.$CFG->wwwroot.'/user/view.php?id='.$menteeuser->id.
        '&course=1\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,'.
        'directories=no,scrollbars=yes,resizable=yes\'); return false;" class="" >'.fullname($menteeuser, true).'</a>'.
        '&nbsp;&nbsp;<a href="'.$CFG->wwwroot.'/message/index.php?id='.$menteeuser->id.'" ><img src="'.$CFG->wwwroot.
        '/blocks/fn_mentor/pix/email.png"></a>', "userfullname"
    ).
    '<span class="mentee-lastaccess">'.$lastaccess.'</span>' .
    '</div></div>';

// COURSES.
if (!$enrolledcourses = enrol_get_all_users_courses($menteeid, false, 'id,fullname,shortname', 'fullname ASC')) {
    $enrolledcourses = array();
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

$courseids = implode(",", array_keys($enrolledcourses));

$courselist = "";

if ($courseid == 0) {
    $courselist .= '<div class="allcourses active">'.
        '<a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview.php?menteeid='.$menteeid.'&courseid=0">'.
        get_string('allcourses', 'block_fn_mentor').'</a></div>';
} else {
    $courselist .= '<div class="allcourses">'.
        '<a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview.php?menteeid='.$menteeid.'&courseid=0">'.
        get_string('allcourses', 'block_fn_mentor').'</a></div>';
}
foreach ($enrolledcourses as $enrolledcourse) {
       $course_fullname = format_string($enrolledcourse->fullname); //allow mlang filters to process language strings
    if ($courseid == $enrolledcourse->id) {
        $courselist .= '<div class="courselist active">
            <img class="mentees-course-bullet" src="'.$CFG->wwwroot.'/blocks/fn_mentor/pix/b.gif">'.
            '<a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview_single.php?menteeid='.
            $menteeid.'&courseid='.$enrolledcourse->id.'">'.$course_fullname.'</a></div>';
    } else {
        $courselist .= '<div class="courselist">
            <img class="mentees-course-bullet" src="'.$CFG->wwwroot.'/blocks/fn_mentor/pix/b.gif">'.
            '<a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview_single.php?menteeid='.
            $menteeid.'&courseid='.$enrolledcourse->id.'">'.$course_fullname.'</a></div>';
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

//  Add LEARNING PLANS.
  // Requires mentor to have moodle/competency:planview set to allow in role. 
if(get_config('core_competency', 'enabled')) {
	$userid = optional_param('userid', $menteeuser->id, PARAM_INT);
	$view = \core_competency\plan::can_read_user($userid);
	$lp_url = new moodle_url('/admin/tool/lp/plans.php', array('userid' => $userid));
	$plans = \core_competency\api::list_user_plans($userid);
	$tooltip = 'View complete report for all learning plans.';
	$reports = core_component::get_plugin_list('report');
    $lpmonitoring= false;
    if ($reports['lpmonitoring']) {
		$lp_url = new moodle_url('/report/lpmonitoring/userreport.php', array('userid' => $menteeuser->id));
		
	}
	if ($view) {
		echo '<div class="mentee-course-overview-block">
              <div class="mentee-course-overview-block-title">
                  Learning Plans
              </div>
              <div class="fz_popup_wrapper">
                  <a  href="'.$lp_url.'" data-toggle="tooltip" title="'.$tooltip.'"
                  onclick="window.open(\''.$lp_url.'\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,'.
                  'status=no,directories=no,scrollbars=yes,resizable=yes\'); return false;"
                                        class="" ><img src="'.$CFG->wwwroot.'/blocks/fn_mentor/pix/popup_icon.gif"></a>
              </div>
              <div class="mentee-course-overview-block-content">';
             
			// Learning plan. 
			$lp_count = count($plans);
			foreach($plans as $plan) {
				$exporter = new \core_competency\external\plan_exporter($plan, array('template' => $plan->get_template()));
				$record = $exporter->export($OUTPUT);
				$plan_record = '<div class="courselist"><img class="mentees-course-bullet" src="'.$CFG->wwwroot.'/blocks/fn_mentor/pix/b.gif"> <a href="' . $CFG->wwwroot .
						'/admin/tool/lp/plan.php?id=' . $record->id . '">' . $record->name . '</a>';
				if($record->duedate) {
					$due_date = $record->duedateformatted;
					$plan_record .= ' (Due: '.$due_date.')';
				}
				if($record->iscompleted) {
					$plan_record .= ' <em>- Completed</em> ';
				}
				$plan_record .= '</div>';
				
				echo $plan_record;
		
			}
			
		echo     '</div>
				</div>';
	}
}	
// NOTES.
if ($view = has_capability('block/fn_mentor:viewcoursenotes', context_system::instance()) && $allownotes) {
    echo '<div class="mentee-course-overview-block">
              <div class="mentee-course-overview-block-title">
                  '.get_string('notes', 'block_fn_mentor').'
              </div>
              <div class="fz_popup_wrapper">
                  <a  href="'.$CFG->wwwroot.'/notes/index.php?user='.$menteeuser->id.'"
                  onclick="window.open(\''.$CFG->wwwroot.'/notes/index.php?user='.
                  $menteeuser->id.'\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,'.
                  'status=no,directories=no,scrollbars=yes,resizable=yes\'); return false;"
                                        class="" ><img src="'.$CFG->wwwroot.'/blocks/fn_mentor/pix/popup_icon.gif"></a>
              </div>
              <div class="mentee-course-overview-block-content">';



    // COURSE NOTES.
    if ($courseids && $view) {
        $sqlnotes = "SELECT p.*, c.fullname
                                FROM {post} p
                          INNER JOIN {course} c
                                  ON p.courseid = c.id
                               WHERE p.module = 'notes'
                                 AND p.userid = ?
                                 AND p.courseid IN ($courseids)
                                 AND p.publishstate IN ('site', 'public')
                            ORDER BY p.lastmodified DESC";

        if ($notes = $DB->get_records_sql($sqlnotes, array($menteeuser->id), 0, 3)) {
            foreach ($notes as $note) {
                $ccontext = context_course::instance($note->courseid);
                $cfullname = format_string($note->fullname, true, array('context' => $ccontext));
                $header = '<h3 class="notestitle"><a href="' . $CFG->wwwroot .
                    '/course/view.php?id=' . $note->courseid . '">' . $cfullname . '</a></h3>';
                echo $header;
                block_fn_mentor_note_print($note, NOTES_SHOW_FULL);
            }
            // Show all notes.
            echo '<a  href="'.$CFG->wwwroot.'/notes/index.php?user='.$menteeuser->id.
                '"onclick="window.open(\''.$CFG->wwwroot.'/notes/index.php?user='.
                $menteeuser->id.'\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,'.
                'directories=no,scrollbars=yes,resizable=yes\'); return false;" class="" >'.
                get_string('show_all_notes', 'block_fn_mentor').'</a>';
        } else {
            // Add a note.
            echo '<a  href="'.$CFG->wwwroot.'/notes/index.php?user='.$menteeuser->id.
                '"onclick="window.open(\''.$CFG->wwwroot.'/notes/index.php?user='.
                $menteeuser->id.'\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,'.
                'directories=no,scrollbars=yes,resizable=yes\'); return false;" class="" >'.
                get_string('add_a_note', 'block_fn_mentor').'</a>';
        }
    }


    echo     '</div>
          </div>';
}
echo '</div>';

// CENTER.
echo '<div id="mentee-course-overview-center">';

if ($enrolledcourses) {

    foreach ($enrolledcourses as $enrolledcourse) {
        $course_fullname = format_string($enrolledcourse->fullname); //allow mlang filters to process language strings
        
        if ($courseid && ($courseid <> $enrolledcourse->id)) {
            continue;
        }

        $course = $DB->get_record('course', array('id' => $enrolledcourse->id), '*', MUST_EXIST);

        echo '<div class="mentee-course-overview-center_course">';

        $context = context_course::instance($course->id);

        $progressdata = block_fn_mentor_activity_progress($course, $menteeid, $modgradesarray);

        $progresshtml = '';

        foreach ($progressdata->content->items as $key => $value) {
            $progresshtml .= '<div class="overview-progress-list">' . $progressdata->content->icons[$key] .
                $progressdata->content->items[$key] . '</div>';
        }
		//  Add enrollment dates.
		$enroldata = get_enrollment_dates($menteeid, $enrolledcourse->id);

        echo '<table class="mentee-course-overview-center_table block">';
        echo '<tr>';

        echo '<td valign="top" class="mentee-grey-border">';
        echo '<div class="overview-course coursetitle"><a  href="' . $CFG->wwwroot . '/course/view.php?id=' .
            $enrolledcourse->id . '" onclick="window.open(\'' . $CFG->wwwroot . '/course/view.php?id=' .
            $enrolledcourse->id . '\', \'\', \'width=800,height=600,toolbar=no,location=no,menubar=no,'.
            'copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes\'); return false;" class="" >' .
            $course_fullname . '</a></div>';
		// Enrollment dates.
		if ($enroldata->enrolstart && $enroldata->enrolend){
		echo '<div style="text-align:center"><small><b>'.get_string('enroldates', 'block_fn_mentor').':</b><br>'.date('n/j/Y', $enroldata->enrolstart).' - '.date('n/j/Y', $enroldata->enrolend).'</small></div>';
		} else {
			echo '<div style="text-align:center"><small><b>'.get_string('enroldates', 'block_fn_mentor').':</b><br>'.get_string('enroldatesnone', 'block_fn_mentor').'</small></div>';
		}
		// Add Session times.

		$session = get_session_times($enrolledcourse->id);

		if ($session){
		echo '<div style="text-align:center"><small><b>'.get_string('sessions', 'block_fn_mentor').':</b><br>'.$session['meeting'].'</small></div>';
		} else {
			echo '<div style="text-align:center"><small><b>'.get_string('sessions', 'block_fn_mentor').':</b><br>'.get_string('sessionsnone', 'block_fn_mentor').'</small></div>';
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
            $numofteachers = count($teachers);
            $teacherlist = '';
            $teacherlabel = get_string('teacher', 'block_fn_mentor');
            foreach ($teachers as $teacher) {
				// Extra param for last access.
                $lastaccess = get_string('lastaccess') . get_string('labelsep', 'langconfig') .
                    block_fn_mentor_format_time(time(), $teacher->lastaccess);
                $teacherlist .= block_fn_mentor_teacher_link ($teacher->id, $lastaccess);
            }
            if ($numofteachers > 1) {
                $teacherlabel = $teacherlabel.'s';
            }
            echo '<tr><td class="mentee-teacher-table-label" valign="top"><span>' . $teacherlabel . ': </span></td><td valign="top">';
            if ($numofteachers > 1) {
                echo $numofteachers . ' ' . $teacherlabel;
                echo block_fn_mentor_modal_win(
                    'modalTeacher'.$enrolledcourse->id,
                    get_string('showallteachers', 'block_fn_mentor'),
                    $enrolledcourse->fullname,
                    $teacherlabel,
                    $teacherlist
                );
            } else {
                echo $teacherlist;
            }

        }
        echo '</table>';
        echo '</div>';

        echo '<div class="overview-mentor">';
        echo '<table class="mentee-teacher-table">';
        if ($mentors = block_fn_mentor_get_mentors($menteeuser->id)) {
            $numofmentors = count($mentors);
            $mentorlist = '';
            $mentorlabel = (get_config('mentor', 'blockname')) ? get_config('mentor',
                'blockname') : get_string('mentor', 'block_fn_mentor');

            foreach ($mentors as $mentor) {
				// Extra param for last access.
                $lastaccess = get_string('lastaccess') . get_string('labelsep', 'langconfig') .
                    block_fn_mentor_format_time(time(), $mentor->lastaccess);
                $mentorlist .= block_fn_mentor_teacher_link($mentor->mentorid, $lastaccess);
            }
            echo '<tr><td class="mentee-teacher-table-label" valign="top"><span>';
            if ($numofmentors > 1) {
                $mentorlabel .= 's';
            }
            echo $mentorlabel . ': ';
            echo '</span></td><td valign="top">';
            if ($numofmentors > 1) {
                echo $numofmentors . ' ' . $mentorlabel;
                echo block_fn_mentor_modal_win(
                    'modalMentor'.$enrolledcourse->id,
                    get_string('showallmentors', 'block_fn_mentor'),
                    $enrolledcourse->fullname,
                    $mentorlabel,
                    $mentorlist
                );
            } else {
                echo $mentorlist;
            }
            echo '</td></tr>';
        }
        echo '</table>';
        echo '</div>';
        echo '</td>';
        // Progress.
        $completedcourseclass = '';
        if ($progressdata->timecompleted) {
            $completedcourseclass = 'completed';
        }
        echo '<td valign="top" style="height: 100%;" class="mentee-blue-border">';

        echo '<table style="height: 100%; width: 100%;">';
        echo '<tr>';
        echo '<td class="overview-progress blue">';
        echo get_string('progress', 'block_fn_mentor');
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        if ($progressdata->timecompleted) {
            echo '<td class="vertical-textd completed" valign="middle">';
            echo get_string('completedon', 'block_fn_mentor', date('d M Y', $progressdata->timecompleted));
        } else {
            echo '<td class="vertical-textd" valign="middle">';
            echo $progresshtml;
        }
        echo '</td>';
        echo '</tr>';
		//  Add Progress bar.
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
        echo '<td valign="top" style="height: 100%;" class="mentee-blue-border '.$completedcourseclass.'">';

        echo '<table style="height: 100%; width: 100%;">';

        echo '<tr>';
        echo '<td class="overview-progress blue">';
        echo get_string('grade', 'block_fn_mentor');
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        if ($progressdata->timecompleted) {
            echo '<td class="vertical-textd completed" valign="middle">';
            $gradesummary = block_fn_mentor_grade_summary($menteeuser->id, $course->id);
            echo '<div class="overview-grade-completed green">'.$gradesummary->courseaverage.'%</div>';
        } else {
            echo '<td class="vertical-textd" valign="middle">';
            echo block_fn_mentor_print_grade_summary($course->id, $menteeuser->id);
        }
        echo '</td>';
        echo '</tr>';

        echo '</table>';


        echo '</td>';

        echo '</tr>';
        echo '</table>';

        echo '</div>'; // Mentee course overview center course.
    }

} else {
    echo get_string('notenrolledanycourse', 'block_fn_mentor');
}

echo '</div>'; // Mentee course overview center.

echo '</div>'; // Mentee course overview page.

echo block_fn_mentor_footer();

echo $OUTPUT->footer();
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

defined('MOODLE_INTERNAL') || die();

$settings->add(
    new admin_setting_heading('terminology', get_string('terminology', 'block_fn_mentor'), '')
);

$settings->add(
    new admin_setting_configtext(
        'block_fn_mentor/blockname',
        get_string('blockname', 'block_fn_mentor'),
        '',
        get_string('blocktitle', 'block_fn_mentor')
    )
);

$settings->add(
    new admin_setting_configtext(
        'block_fn_mentor/mentor',
        get_string('wordformentor', 'block_fn_mentor'),
        '',
        get_string('mentor', 'block_fn_mentor')
    )
);

$settings->add(
    new admin_setting_configtext(
        'block_fn_mentor/mentors',
        get_string('wordformentors', 'block_fn_mentor'),
        '',
        get_string('mentors', 'block_fn_mentor')
    )
);

$settings->add(
    new admin_setting_configtext(
        'block_fn_mentor/mentee',
        get_string('wordformentee', 'block_fn_mentor'),
        '',
        get_string('mentee', 'block_fn_mentor')
    )
);

$settings->add(
    new admin_setting_configtext(
        'block_fn_mentor/mentees',
        get_string('wordformentees', 'block_fn_mentor'),
        '',
        get_string('mentees', 'block_fn_mentor')
    )
);

$settings->add(
    new admin_setting_configtext(
        'block_fn_mentor/wordformentorgroup',
        get_string('wordformentorgroup', 'block_fn_mentor'),
        '',
        get_string('mentorgroup', 'block_fn_mentor')
    )
);

$settings->add(
    new admin_setting_configtext(
        'block_fn_mentor/wordformentorgroups',
        get_string('wordformentorgroups', 'block_fn_mentor'),
        '',
        get_string('mentorgroups', 'block_fn_mentor')
    )
);

$settings->add(
    new admin_setting_heading('roles', get_string('roles', 'block_fn_mentor'), '')
);

$roleoptions = array();
$roles = $DB->get_records('role');
foreach ($roles as $role) {
    $roleoptions[$role->id] = $role->shortname;
}
if ($mentorsystem = $DB->get_record('role', array('shortname' => 'mentor'))) {
    $mentorrolesystemdefault = $mentorsystem->id;
} else {
    $mentorrolesystemdefault = 0;
}

if ($mentoruser = $DB->get_record('role', array('shortname' => 'mentor_user'))) {
    $mentorroleuserdefault = $mentoruser->id;
} else {
    $mentorroleuserdefault = 0;
}

$settings->add(
    new admin_setting_configselect(
        'block_fn_mentor/studentrole',
        get_string('studentrole', 'block_fn_mentor'),
        '',
        5,
        $roleoptions
    )
);

$settings->add(
    new admin_setting_configselect(
        'block_fn_mentor/teacherrole',
        get_string('teacherrole', 'block_fn_mentor'),
        '',
        3,
        $roleoptions
    )
);

$settings->add(
    new admin_setting_configselect(
        'block_fn_mentor/mentor_role_system',
        get_string('mentor_role_system', 'block_fn_mentor'),
        '',
        $mentorrolesystemdefault,
        $roleoptions
    )
);

$settings->add(
    new admin_setting_configselect(
        'block_fn_mentor/mentor_role_user',
        get_string('mentor_role_user', 'block_fn_mentor'),
        '',
        $mentorroleuserdefault,
        $roleoptions
    )
);

$settings->add(
    new admin_setting_heading('blockview', get_string('blockview', 'block_fn_mentor'), '')
);

$settings->add(
    new admin_setting_configcheckbox(
        'block_fn_mentor/menteecanview',
        get_string('menteecanview', 'block_fn_mentor'),
        '',
        '0'
    )
);

$settings->add(
    new admin_setting_configtext(
        'block_fn_mentor/maxnumberofmentees',
        get_string('maxnumberofmentees', 'block_fn_mentor'),
        '',
        '15',
        PARAM_INT
    )
);

$settings->add(
    new admin_setting_heading('reportview', get_string('reportview', 'block_fn_mentor'), '')
);

$settings->add(
    new admin_setting_configcheckbox(
        'block_fn_mentor/allownotes',
        get_string('allownotes', 'block_fn_mentor'),
        '',
        '0'
    )
);

$settings->add(
    new admin_setting_configcheckbox(
        'block_fn_mentor/showallstudents',
        get_string('showallstudents', 'block_fn_mentor'),
        '',
        '1'
    )
);

$settings->add(
    new admin_setting_configcheckbox(
        'block_fn_mentor/showsitegroups',
        get_string('showsitegroups', 'block_fn_mentor'),
        '',
        '0'
    )
);

$settings->add(
    new admin_setting_configcheckbox(
        'block_fn_mentor/showgradestatus',
        get_string('showgradestatus', 'block_fn_mentor'),
        '',
        '1'
    )
);

$settings->add(
    new admin_setting_heading('othersettings', get_string('othersettings', 'block_fn_mentor'), '')
);

$coursecaturl = new moodle_url('/blocks/fn_mentor/coursecategories.php');
$settings->add(
    new admin_setting_configempty(
        'block_fn_mentor/coursecategories',
        get_string('coursecategoriesincluded', 'block_fn_mentor'),
        '<a class="btn" href="'.$coursecaturl->out().'">'.get_string('selectcategories', 'block_fn_mentor').'</a>'
    )
);

// Include only current active enrolled courses?
$settings->add(
    new admin_setting_configcheckbox(
        'block_fn_mentor/includecurrentenrollments',
        get_string('includecurrentenrollments', 'block_fn_mentor'),
        '',
        '0'
    )
);

$settings->add(
    new admin_setting_configtext(
        'block_fn_mentor/passinggrade',
        get_string('passinggrade', 'block_fn_mentor'),
        '',
        '50',
        PARAM_INT
    )
);

$settings->add(
    new admin_setting_configcheckbox(
        'block_fn_mentor/includeextranedcolumns',
        get_string('includeextranedcolumns', 'block_fn_mentor'),
        '',
        '0'
    )
);

$settings->add(
    new admin_setting_configcheckbox(
        'block_fn_mentor/usementorgroups',
        get_string('usementorgroups', 'block_fn_mentor'),
        '',
        '0'
    )
);

$themeconfig = theme_config::load($CFG->theme);
$layouts = array();
foreach (array_keys($themeconfig->layouts) as $layout) {
    $layouts[$layout] = $layout;
}

$settings->add(
    new admin_setting_configselect(
        'block_fn_mentor/pagelayout',
        get_string('pagelayout', 'block_fn_mentor'),
        '',
        'login',
        $layouts
    )
);
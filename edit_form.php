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
 * Edit instance form.
 *
 * @package    enrol_ilios
 * @author     Carson Tam <carson.tam@ucsf.edu>
 * @copyright  The Regents of the University of California
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\di;
use enrol_ilios\ilios;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/formslib.php");
require_once("lib.php");

/**
 * Edit instance form class.
 *
 * @package    enrol_ilios
 * @author     Carson Tam <carson.tam@ucsf.edu>
 * @copyright  The Regents of the University of California
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_ilios_edit_form extends moodleform {

    /**
     * Form definition.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    protected function definition(): void {
        global $DB, $PAGE;

        $mform  = $this->_form;
        /* @var enrol_ilios_plugin $plugin This enrolment plugin. */
        list($instance, $plugin, $course, $ilios) = $this->_customdata;
        $coursecontext = context_course::instance($course->id);

        $PAGE->requires->yui_module(
            'moodle-enrol_ilios-groupchoosers',
            'M.enrol_ilios.init_groupchoosers',
            [
                [
                    'formid' => $mform->getAttribute('id'),
                    'courseid' => $course->id,
                ],
            ]
        );

        $enrol = $plugin;

        $mform->addElement('header', 'general', get_string('pluginname', 'enrol_ilios'));
        $mform->addElement('text', 'name', get_string('custominstancename', 'enrol'));
        $mform->addHelpButton('name', 'custominstancename', 'enrol_ilios');
        $mform->setType('name', PARAM_TEXT);

        $options = [ENROL_INSTANCE_ENABLED  => get_string('yes'),
                         ENROL_INSTANCE_DISABLED => get_string('no')];
        $mform->addElement('select', 'status', get_string('status', 'enrol_ilios'), $options);

        $usertypes = [
            get_string('learner', 'enrol_ilios'),
            get_string('instructor', 'enrol_ilios'),
        ];
        $schooloptions = ['' => get_string('choosedots')];
        $programoptions = ['' => get_string('choosedots')];
        $cohortoptions = ['' => get_string('choosedots')];
        $learnergroupoptions = ['' => get_string('none')];
        $hasorphanedlearnergroup = false;
        $subgroupoptions = ['' => get_string('none')];
        $instructorgroupoptions = ['' => get_string('none')];

        if ($instance->id) {
            $synctype = $instance->customchar1;
            $syncid = $instance->customint1;
            $instance->customint2 = isset($instance->customint2) ? $instance->customint2 : 0;
            $syncinfo = json_decode($instance->customtext1);

            $instance->schoolid = $syncinfo->school->id;
            $school = $ilios->get_school($instance->schoolid);
            $instance->selectschoolindex = "$instance->schoolid:$school->title";
            $schooloptions = [ $instance->selectschoolindex => $school->title ];

            $instance->programid = $syncinfo->program->id;
            $program = $ilios->get_program($instance->programid);
            $instance->selectprogramindex = $instance->programid;

            foreach (['shortTitle', 'title'] as $attr) {
                $instance->selectprogramindex .= ':';
                if (property_exists($program, $attr)) {
                    $instance->selectprogramindex .= $program->$attr;
                }
            }
            $programoptions = [ $instance->selectprogramindex => $program->title ];

            $instance->cohortid = $syncinfo->cohort->id;
            $cohort = $ilios->get_cohort($instance->cohortid);
            $instance->selectcohortindex = "$instance->cohortid:$cohort->title";
            $cohortoptions = [ $instance->selectcohortindex =>
                                    $cohort->title
                                    .' ('.count($cohort->learnerGroups).')'
                                    .' ('.count($cohort->users).')'];

            $instance->learnergroupid = '';
            $instance->selectlearnergroupindex = '';
            $instance->subgroupid = '';
            $instance->selectsubgroupindex = '';

            if ($synctype == 'learnerGroup') {
                $instance->learnergroupid = $syncid;
                $group = $ilios->get_learner_group($instance->learnergroupid);

                if ($group) {
                    $instance->selectlearnergroupindex = "$instance->learnergroupid:$group->title";
                    $grouptitle = $group->title.
                        ' ('. count($group->children) .')';
                    $grouptitle .= ' ('. count($group->users) .')';
                    $learnergroupoptions = [$instance->selectlearnergroupindex => $grouptitle];

                    if (!empty($group->parent)) {
                        $processparents = function ($child) use (
                            &$processparents,
                            &$learnergroupoptions,
                            &$grouptitle,
                            &$instance,
                            $ilios
                        ) {
                            $parentgroup = $ilios->get_learner_group($child->parent);
                            $instance->learnergroupid = $parentgroup->id;
                            $instance->selectlearnergroupindex = "$instance->learnergroupid:$parentgroup->title";
                            $learnergroupoptions = [
                                "$instance->learnergroupid:$parentgroup->title" => $parentgroup->title,
                            ];
                            if (!empty($parentgroup->parent)) {
                                $grouptitle = $parentgroup->title . ' / '. $grouptitle;
                                $processparents($parentgroup);
                            }
                        };
                        $processparents($group);
                        $instance->subgroupid = $group->id;
                        $instance->selectsubgroupindex = "$group->id:$group->title";
                        $subgroupoptions = [$instance->selectsubgroupindex => $grouptitle];
                    }
                } else {
                    $hasorphanedlearnergroup = true;
                }
            }
        }

        if ($instance->id) {
            $mform->addElement('select', 'selectusertype', get_string('selectusertype', 'enrol_ilios'), $usertypes);
            $mform->setConstant('selectusertype', $instance->customint2);
            $mform->hardFreeze('selectusertype', $instance->customint2);
        } else {
            $mform->addElement('select', 'selectusertype', get_string('selectusertype', 'enrol_ilios'), $usertypes);
            $mform->addHelpButton('selectusertype', 'selectusertype', 'enrol_ilios');
        }

        if ($instance->id) {
            $mform->addElement('select', 'selectschool', get_string('school', 'enrol_ilios'), $schooloptions);
            $mform->setConstant('selectschool', $instance->selectschoolindex);
            $mform->hardFreeze('selectschool');
        } else {
            $mform->addElement('select', 'selectschool', get_string('school', 'enrol_ilios'), $schooloptions);
            $mform->addRule('selectschool', get_string('required'), 'required', null, 'client');
            $mform->addHelpButton('selectschool', 'school', 'enrol_ilios');
            $mform->registerNoSubmitButton('updateschooloptions');
            $mform->addElement('submit', 'updateschooloptions', get_string('schooloptionsupdate', 'enrol_ilios'));
        }

        if ($instance->id) {
            $mform->addElement('select', 'selectprogram', get_string('program', 'enrol_ilios'), $programoptions);
            $mform->setConstant('selectprogram', $instance->selectprogramindex);
            $mform->hardFreeze('selectprogram');

        } else {
            $mform->addElement('select', 'selectprogram', get_string('program', 'enrol_ilios'), $programoptions);
            $mform->addRule('selectprogram', get_string('required'), 'required', null, 'client');
            $mform->addHelpButton('selectprogram', 'program', 'enrol_ilios');
            $mform->disabledIf('selectprogram', 'selectschool', 'eq', '');
            $mform->registerNoSubmitButton('updateprogramoptions');
            $mform->addElement('submit', 'updateprogramoptions', get_string('programoptionsupdate', 'enrol_ilios'));
        }

        if ($instance->id) {
            $mform->addElement('select', 'selectcohort', get_string('cohort', 'enrol_ilios'), $cohortoptions);
            $mform->setConstant('selectcohort', $instance->selectcohortindex);
            $mform->hardFreeze('selectcohort');

        } else {
            $mform->addElement('select', 'selectcohort', get_string('cohort', 'enrol_ilios'), $cohortoptions);
            $mform->addRule('selectcohort', get_string('required'), 'required', null, 'client');
            $mform->addHelpButton('selectcohort', 'cohort', 'enrol_ilios');
            $mform->disabledIf('selectcohort', 'selectprogram', 'eq', '');
            $mform->registerNoSubmitButton('updatecohortoptions');
            $mform->addElement('submit', 'updatecohortoptions', get_string('cohortoptionsupdate', 'enrol_ilios'));
        }

        if ($instance->id) {
            $mform->addElement('select', 'selectlearnergroup', get_string('learnergroup', 'enrol_ilios'), $learnergroupoptions);
            $mform->setConstant('selectlearnergroup', $instance->selectlearnergroupindex);
            $mform->hardFreeze('selectlearnergroup');

        } else {
            $mform->addElement('select', 'selectlearnergroup', get_string('learnergroup', 'enrol_ilios'), $learnergroupoptions);
            $mform->addHelpButton('selectlearnergroup', 'learnergroup', 'enrol_ilios');
            $mform->disabledIf('selectlearnergroup', 'selectcohort', 'eq', '');
            $mform->registerNoSubmitButton('updatelearnergroupoptions');
            $mform->addElement('submit', 'updatelearnergroupoptions', get_string('learnergroupoptionsupdate', 'enrol_ilios'));
        }

        if ($hasorphanedlearnergroup) {
            $mform->addElement(
                'static',
                'orphaned_learnergroup',
                get_string('orphanedlearnergroup', 'enrol_ilios', $instance->learnergroupid)
            );
        }

        if ($instance->id) {
            $mform->addElement('select', 'selectsubgroup', get_string('subgroup', 'enrol_ilios'), $subgroupoptions);
            $mform->setConstant('selectsubgroup', $instance->selectsubgroupindex);
            $mform->hardFreeze('selectsubgroup');

        } else {
            $mform->addElement('select', 'selectsubgroup', get_string('subgroup', 'enrol_ilios'), $subgroupoptions);
            $mform->addHelpButton('selectsubgroup', 'subgroup', 'enrol_ilios');
            $mform->disabledIf('selectsubgroup', 'selectlearnergroup', 'eq', '');
            $mform->registerNoSubmitButton('updatesubgroupoptions');
            $mform->addElement('submit', 'updatesubgroupoptions', 'Update subgroup option');
        }

        // Role assignment.
        $mform->addElement('header', 'roleassignments', get_string('roleassignments', 'role'));

        $roles = get_assignable_roles($coursecontext);
        $roles[0] = get_string('none');
        $roles = array_reverse($roles, true); // Descending default sortorder.
        if ($instance->id) {
            if (!isset($roles[$instance->roleid])) {
                if ($role = $DB->get_record('role', ['id' => $instance->roleid])) {
                    $roles = role_fix_names($roles, $coursecontext, ROLENAME_ALIAS, true);
                    $roles[$instance->roleid] = role_get_name($role, $coursecontext);
                } else {
                    $roles[$instance->roleid] = get_string('error');
                }
            }
        }

        $mform->addElement('select', 'roleid', get_string('assignrole', 'enrol_ilios'), $roles);
        $mform->setDefault('roleid', $enrol->get_config('roleid'));

        $groups = [0 => get_string('none')];
        foreach (groups_get_all_groups($course->id) as $group) {
            $groups[$group->id] = format_string($group->name, true, ['context' => $coursecontext]);
        }

        $mform->addElement('select', 'customint6', get_string('addgroup', 'enrol_ilios'), $groups);

        $mform->addElement('hidden', 'courseid', null);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'id', null);
        $mform->setType('id', PARAM_INT);

        if ($instance->id) {
            $this->add_action_buttons(true);
        } else {
            $this->add_action_buttons(true, get_string('addinstance', 'enrol'));
        }

        $this->set_data($instance);
        $progel =& $mform->getElement('selectschool');
    }

    /**
     * Set up the form depending on current values.
     * This method is called after definition(), data submission and set_data().
     * All form setup that is dependent on form values should go in here.
     *
     * @return void
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function definition_after_data(): void {
        $mform = $this->_form;

        $progel = $mform->getElement('selectschool');
        if ($progel->isFrozen()) {
            return;
        }

        list($instance, $enrol, $course, $ilios) = $this->_customdata;

        $selectvalues = $mform->getElementValue('selectschool');
        if (is_array($selectvalues)) {
            if (strstr($selectvalues[0], ':')) {
                list($schoolid, $schooltitle) = explode(':', $selectvalues[0], 2);
            } else {
                list($schoolid, $schooltitle) = [$selectvalues[0], '', ''];
            }
        } else {
            $schoolid = '';
            $schooltitle = '';
        }

        $selectvalues = $mform->getElementValue('selectprogram');
        if (is_array($selectvalues)) {
            if (strstr($selectvalues[0], ':')) {
                list($programid, $programshorttitle, $programtitle) = explode(':', $selectvalues[0], 3);
            } else {
                list($programid, $programshorttitle, $programtitle)
                    = [ $selectvalues[0], '', ''];
            }
        } else {
                list($programid, $programshorttitle, $programtitle) = [ '', '', ''];
        }

        $selectvalues = $mform->getElementValue('selectcohort');
        if (is_array($selectvalues)) {
            if (strstr($selectvalues[0], ':')) {
                list($cohortid, $cohorttitle) = explode(':', $selectvalues[0], 2);
            } else {
                list($cohortid, $cohorttitle) = [$selectvalues[0], '', ''];
            }
        } else {
            $cohortid = '';
            $cohorttitle = '';
        }

        $selectvalues = $mform->getElementValue('selectlearnergroup');
        if (is_array($selectvalues)) {
            if (strstr($selectvalues[0], ':')) {
                list($learnergroupid, $learnergrouptitle) = explode(':', $selectvalues[0], 2);
            } else {
                list($learnergroupid, $learnergrouptitle) = [$selectvalues[0], '', ''];
            }
        } else {
            $learnergroupid = '';
            $learnergrouptitle = '';
        }

        $schools = $ilios->get_schools(sortby: ['title' => 'ASC']);

        $progel =& $mform->getElement('selectschool');
        if ($schools === null) { // No connection to the server.
            // Todo: get from cache if possible.
            $schooloptions = ['' => get_string('error')];
            $progel->load($schooloptions);
        } else {
            foreach ($schools as $school) {
                $progel->addOption( $school->title, "$school->id:$school->title" );
            }
        }

        if (!empty($schoolid)) {
            $sid = $schoolid;
            $progel =& $mform->getElement('selectprogram');
            $programoptions = [];
            $programs = $ilios->get_programs(['school' => $sid], ['title' => "ASC"]);

            if (!empty($programs)) {
                foreach ($programs as $program) {
                    $key = $program->id;
                    foreach (['shortTitle', 'title'] as $attr) {
                        $key .= ':';
                        if (property_exists($program, $attr)) {
                            $key .= $program->$attr;
                        }
                    }
                    $programoptions[$key] = $program->title;
                }
                $progel->load($programoptions);
            }
        }

        if (!empty($programid)) {
            $pid = $programid;
            $progel =& $mform->getElement('selectcohort');
            $cohortoptions = [];

            $programyears = $ilios->get_program_years(["program" => $pid], ["startYear" => "ASC"]);
            $programyeararray = [];
            foreach ($programyears as $progyear) {
                $programyeararray[] = $progyear->id;
            }

            if (!empty($programyeararray)) {
                $cohorts = $ilios->get_cohorts(["programYear" => $programyeararray], ["title" => "ASC"]);

                foreach ($cohorts as $cohort) {
                    $cohortoptions["$cohort->id:$cohort->title"] = $cohort->title
                                                                 .' ('.count($cohort->learnerGroups).')'
                                                                 .' ('.count($cohort->users).')';
                }
                $progel->load($cohortoptions);
            }
        }

        if (!empty($cohortid)) {
            $cid = $cohortid;
            $progel =& $mform->getElement('selectlearnergroup');
            $learnergroupoptions = [];

            $learnergroups = $ilios->get_learner_groups(['cohort' => $cid, 'parent' => 'null'], ['title' => "ASC"]);
            if (!empty($learnergroups)) {
                foreach ($learnergroups as $group) {
                    $learnergroupoptions["$group->id:$group->title"] = $group->title.
                                                     ' ('. count($group->children) .')'.
                                                     ' ('. count($group->users) .')';
                }
                $progel->load($learnergroupoptions);
            }
        }

        if (!empty($learnergroupid)) {
            $gid = $learnergroupid;
            $progel =& $mform->getElement('selectsubgroup');
            $subgroupoptions = [];

            $subgroups = $ilios->get_learner_groups(["parent" => $gid], ["title" => "ASC"]);
            foreach ($subgroups as $subgroup) {
                $subgroupoptions["$subgroup->id:$subgroup->title"] = $subgroup->title.
                                                                   ' ('. count($subgroup->children) .')'.
                                                                   ' ('. count($subgroup->users) .')';
                if (!empty($subgroup->children)) {
                    $processchildren = function ($parent) use (&$processchildren, &$subgroupoptions, $ilios) {
                        $subgrps = $ilios->get_learner_groups([ 'parent' => $parent->id], [ 'title' => "ASC"]);
                        foreach ($subgrps as $subgrp) {
                            $subgroupoptions["$subgrp->id:$parent->title / $subgrp->title"] = $parent->title.' / '.$subgrp->title.
                                                          ' ('. count($subgrp->children) .')'.
                                                          ' ('. count($subgrp->users) .')';
                            if (!empty($subgrp->children)) {
                                $processchildren($subgrp);
                            }
                        }
                    };
                    $processchildren($subgroup);
                }
            }
            $progel->load($subgroupoptions);
        }
    }

    /**
     * Perform some extra validation.
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @return array of "element_name"=>"error_description" if there are errors, or an empty array if everything is OK.
     * @throws coding_exception
     * @throws dml_exception
     */
    public function validation($data, $files) {
        global $DB;

        $errors = parent::validation($data, $files);

        // Make sure a learner group is selected if customint2 = 1 (instructor).
        if (!empty($data['selectusertype'])) {
            if (empty($data['selectlearnergroup'])) {
                $errors['selectlearnergroup'] = get_string('requiredforinstructor', 'enrol_ilios');
            }
        }

        // Check for existing role.
        $selectgrouptype = 'cohort';
        list($selectgroupid, $selecttitle) = explode(':', $data['selectcohort'], 2);
        if (!empty($data['selectlearnergroup'])) {
            $selectgrouptype = 'learnerGroup';
            list($selectgroupid, $selecttitle) = explode(':', $data['selectlearnergroup'], 2);
            if (!empty($data['selectsubgroup'])) {
                list($selectgroupid, $selecttitle) = explode(':', $data['selectsubgroup'], 2);
            }
        }

        $params = [
            'roleid' => $data['roleid'],
            'customchar1' => $selectgrouptype,
            'customint1' => $selectgroupid,
            'customint2' => $data['selectusertype'],
            'courseid' => $data['courseid'],
            'id' => $data['id'],
        ];
        // Customint2 could be NULL or 0 on the database.
        if (empty($data['selectusertype'])
            && $DB->record_exists_select(
                'enrol',
                "roleid = :roleid AND customchar1 = :customchar1 AND customint1 = :customint1 "
                . " AND customint2 IS NULL AND courseid = :courseid AND enrol = 'ilios' AND id <> :id",
                $params
            )
        ) {
            $errors['roleid'] = get_string('instanceexists', 'enrol_ilios');
        } else {
            if ($DB->record_exists_select(
                'enrol',
                "roleid = :roleid AND customchar1 = :customchar1 AND customint1 = :customint1  " .
                " AND customint2 = :customint2 AND courseid = :courseid AND enrol = 'ilios' AND id <> :id",
                $params
            )) {
                $errors['roleid'] = get_string('instanceexists', 'enrol_ilios');
            }
        }

        return $errors;
    }
}

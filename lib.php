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
 * Ilios enrolment plugin.
 *
 * @package    enrol_ilios
 * @author     Carson Tam <carson.tam@ucsf.edu>
 * @copyright  The Regents of the University of California
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\di;
use enrol_ilios\ilios;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/filelib.php');

/**
 * Ilios enrolment plugin implementation.
 * @author    Carson Tam
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_ilios_plugin extends enrol_plugin {
    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     * @throws coding_exception
     */
    public function can_delete_instance($instance): bool {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/ilios:config', $context);
    }

    /**
     * Is it possible to hide/show enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     * @throws coding_exception
     */
    public function can_hide_show_instance($instance): bool {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/ilios:config', $context);
    }

    /**
     * Returns localised name of enrol instance.
     *
     * @param stdClass $instance An enrol instance (or NULL for *this* instance).
     * @return string The name of this enrol instance.
     * @throws Exception
     */
    public function get_instance_name($instance) {
        global $DB;

        if (empty($instance)) {
            $enrol = $this->get_name();
            return get_string('pluginshortname', 'enrol_'.$enrol);

        } else if (empty($instance->name)) {
            $enrol = $this->get_name();
            $groupname = get_string('pluginshortname', 'enrol_'.$enrol);
            $syncinfo = json_decode($instance->customtext1);

            if (!empty($syncinfo)) {
                $schooltitle = $syncinfo->school->title;
                $programtitle = $syncinfo->program->shorttitle;
                $cohorttitle = $syncinfo->cohort->title;
                $groupname .= ": ". $schooltitle ."/".$programtitle."/".$cohorttitle;
                if (isset($syncinfo->learnerGroup)) {
                    $grouptitle = $syncinfo->learnerGroup->title;
                    $groupname .= '/'.$grouptitle;
                }
                if (isset($syncinfo->subGroup)) {
                    $grouptitle = $syncinfo->subGroup->title;
                    $groupname .= '/'.$grouptitle;
                }
            }

            if ($role = $DB->get_record('role', ['id' => $instance->roleid])) {
                $role = role_get_name($role, context_course::instance($instance->courseid, IGNORE_MISSING));
                if (empty($instance->customint2)) {
                    $groupname .= ' (Learner => '.$role;
                } else {
                    $groupname .= ' (Instructor => '.$role;
                }

                $groupid = $instance->customint6;
                $group = groups_get_group( $groupid, 'name' );
                if (!empty($group) && isset($group->name)) {
                    $groupname .= ', ' . $group->name;
                }
                $groupname .= ')';
            } else {
                $groupid = $instance->customint6;
                $group = groups_get_group( $groupid, 'name' );
                if (!empty($group) && isset($group->name)) {
                    $groupname .= ' (' . $group->name. ')';
                }
            }

            return $groupname;

        } else {
            return format_string($instance->name, true, ['context' => context_course::instance($instance->courseid)]);
        }
    }

    /**
     * Returns link to page which may be used to add new instance of enrolment plugin in course.
     *
     * @param int $courseid The course ID.
     * @return moodle_url|null The page URL to the new instance form, or NULL if not allowed.
     * @throws Exception
     */
    public function get_newinstance_link($courseid) {
        if (!$this->can_add_new_instances($courseid)) {
            return null;
        }
        // Multiple instances supported - multiple parent courses linked.
        return new moodle_url('/enrol/ilios/edit.php', ['courseid' => $courseid]);
    }

    /**
     * Given a courseid this function returns true if the user is able to enrol or configure cohorts.
     * AND there are cohorts that the user can view.
     *
     * @param int $courseid The course ID.
     * @return bool TRUE if the current user can add new enrolment instances in the given course, FALSE otherwise.
     * @throws Exception
     */
    protected function can_add_new_instances($courseid): bool {
        global $DB;

        $coursecontext = context_course::instance($courseid);
        if (!has_capability('moodle/course:enrolconfig', $coursecontext) || !has_capability('enrol/ilios:config', $coursecontext)) {
            return false;
        }
        return true;
    }

    /**
     * Returns edit icons for the page with list of instances.
     *
     * @param stdClass $instance The enrol instance.
     * @return array The list of edit icons und URLs.
     * @throws Exception
     */
    public function get_action_icons(stdClass $instance): array {
        global $OUTPUT;

        if ($instance->enrol !== 'ilios') {
            throw new coding_exception('invalid enrol instance!');
        }
        $context = context_course::instance($instance->courseid);

        $icons = [];

        if (has_capability('enrol/ilios:config', $context)) {
            $editlink = new moodle_url("/enrol/ilios/edit.php", ['courseid' => $instance->courseid, 'id' => $instance->id]);
            $icons[] = $OUTPUT->action_icon($editlink, new pix_icon('t/edit', get_string('edit'), 'core',
                    ['class' => 'iconsmall']));
        }

        return $icons;
    }

    /**
     * Execute all sync jobs, or all sync jobs for a given course.
     *
     * @param progress_trace $trace The progress tracer for this task run.
     * @param int|NULL $courseid The course ID, or NULL if all sync jobs should be executed.
     * @return int exit code, 0 means ok, 2 means plugin disabled.
     * @throws Exception
     */
    public function sync($trace, $courseid = null): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        $ilios = di::get(ilios::class);

        if (!enrol_is_enabled('ilios')) {
            // Purge all roles if ilios sync disabled, those can be recreated later here by cron or CLI.
            $trace->output('Ilios enrolment sync plugin is disabled, unassigning all plugin roles and stopping.');
            role_unassign_all(['component' => 'enrol_ilios']);
            return 2;
        }

        // Unfortunately this may take a long time, this script can be interrupted without problems.
        @set_time_limit(0);
        raise_memory_limit(MEMORY_HUGE);

        $trace->output('Starting user enrolment synchronisation...');

        $allroles = get_all_roles();
        $iliosusers = []; // Cache user data.

        $unenrolaction = $this->get_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL);

        // Get all enabled enrollment Ilios instances.
        $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
        $sql = "SELECT * FROM {enrol} e WHERE e.enrol = 'ilios' AND e.status = :status $onecourse";

        $params = [];
        $params['courseid'] = $courseid;
        $params['status'] = ENROL_INSTANCE_ENABLED;

        $instances = $DB->get_recordset_sql($sql, $params);

        foreach ($instances as $instance) {
            $synctype = $instance->customchar1;
            $syncid = $instance->customint1;

            if ('learnerGroup' === $synctype) {
                $entity = $ilios->get_learner_group($syncid);
            } else {
                $entity = $ilios->get_cohort($syncid);
            }

            if (empty($entity)) {
                $trace->output("skipping: Unable to fetch data for Ilios $synctype ID $syncid.", 1);
                continue;
            }

            $enrolleduserids = []; // Keep a list of enrolled user's Moodle userid (both learners and instructors).
            $suspendenrolments = []; // List of user enrollments to suspend.

            $users = []; // Ilios users in that group.

            if (!empty($instance->customint2)) {
                $instructors = [];
                if ('learnerGroup' === $synctype && !empty($instance->customint2)) {
                    $instructors = $ilios->get_instructor_ids_from_learner_group($entity->id);
                }
                if (!empty($instructors)) {
                    $trace->output(
                        "Enrolling instructors to Course ID "
                        . $instance->courseid
                        . " with Role ID "
                        . $instance->roleid
                        . " through Ilios Sync ID "
                        . $instance->id
                        . "."
                    );
                    $users = $ilios->get_users(['id' => $instructors]);
                }
            } else if (!empty($entity->users)) {
                $trace->output(
                    "Enrolling students to Course ID "
                    . $instance->courseid
                    . " with Role ID "
                    . $instance->roleid
                    . " through Ilios Sync ID " .
                    $instance->id
                    . "."
                );
                $users = $ilios->get_users(['id' => $entity->users]);
            }

            $trace->output(count($users) . " Ilios users found.");

            // Filter out records that do not have a campus ID.
            $users = array_values(array_filter($users, function ($user) use ($trace) {
                $campusid = $user->campusId ?? '';
                if ('' === trim($campusid)) {
                    $trace->output("skipping: Ilios user " . $user->id . " does not have a 'campusId' field.", 1);
                    return false;
                }
                return true;
            }));

            // Deduplicate Ilios user records.
            $users = $this->deduplicate_ilios_users($users, $trace);

            foreach ($users as $user) {
                // Fetch user info if not cached in $iliosusers.
                if (!isset($iliosusers[$user->id])) {
                    $iliosusers[$user->id] = null;
                    if (!empty($user->campusId)) {
                        $urec = $DB->get_record('user', ["idnumber" => $user->campusId]);
                        if (!empty($urec)) {
                            $iliosusers[$user->id] = ['id' => $urec->id, 'syncfield' => $urec->idnumber];
                        }
                    }
                }

                if ($iliosusers[$user->id] === null) {
                    $trace->output(
                        "skipping: Cannot find campusId "
                        . $user->campusId
                        . " that matches Moodle user field 'idnumber'."
                        , 1);
                } else {
                    $enrolleduserids[] = $userid = $iliosusers[$user->id]['id'];

                    $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);

                    // Don't enroll disabled Ilios users that are currently not enrolled.
                    if (empty($ue) && !$user->enabled) {
                        continue;
                    }

                    // Don't re-enroll suspended enrollments for disabled Ilios users.
                    if (!empty($ue) && ENROL_USER_SUSPENDED === (int)$ue->status && !$user->enabled) {
                        continue;
                    }

                    // Flag actively enrolled users that are disabled in Ilios
                    // for enrollment suspension further downstream.
                    if (!empty($ue) && ENROL_USER_ACTIVE === (int)$ue->status && !$user->enabled) {
                        $suspendenrolments[] = $ue;
                        continue;
                    }

                    // Continue if already enrolled with active status.
                    if (!empty($ue) && ENROL_USER_ACTIVE === (int)$ue->status) {
                        continue;
                    }

                    // Enroll user.
                    $this->enrol_user(
                        $instance,
                        $userid,
                        $instance->roleid,
                        0,
                        0,
                        ENROL_USER_ACTIVE
                    );
                    if (!empty($ue) && ENROL_USER_ACTIVE !== (int)$ue->status) {
                        $trace->output(
                            "changing enrollment status to '"
                            . ENROL_USER_ACTIVE
                            . "' from '{$ue->status}': userid $userid ==> courseid "
                            . $instance->courseid
                            , 1
                        );
                    } else {
                        $trace->output(
                            "enrolling with "
                            . ENROL_USER_ACTIVE
                            . " status: userid $userid ==> courseid "
                            . $instance->courseid
                            , 1
                        );
                    }
                }
            }

            // Suspend active enrollments for users that are disabled in Ilios.
            foreach ($suspendenrolments as $ue) {
                $trace->output(
                    "Suspending enrollment for disabled Ilios user: userid "
                    . " {$ue->userid} ==> courseid {$instance->courseid}."
                    , 1
                );
                $this->update_user_enrol($instance, $ue->userid, ENROL_USER_SUSPENDED);
            }

            // Unenrol as necessary.
            $trace->output(
                "Unenrolling users from Course ID "
                . $instance->courseid." with Role ID "
                . $instance->roleid
                . " that no longer associate with Ilios Sync ID "
                . $instance->id
                . "."
            );

            $sql = "SELECT ue.*
                  FROM {user_enrolments} ue
                  WHERE ue.enrolid = $instance->id";

            if (!empty($enrolleduserids)) {
                $sql .= " AND ue.userid NOT IN ( ".implode(",", $enrolleduserids)." )";
            }

            $rs = $DB->get_recordset_sql($sql);
            foreach ($rs as $ue) {
                if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
                    // Remove enrolment together with group membership, grades, preferences, etc.
                    $this->unenrol_user($instance, $ue->userid);
                    $trace->output(
                        "unenrolling: $ue->userid ==> "
                        . $instance->courseid
                        . " via Ilios $synctype $syncid"
                        , 1
                    );
                } else { // Would be ENROL_EXT_REMOVED_SUSPENDNOROLES.
                    // Just disable and ignore any changes.
                    if ($ue->status != ENROL_USER_SUSPENDED) {
                        $this->update_user_enrol($instance, $ue->userid, ENROL_USER_SUSPENDED);
                        $context = context_course::instance($instance->courseid);
                        role_unassign_all([
                            'userid' => $ue->userid,
                            'contextid' => $context->id,
                            'component' => 'enrol_ilios',
                            'itemid' => $instance->id,
                        ]);
                        $trace->output(
                            "suspending and unassigning all roles: userid "
                            . $ue->userid
                            . " ==> courseid "
                            . $instance->courseid
                            , 1
                        );
                    }
                }
            }
            $rs->close();
        }

        $instances->close();
        unset($iliosusers);

        // Now assign all necessary roles to enrolled users - skip suspended instances and users.
        $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
        $sql = "SELECT e.roleid, ue.userid, c.id AS contextid, e.id AS itemid, e.courseid
              FROM {user_enrolments} ue
              JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'ilios' AND e.status = :statusenabled $onecourse)
              JOIN {role} r ON (r.id = e.roleid)
              JOIN {context} c ON (c.instanceid = e.courseid AND c.contextlevel = :coursecontext)
              JOIN {user} u ON (u.id = ue.userid AND u.deleted = 0)
         LEFT JOIN {role_assignments} ra ON (
              ra.contextid = c.id
              AND ra.userid = ue.userid
              AND ra.itemid = e.id
              AND ra.component = 'enrol_ilios'
              AND e.roleid = ra.roleid
         )
         WHERE ue.status = :useractive AND ra.id IS NULL";
        $params = [];
        $params['statusenabled'] = ENROL_INSTANCE_ENABLED;
        $params['useractive'] = ENROL_USER_ACTIVE;
        $params['coursecontext'] = CONTEXT_COURSE;
        $params['courseid'] = $courseid;

        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $ra) {
            role_assign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_ilios', $ra->itemid);
            $trace->output("assigning role: $ra->userid ==> $ra->courseid as ".$allroles[$ra->roleid]->shortname, 1);
        }
        $rs->close();

        // Remove unwanted roles - sync role can not be changed, we only remove role when unenrolled.
        $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
        $sql = "SELECT ra.roleid, ra.userid, ra.contextid, ra.itemid, e.courseid
              FROM {role_assignments} ra
              JOIN {context} c ON (c.id = ra.contextid AND c.contextlevel = :coursecontext)
              JOIN {enrol} e ON (e.id = ra.itemid AND e.enrol = 'ilios' $onecourse)
         LEFT JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = ra.userid AND ue.status = :useractive)
             WHERE ra.component = 'enrol_ilios' AND (ue.id IS NULL OR e.status <> :statusenabled)";
        $params = [];
        $params['statusenabled'] = ENROL_INSTANCE_ENABLED;
        $params['useractive'] = ENROL_USER_ACTIVE;
        $params['coursecontext'] = CONTEXT_COURSE;
        $params['courseid'] = $courseid;

        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $ra) {
            role_unassign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_ilios', $ra->itemid);
            $trace->output("unassigning role: $ra->userid ==> $ra->courseid as ".$allroles[$ra->roleid]->shortname, 1);
        }
        $rs->close();

        // Finally sync groups.
        $onecourse = $courseid ? "AND e.courseid = :courseid" : "";

        // Remove invalid.
        $sql = "SELECT gm.*, e.courseid, g.name AS groupname
              FROM {groups_members} gm
              JOIN {groups} g ON (g.id = gm.groupid)
              JOIN {enrol} e ON (e.enrol = 'ilios' AND e.courseid = g.courseid $onecourse)
              JOIN {user_enrolments} ue ON (ue.userid = gm.userid AND ue.enrolid = e.id)
             WHERE gm.component='enrol_ilios' AND gm.itemid = e.id AND g.id <> e.customint6";
        $params = [];
        $params['courseid'] = $courseid;

        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $gm) {
            groups_remove_member($gm->groupid, $gm->userid);
            $trace->output("removing user from group: $gm->userid ==> $gm->courseid - $gm->groupname", 1);
        }
        $rs->close();

        // Add missing.
        $sql = "SELECT ue.*, g.id AS groupid, e.courseid, g.name AS groupname
              FROM {user_enrolments} ue
              JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'ilios' $onecourse)
              JOIN {groups} g ON (g.courseid = e.courseid AND g.id = e.customint6)
              JOIN {user} u ON (u.id = ue.userid AND u.deleted = 0)
         LEFT JOIN {groups_members} gm ON (gm.groupid = g.id AND gm.userid = ue.userid)
             WHERE gm.id IS NULL";
        $params = [];
        $params['courseid'] = $courseid;

        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $ue) {
            groups_add_member($ue->groupid, $ue->userid, 'enrol_ilios', $ue->enrolid);
            $trace->output("adding user to group: $ue->userid ==> $ue->courseid - $ue->groupname", 1);
        }
        $rs->close();

        $trace->output('...user enrolment synchronisation finished.');

        return 0;
    }

    /**
     * Update instance status.
     *
     * @param stdClass $instance
     * @param int $newstatus ENROL_INSTANCE_ENABLED, ENROL_INSTANCE_DISABLED
     * @return void
     * @throws Exception
     */
    public function update_status($instance, $newstatus) {
        parent::update_status($instance, $newstatus);

        $trace = new null_progress_trace();
        $this->sync($trace, $instance->courseid);
        $trace->finished();
    }

    /**
     * Does this plugin allow manual unenrolment of a specific user?
     * Yes, but only if user suspended.
     *
     * @param stdClass $instance The course enrol instance.
     * @param stdClass $ue A user enrolment record.
     *
     * @return bool TRUE means that the current user may unenrol this user, FALSE otherwise.
     */
    public function allow_unenrol_user(stdClass $instance, stdClass $ue): bool {
        if ($ue->status == ENROL_USER_SUSPENDED) {
            return true;
        }

        return false;
    }

    /**
     * Gets an array of the user enrolment actions.
     *
     * @param course_enrolment_manager $manager The course enrolment manager.
     * @param stdClass $ue A user enrolment object.
     * @return array An array of user_enrolment_actions.
     * @throws Exception
     */
    public function get_user_enrolment_actions(course_enrolment_manager $manager, $ue): array {
        $actions = [];
        $context = $manager->get_context();
        $instance = $ue->enrolmentinstance;
        $params = $manager->get_moodlepage()->url->params();
        $params['ue'] = $ue->id;
        if ($this->allow_unenrol_user($instance, $ue) && has_capability('enrol/ilios:unenrol', $context)) {
            $url = new moodle_url('/enrol/unenroluser.php', $params);
            $actions[] = new user_enrolment_action(
                new pix_icon('t/delete', ''),
                get_string('unenrol', 'enrol'),
                $url,
                ['class' => 'unenrollink', 'rel' => $ue->id]
            );
        }
        return $actions;
    }

    /**
     * Restore instance and map settings.
     *
     * @param restore_enrolments_structure_step $step The restore enrolments structure step.
     * @param stdClass $data The data object.
     * @param stdClass $course The course object.
     * @param int $oldid The old enrolment instance ID.
     * @throws Exception
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid): void {
        global $DB;

        if (!$step->get_task()->is_samesite()) {
            // No ilios restore from other sites.
            $step->set_mapping('enrol', $oldid, 0);
            return;
        }

        if (!empty($data->customint6)) {
            $data->customint6 = $step->get_mappingid('group', $data->customint6);
        }

        if ($data->roleid) {
            $instance = $DB->get_record(
                'enrol',
                [
                    'roleid' => $data->roleid,
                    'customint1' => $data->customint1,
                    'customchar1' => $data->customchar1,
                    'courseid' => $course->id,
                    'enrol' => $this->get_name(),
                ],
            );
            if ($instance) {
                $instanceid = $instance->id;
            } else {
                $instanceid = $this->add_instance($course, (array)$data);
            }
            $step->set_mapping('enrol', $oldid, $instanceid);

            $trace = new null_progress_trace();
            $this->sync($trace, $course->id);
            $trace->finished();

        } else if ($this->get_config('unenrolaction') == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
            $instance = $DB->get_record(
                'enrol',
                [
                    'roleid' => $data->roleid,
                    'customint1' => $data->customint1,
                    'customerchar1' => $data->customchar1,
                    'courseid' => $course->id,
                    'enrol' => $this->get_name(),
                    ],
            );
            if ($instance) {
                $instanceid = $instance->id;
            } else {
                $data->status = ENROL_INSTANCE_DISABLED;
                $instanceid = $this->add_instance($course, (array)$data);
            }
            $step->set_mapping('enrol', $oldid, $instanceid);

            $trace = new null_progress_trace();
            $this->sync($trace, $course->id);
            $trace->finished();

        } else {
            $step->set_mapping('enrol', $oldid, 0);
        }
    }

    /**
     * Restore user enrolment.
     *
     * @param restore_enrolments_structure_step $step The restore enrolment structure step.
     * @param stdClass $data The data object.
     * @param stdClass $instance The enrolment instance.
     * @param int $userid The user ID.
     * @param int $oldinstancestatus The old enrolment instance status.
     * @throws Exception
     */
    public function restore_user_enrolment(
        restore_enrolments_structure_step $step,
        $data,
        $instance,
        $userid,
        $oldinstancestatus
    ): void {
        global $DB;

        if ($this->get_config('unenrolaction') != ENROL_EXT_REMOVED_SUSPENDNOROLES) {
            // Enrolments were already synchronised in restore_instance(), we do not want any suspended leftovers.
            return;
        }

        // ENROL_EXT_REMOVED_SUSPENDNOROLES means all previous enrolments are restored
        // but without roles and suspended.

        if (!$DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid])) {
            $this->enrol_user($instance, $userid, null, $data->timestart, $data->timeend, ENROL_USER_SUSPENDED);
        }
    }

    /**
     * Ensures uniqueness of user accounts by campus ID.
     * If there are duplicate user records, they are resolved in the following manner.
     * 1. If all duplicates are active user records, then use the most recently created one.
     * 2. If all duplicates are inactive user records, then use the most recently created one.
     * 3. If there is a mix between active and inactive user records, then use the most recently created active one.
     * @param array $users An array of Ilios user records.
     * @return array The given array of Ilios user records, with duplicates removed.
     */
    protected function deduplicate_ilios_users(array $users): array {
        // Reverse-sort users by their user ID. In other words, from most-recently created to oldest.
        array_multisort(array_column($users, 'id'), SORT_DESC, SORT_NUMERIC, $users);
        $cache = [];
        foreach ($users as $user) {
            $key = $user->campusId;
            // If this is the first time we encounter this user, cache them and move on.
            if (!array_key_exists($key, $cache)) {
                $cache[$key] = $user;
                continue;
            }

            // And now we're dealing with duplicates.
            // If the current user record is active, and the cached duplicate is inactive,
            // then replace the cached, inactive user record with the current, active one.
            if (!$cache[$key]->enabled && $user->enabled) {
                $cache[$key] = $user;
            }
        }

        return array_values($cache);
    }
}

/**
 * Prevents the removal of enrol roles.
 * Implements the <code>allow_group_member_remove</code> callback from the Group API.
 *
 * @param int $itemid The item ID.
 * @param int $groupid The group ID.
 * @param int $userid The user ID.
 * @return bool Always FALSE.
 */
function enrol_ilios_allow_group_member_remove($itemid, $groupid, $userid): bool {
    return false;
}

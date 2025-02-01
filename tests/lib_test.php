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
 * Ilios enrolment tests.
 *
 * @category   test
 * @package    enrol_ilios
 * @copyright  The Regents of the University of California
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_ilios;

use context_course;
use core\di;
use core\http_client;
use enrol_ilios\tests\helper;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use progress_trace_buffer;
use Psr\Http\Message\RequestInterface;
use text_progress_trace;

/**
 * Ilios enrolment tests.
 *
 * @category   test
 * @package    enrol_ilios
 * @copyright  The Regents of the University of California
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \enrol_ilios_plugin
 */
final class lib_test extends \advanced_testcase {

    /**
     * Tests the enrolment of Ilios cohort members into a Moodle course.
     */
    public function test_sync_from_ilios_cohort_members(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Configure the Ilios API client.
        $accesstoken = helper::create_valid_ilios_api_access_token();
        set_config('apikey', $accesstoken, 'enrol_ilios');
        set_config('host_url', 'http://ilios.demo', 'enrol_ilios');

        // Mock out the responses from the Ilios API.
        $handlerstack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'cohorts' => [
                    [
                        'id' => 1,
                        'users' => ['2', '3', '4', '5', '6'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'users' => [
                    [
                        'id' => 2,
                        'campusId' => 'xx1000002',
                        'enabled' => true,
                    ],
                    [
                        'id' => 3,
                        'campusId' => 'xx1000003',
                        'enabled' => true,
                    ],
                    [
                        'id' => 4,
                        'campusId' => 'xx1000004',
                        'enabled' => false, // Disabled user account - should result in user enrolment suspension.
                    ],
                    [
                        'id' => 5,
                        'campusId' => 'xx1000005', // Not currently enrolled - should result in new user enrolment.
                        'enabled' => true,
                    ],
                    [
                        'id' => 6,
                        'campusId' => 'xx1000006', // Currently with suspended enrolment in Moodle.
                        'enabled' => true,
                    ],
                ],
            ])),
        ]));
        $container = [];
        $history = Middleware::history($container);
        $handlerstack->push($history);
        di::set(http_client::class, new http_client(['handler' => $handlerstack]));

        // Get a handle of the enrolment handler.
        $plugin = enrol_get_plugin('ilios');

        // Create a course and users, enrol some students.
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->assertNotEmpty($studentrole);

        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);

        $user1 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000001']);
        $user2 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000002']);
        $user3 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000003']);
        $user4 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000004']);
        $user5 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000005']);
        $user6 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000006']);

        $this->assertEquals(0, $DB->count_records('enrol', ['enrol' => 'ilios']));
        $this->assertEquals(
            0,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(0, $DB->count_records('user_enrolments'));

        // Instantiate an enrolment instance that targets cohort members in Ilios.
        $ilioscohortid = 1; // Ilios cohort ID.
        $synctype = 'cohort'; // Enrol from cohort.
        $plugin->add_instance($course, [
                'customint1' => $ilioscohortid,
                'customint2' => 0,
                'customchar1' => $synctype,
                'roleid' => $studentrole->id,
            ]
        );
        $this->assertEquals(1, $DB->count_records('enrol', ['enrol' => 'ilios']));
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'ilios'], '*', MUST_EXIST);
        $this->assertEquals($studentrole->id, $instance->roleid);
        $this->assertEquals(1, $instance->customint1);
        $this->assertEquals(0, $instance->customint2);
        $this->assertEquals('cohort', $instance->customchar1);

        // Enable the enrolment method.
        $CFG->enrol_plugins_enabled = 'ilios';

        // Enroll users 1-4 and 6, but not 5.
        $plugin->enrol_user($instance, $user1->id, $studentrole->id);
        $plugin->enrol_user($instance, $user2->id, $studentrole->id);
        $plugin->enrol_user($instance, $user3->id, $studentrole->id);
        $plugin->enrol_user($instance, $user4->id, $studentrole->id);
        $plugin->enrol_user($instance, $user6->id, $studentrole->id);
        // Suspend enrolment of user 6.
        $plugin->update_user_enrol($instance, $user6->id, ENROL_USER_SUSPENDED);

        // Check user enrolments pre-sync.
        $this->assertEquals(
            5,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(5, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));

        // Users 1 - 4 are actively enrolled in the course.
        foreach ([$user1, $user2, $user3, $user4] as $user) {
            $this->assertNotEmpty(
                $DB->get_record(
                    'role_assignments',
                    [
                        'roleid' => $studentrole->id,
                        'component' => 'enrol_ilios',
                        'userid' => $user->id,
                        'contextid' => $context->id,
                    ],
                    strictness: MUST_EXIST
                )
            );
            $this->assertNotEmpty(
                $DB->get_record(
                    'user_enrolments',
                    [
                        'enrolid' => $instance->id,
                        'userid' => $user->id,
                        'status' => ENROL_USER_ACTIVE,
                    ],
                    strictness: MUST_EXIST
                )
            );
        }

        // Verify that user 5 is not enrolled.
        $this->assertEmpty(
            $DB->get_record(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'userid' => $user5->id,
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user5->id,
                ]
            )
        );

        // Verify that user 6 has a suspended user enrolment.
        $this->assertNotEmpty(
            $DB->get_record(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'userid' => $user6->id,
                    'contextid' => $context->id,
                ],
                strictness: MUST_EXIST,
            )
        );
        $this->assertNotEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user6->id,
                    'status' => ENROL_USER_SUSPENDED,
                ],
                strictness: MUST_EXIST,
            )
        );

        // Run enrolment sync.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        // Check the captured request history.
        $this->assertEquals('ilios.demo', $container[0]['request']->getUri()->getHost());
        $this->assertEquals('/api/v3/cohorts/1', $container[0]['request']->getUri()->getPath());

        $this->assertEquals('/api/v3/users', $container[1]['request']->getUri()->getPath());
        $this->assertEquals(
            'filters[id][]=2&filters[id][]=3&filters[id][]=4&filters[id][]=5&filters[id][]=6',
            urldecode($container[1]['request']->getUri()->getQuery())
        );

        // Check the logging output.
        $this->assertStringContainsString(
            "Enrolling students to Course ID {$course->id} with Role ID "
                . "{$studentrole->id} through Ilios Sync ID {$instance->id}.",
                $output
        );
        $this->assertStringContainsString('5 Ilios users found.', $output);
        $this->assertStringContainsString(
            'enrolling with ' . ENROL_USER_ACTIVE . " status: userid {$user5->id} ==> courseid {$course->id}",
            $output
        );
        $this->assertStringContainsString(
            "changing enrollment status to '". ENROL_USER_ACTIVE
            . "' from '" . ENROL_USER_SUSPENDED . "': userid {$user6->id} ==> courseid {$course->id}",
            $output
        );
        $this->assertEquals(1, substr_count($output, 'changing enrollment status'));
        $this->assertEquals(1, substr_count($output, 'enrolling with ' . ENROL_USER_ACTIVE . ' status:'));
        $this->assertStringContainsString(
            "Suspending enrollment for disabled Ilios user: userid  {$user4->id} ==> courseid {$course->id}.",
            $output
        );
        $this->assertEquals(1, substr_count($output, 'Suspending enrollment for disabled Ilios user:'));
        $this->assertStringContainsString(
            "Unenrolling users from Course ID {$course->id} with Role ID {$studentrole->id} " .
            "that no longer associate with Ilios Sync ID {$instance->id}.",
            $output
        );
        $this->assertStringContainsString(
            "unenrolling: {$user1->id} ==> {$course->id} via Ilios {$synctype} {$ilioscohortid}",
            $output
        );
        $this->assertEquals(1, substr_count($output, 'unenrolling:'));
        $this->assertStringContainsString(
            "unassigning role: {$user4->id} ==> {$course->id} as {$studentrole->shortname}",
            $output
        );
        $this->assertEquals(1, substr_count($output, 'unassigning role:'));

        // Check user enrolments post-sync.
        $this->assertEquals(
            4,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(5, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));

        // User 2, 3, and now 5 and 6, are actively enrolled as students in the course.
        foreach ([$user2, $user3, $user5, $user6] as $user) {
            $this->assertNotEmpty(
                $DB->get_record(
                    'role_assignments',
                    [
                        'roleid' => $studentrole->id,
                        'component' => 'enrol_ilios',
                        'userid' => $user->id,
                        'contextid' => $context->id,
                    ],
                    strictness: MUST_EXIST
                )
            );
            $userenrolment = $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user->id,
                    'status' => ENROL_USER_ACTIVE,
                ],
                strictness: MUST_EXIST
            );
        }
        // Verify that user1 has been fully unenrolled and has no role assigment in the course.
        $this->assertEmpty(
            $DB->get_record(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'userid' => $user1->id,
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user1->id,
                ]
            )
        );
        // User 4 is still enrolled in the course, but their enrolment status suspended and their student assignment
        // in the course has been removed.
        $this->assertEmpty(
            $DB->get_record(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'userid' => $user4->id,
                    'contextid' => $context->id,
                ]
            )
        );
        $userenrolment = $DB->get_record(
            'user_enrolments',
            [
                'enrolid' => $instance->id,
                'userid' => $user4->id,
            ],
            strictness: MUST_EXIST
        );
        $this->assertEquals(ENROL_USER_SUSPENDED, $userenrolment->status);
    }

    /**
     * Tests the enrolment of Ilios learner-group members into a Moodle course.
     */
    public function test_sync_from_ilios_learner_group_members(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Configure the Ilios API client.
        $accesstoken = helper::create_valid_ilios_api_access_token();
        set_config('apikey', $accesstoken, 'enrol_ilios');
        set_config('host_url', 'http://ilios.demo', 'enrol_ilios');

        // Mock out the responses from the Ilios API.
        $handlerstack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'learnerGroups' => [
                    [
                        'id' => 1,
                        'users' => ['2', '3', '4', '5', '6'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'users' => [
                    [
                        'id' => 2,
                        'campusId' => 'xx1000002',
                        'enabled' => true,
                    ],
                    [
                        'id' => 3,
                        'campusId' => 'xx1000003',
                        'enabled' => true,
                    ],
                    [
                        'id' => 4,
                        'campusId' => 'xx1000004',
                        'enabled' => false, // Disabled user account - should result in user unenrolment.
                    ],
                    [
                        'id' => 5,
                        'campusId' => 'xx1000005', // Not currently enrolled - should result in new user enrolment.
                        'enabled' => true,
                    ],
                    [
                        'id' => 6,
                        'campusId' => 'xx1000006', // Currently with suspended enrolment in Moodle.
                        'enabled' => true,
                    ],

                ],
            ])),
        ]));
        $container = [];
        $history = Middleware::history($container);
        $handlerstack->push($history);
        di::set(http_client::class, new http_client(['handler' => $handlerstack]));

        // Get a handle of the enrolment handler.
        $plugin = enrol_get_plugin('ilios');

        // Create a course and users, enrol some students.
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->assertNotEmpty($studentrole);

        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);

        $user1 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000001']);
        $user2 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000002']);
        $user3 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000003']);
        $user4 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000004']);
        $user5 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000005']);
        $user6 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000006']);

        $this->assertEquals(0, $DB->count_records('enrol', ['enrol' => 'ilios']));
        $this->assertEquals(
            0,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(0, $DB->count_records('user_enrolments'));

        // Instantiate an enrolment instance that targets learner-group members in Ilios.
        $ilioslearnergroupid = 1; // Ilios cohort ID.
        $synctype = 'learnerGroup'; // Enrol from cohort.
        $plugin->add_instance($course, [
                'customint1' => $ilioslearnergroupid,
                'customint2' => 0,
                'customchar1' => $synctype,
                'roleid' => $studentrole->id,
            ]
        );
        $this->assertEquals(1, $DB->count_records('enrol', ['enrol' => 'ilios']));
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'ilios'], '*', MUST_EXIST);
        $this->assertEquals($studentrole->id, $instance->roleid);
        $this->assertEquals(1, $instance->customint1);
        $this->assertEquals(0, $instance->customint2);
        $this->assertEquals('learnerGroup', $instance->customchar1);

        // Enable the enrolment method.
        $CFG->enrol_plugins_enabled = 'ilios';

        // Enroll users 1-4 and 6, but not 5.
        $plugin->enrol_user($instance, $user1->id, $studentrole->id);
        $plugin->enrol_user($instance, $user2->id, $studentrole->id);
        $plugin->enrol_user($instance, $user3->id, $studentrole->id);
        $plugin->enrol_user($instance, $user4->id, $studentrole->id);
        $plugin->enrol_user($instance, $user6->id, $studentrole->id);
        // Suspend enrolment of user 6.
        $plugin->update_user_enrol($instance, $user6->id, ENROL_USER_SUSPENDED);

        // Check user enrolments pre-sync.
        $this->assertEquals(
            5,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(5, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));

        // Users 1 - 4 actively enrolled in the course.
        foreach ([$user1, $user2, $user3, $user4] as $user) {
            $this->assertNotEmpty(
                $DB->get_record(
                    'role_assignments',
                    [
                        'roleid' => $studentrole->id,
                        'component' => 'enrol_ilios',
                        'userid' => $user->id,
                        'contextid' => $context->id,
                    ],
                    strictness: MUST_EXIST
                )
            );
            $this->assertNotEmpty(
                $DB->get_record(
                    'user_enrolments',
                    [
                        'enrolid' => $instance->id,
                        'userid' => $user->id,
                        'status' => ENROL_USER_ACTIVE,
                    ],
                    strictness: MUST_EXIST
                )
            );
        }

        // Verify that user 5 is not enrolled.
        $this->assertEmpty(
            $DB->get_record(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'userid' => $user5->id,
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user5->id,
                ]
            )
        );

        // Verify that user 6 has a suspended user enrolment.
        $this->assertNotEmpty(
            $DB->get_record(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'userid' => $user6->id,
                    'contextid' => $context->id,
                ],
                strictness: MUST_EXIST,
            )
        );
        $this->assertNotEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user6->id,
                    'status' => ENROL_USER_SUSPENDED,
                ],
                strictness: MUST_EXIST,
            )
        );

        // Run enrolment sync.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        // Check the captured request history.
        $this->assertEquals('/api/v3/learnergroups/1', $container[0]['request']->getUri()->getPath());
        $this->assertEquals('/api/v3/users', $container[1]['request']->getUri()->getPath());
        $this->assertEquals(
            'filters[id][]=2&filters[id][]=3&filters[id][]=4&filters[id][]=5&filters[id][]=6',
            urldecode($container[1]['request']->getUri()->getQuery())
        );

        // Check the logging output.
        $this->assertStringContainsString(
            "Enrolling students to Course ID {$course->id} with Role ID "
            . "{$studentrole->id} through Ilios Sync ID {$instance->id}.",
            $output
        );
        $this->assertStringContainsString('5 Ilios users found.', $output);
        $this->assertStringContainsString(
            'enrolling with ' . ENROL_USER_ACTIVE . " status: userid {$user5->id} ==> courseid {$course->id}",
            $output
        );
        $this->assertEquals(1, substr_count($output, 'enrolling with ' . ENROL_USER_ACTIVE . ' status:'));
        $this->assertStringContainsString(
            "changing enrollment status to '". ENROL_USER_ACTIVE
            . "' from '" . ENROL_USER_SUSPENDED . "': userid {$user6->id} ==> courseid {$course->id}",
            $output
        );
        $this->assertEquals(1, substr_count($output, 'changing enrollment status'));
        $this->assertStringContainsString(
            "Suspending enrollment for disabled Ilios user: userid  {$user4->id} ==> courseid {$course->id}.",
            $output
        );
        $this->assertEquals(1, substr_count($output, 'Suspending enrollment for disabled Ilios user:'));
        $this->assertStringContainsString(
            "Unenrolling users from Course ID {$course->id} with Role ID {$studentrole->id} " .
            "that no longer associate with Ilios Sync ID {$instance->id}.",
            $output
        );
        $this->assertStringContainsString(
            "unenrolling: {$user1->id} ==> {$course->id} via Ilios {$synctype} {$ilioslearnergroupid}",
            $output
        );
        $this->assertEquals(1, substr_count($output, 'unenrolling:'));
        $this->assertStringContainsString(
            "unassigning role: {$user4->id} ==> {$course->id} as {$studentrole->shortname}",
            $output
        );
        $this->assertEquals(1, substr_count($output, 'unassigning role:'));

        // Check user enrolments post-sync.
        $this->assertEquals(
            4,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(5, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));

        // User 2, 3, and now 5 and 6, are actively enrolled as students in the course.
        foreach ([$user2, $user3, $user5, $user6] as $user) {
            $this->assertNotEmpty(
                $DB->get_record(
                    'role_assignments',
                    [
                        'roleid' => $studentrole->id,
                        'component' => 'enrol_ilios',
                        'userid' => $user->id,
                        'contextid' => $context->id,
                    ],
                    strictness: MUST_EXIST
                )
            );
            $this->assertNotEmpty(
                $DB->get_record(
                    'user_enrolments',
                    [
                        'enrolid' => $instance->id,
                        'userid' => $user->id,
                        'status' => ENROL_USER_ACTIVE,
                    ],
                    strictness: MUST_EXIST
                )
            );
        }
        // Verify that user1 has been fully unenrolled and has no role assigment in the course.
        $this->assertEmpty(
            $DB->get_record(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'userid' => $user1->id,
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user1->id,
                ]
            )
        );
        // User 4 is still enrolled in the course, but their enrolment status suspended and their student assignment
        // in the course has been removed.
        $this->assertEmpty(
            $DB->get_record(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'userid' => $user4->id,
                    'contextid' => $context->id,
                ]
            )
        );
        $userenrolment = $DB->get_record(
            'user_enrolments',
            [
                'enrolid' => $instance->id,
                'userid' => $user4->id,
            ],
            strictness: MUST_EXIST
        );
        $this->assertEquals(ENROL_USER_SUSPENDED, $userenrolment->status);
    }

    /**
     * Tests the enrolment of instructors to an Ilios learner-group (and its subgroups) into a Moodle course.
     */
    public function test_sync_from_ilios_learner_group_instructors(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Configure the Ilios API client.
        $accesstoken = helper::create_valid_ilios_api_access_token();
        set_config('apikey', $accesstoken, 'enrol_ilios');
        set_config('host_url', 'http://ilios.demo', 'enrol_ilios');

        // Mock out the responses from the Ilios API.
        $handlerstack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'learnerGroups' => [
                    [
                        'id' => 1,
                        'children' => ['2', '3'],
                        'ilmSessions' => ['1', '2'],
                        'offerings' => ['1', '2'],
                        'instructorGroups' => [],
                        'instructors' => [],
                    ],
                ],
            ])),
            // We're querying the entry-point learner group again, for no good reason.
            // Todo: Eliminate this duplication from the enrolment workflow [ST 2024/09/30].
            new Response(200, [], json_encode([
                'learnerGroups' => [
                    [
                        'id' => 1,
                        'children' => ['2', '3'],
                        'ilmSessions' => ['1', '2'],
                        'offerings' => ['1', '2'],
                        'instructorGroups' => [],
                        'instructors' => [],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'offerings' => [
                    [
                        'id' => 1,
                        'instructors' => [],
                        'instructorGroups' => ['1'],
                        'learners' => [],
                        'learnerGroups' => ['1'],
                    ],
                    [
                        'id' => 2,
                        'instructors' => ['1'],
                        'instructorGroups' => [],
                        'learners' => [],
                        'learnerGroups' => ['1'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'ilmSessions' => [
                    [
                        'id' => 1,
                        'instructors' => [],
                        'instructorGroups' => ['1'],
                        'learners' => [],
                        'learnerGroups' => ['1'],
                    ],
                    [
                        'id' => 2,
                        'instructors' => ['1'],
                        'instructorGroups' => [],
                        'learners' => [],
                        'learnerGroups' => ['1'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'learnerGroups' => [
                    [
                        'id' => 2,
                        'children' => [],
                        'ilmSessions' => [],
                        'offerings' => ['3'],
                        'instructors' => ['2'],
                        'instructorGroups' => ['2'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'offerings' => [
                    [
                        'id' => 3,
                        'instructors' => [],
                        'instructorGroups' => [],
                        'learners' => [],
                        'learnerGroups' => ['2'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'instructorGroups' => [
                    [
                        'id' => 2,
                        'users' => ['6', '7'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'learnerGroups' => [
                    [
                        'id' => 3,
                        'children' => [],
                        'ilmSessions' => ['3'],
                        'offerings' => [],
                        'instructors' => ['3'],
                        'instructorGroups' => ['3'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'ilmSessions' => [
                    [
                        'id' => 3,
                        'instructors' => [],
                        'instructorGroups' => [],
                        'learners' => [],
                        'learnerGroups' => [],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'instructorGroups' => [
                    [
                        'id' => 3,
                        'users' => ['8', '9'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'instructorGroups' => [
                    [
                        'id' => 1,
                        'users' => ['4', '5'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                    'users' => [
                        ['id' => 1, 'campusId' => 'xx1000001', 'enabled' => true ],
                        ['id' => 2, 'campusId' => 'xx1000002', 'enabled' => true ],
                        ['id' => 3, 'campusId' => 'xx1000003', 'enabled' => true ],
                        ['id' => 4, 'campusId' => 'xx1000004', 'enabled' => true ],
                        ['id' => 5, 'campusId' => 'xx1000005', 'enabled' => true ],
                        ['id' => 6, 'campusId' => 'xx1000006', 'enabled' => true ],
                        ['id' => 7, 'campusId' => 'xx1000007', 'enabled' => true ],
                        ['id' => 8, 'campusId' => 'xx1000008', 'enabled' => true ],
                        ['id' => 9, 'campusId' => 'xx1000009', 'enabled' => true ],
                    ],
            ])),
        ]));
        $container = [];
        $history = Middleware::history($container);
        $handlerstack->push($history);
        di::set(http_client::class, new http_client(['handler' => $handlerstack]));

        // Get a handle of the enrolment handler.
        $plugin = enrol_get_plugin('ilios');

        // Create a course and users, enrol some students.
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->assertNotEmpty($studentrole);

        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);

        $users = array_map(
            fn ($i) => $this->getDataGenerator()->create_user(['idnumber' => 'xx100000'. $i]),
            range(1, 9)
        );

        $this->assertEquals(0, $DB->count_records('enrol', ['enrol' => 'ilios']));
        $this->assertEquals(
            0,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(0, $DB->count_records('user_enrolments'));

        // Instantiate an enrolment instance that targets cohort members in Ilios.
        $learnergroupid = 1;
        $synctype = 'learnerGroup';
        $plugin->add_instance($course, [
                'customint1' => $learnergroupid,
                'customint2' => 1, // Ilios Instructors enrolment.
                'customchar1' => $synctype,
                'roleid' => $studentrole->id,
            ]
        );
        $this->assertEquals(1, $DB->count_records('enrol', ['enrol' => 'ilios']));
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'ilios'], '*', MUST_EXIST);
        $this->assertEquals($studentrole->id, $instance->roleid);
        $this->assertEquals(1, $instance->customint1);
        $this->assertEquals(1, $instance->customint2);
        $this->assertEquals('learnerGroup', $instance->customchar1);

        // Enable the enrolment method.
        $CFG->enrol_plugins_enabled = 'ilios';

        // Check user enrolments pre-sync.
        $this->assertEquals(
            0,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(0, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));

        // Run enrolment sync.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        // Check the captured request history.
        $this->assertEquals('/api/v3/learnergroups/1', $container[0]['request']->getUri()->getPath());
        $this->assertEquals('/api/v3/learnergroups/1', $container[1]['request']->getUri()->getPath());
        $this->assertEquals('/api/v3/offerings', $container[2]['request']->getUri()->getPath());
        $this->assertEquals(
            'filters[id][]=1&filters[id][]=2',
            urldecode($container[2]['request']->getUri()->getQuery())
        );
        $this->assertEquals('/api/v3/ilmsessions', $container[3]['request']->getUri()->getPath());
        $this->assertEquals(
            'filters[id][]=1&filters[id][]=2',
            urldecode($container[3]['request']->getUri()->getQuery())
        );
        $this->assertEquals('/api/v3/learnergroups/2', $container[4]['request']->getUri()->getPath());
        $this->assertEquals('/api/v3/offerings', $container[5]['request']->getUri()->getPath());
        $this->assertEquals('filters[id][]=3', urldecode($container[5]['request']->getUri()->getQuery()));
        $this->assertEquals('/api/v3/instructorgroups', $container[6]['request']->getUri()->getPath());
        $this->assertEquals('filters[id][]=2', urldecode($container[6]['request']->getUri()->getQuery()));
        $this->assertEquals('/api/v3/learnergroups/3', $container[7]['request']->getUri()->getPath());
        $this->assertEquals('/api/v3/ilmsessions', $container[8]['request']->getUri()->getPath());
        $this->assertEquals('filters[id][]=3', urldecode($container[8]['request']->getUri()->getQuery()));
        $this->assertEquals('/api/v3/instructorgroups', $container[9]['request']->getUri()->getPath());
        $this->assertEquals('filters[id][]=3', urldecode($container[9]['request']->getUri()->getQuery()));
        $this->assertEquals('/api/v3/instructorgroups', $container[10]['request']->getUri()->getPath());
        $this->assertEquals('filters[id][]=1', urldecode($container[10]['request']->getUri()->getQuery()));
        $this->assertEquals('/api/v3/users', $container[11]['request']->getUri()->getPath());
        $this->assertEquals(
            'filters[id][]=1&filters[id][]=2&filters[id][]=3&filters[id][]=4'
            . '&filters[id][]=5&filters[id][]=6&filters[id][]=7&filters[id][]=8&filters[id][]=9',
            urldecode($container[11]['request']->getUri()->getQuery()));

        // Check the logging output.
        $this->assertStringContainsString(
            "Enrolling instructors to Course ID {$course->id} with Role ID "
            . "{$studentrole->id} through Ilios Sync ID {$instance->id}.",
            $output
        );
        $this->assertStringContainsString('9 Ilios users found.', $output);
        foreach ($users as $user) {
            $this->assertStringContainsString(
                'enrolling with ' . ENROL_USER_ACTIVE . " status: userid {$user->id} ==> courseid {$course->id}",
                $output
            );
        }
        $this->assertEquals(count($users), substr_count($output, 'enrolling with ' . ENROL_USER_ACTIVE . ' status:'));
        $this->assertStringNotContainsString('Suspending enrollment for disabled Ilios user:', $output);
        $this->assertStringContainsString(
            "Unenrolling users from Course ID {$course->id} with Role ID {$studentrole->id} " .
            "that no longer associate with Ilios Sync ID {$instance->id}.",
            $output
        );
        $this->assertStringNotContainsString('unenrolling:', $output);
        $this->assertStringNotContainsString('unassigning role:', $output);

        // Check user enrolments post-sync.
        $this->assertEquals(
            9,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(9, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));

        // Check that all users have been enrolled.
        foreach ($users as $user) {
            $this->assertNotEmpty(
                $DB->get_record(
                    'role_assignments',
                    [
                        'roleid' => $studentrole->id,
                        'component' => 'enrol_ilios',
                        'userid' => $user->id,
                        'contextid' => $context->id,
                    ],
                    strictness: MUST_EXIST
                )
            );
            $userenrolment = $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user->id,
                ],
                strictness: MUST_EXIST
            );
            $this->assertEquals(ENROL_USER_ACTIVE, $userenrolment->status);
        }
    }

    /**
     * Test that nothing happens when Ilios enrolment is not enabled.
     */
    public function test_sync_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        // Get a handle of the enrolment handler.
        $plugin = enrol_get_plugin('ilios');

        // Create a course and set-up student-enrollment for it. Details beyond that don't really matter here.
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->assertNotEmpty($studentrole);
        $course = $this->getDataGenerator()->create_course();
        $plugin->add_instance($course, [
                'customint1' => 1,
                'customint2' => 0,
                'customchar1' => 'learnerGroup',
                'roleid' => $studentrole->id,
            ]
        );
        $this->assertEquals(1, $DB->count_records('enrol', ['enrol' => 'ilios']));
        $this->assertNotNull($DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'ilios'], '*'));

        // Run the sync.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(2, $plugin->sync($trace, null)); // Note the non-zero exit code here.
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        $this->assertEquals(
            'Ilios enrolment sync plugin is disabled, unassigning all plugin roles and stopping.',
            trim($output)
        );
    }

    /**
     * Test toggling of user enrolment.
     */
    public function test_sync_unenrol_then_reenrol(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Configure the Ilios API client.
        $accesstoken = helper::create_valid_ilios_api_access_token();
        set_config('apikey', $accesstoken, 'enrol_ilios');
        set_config('host_url', 'http://ilios.demo', 'enrol_ilios');
        set_config('unenrolaction', ENROL_EXT_REMOVED_UNENROL, 'enrol_ilios');

        // Mock out the responses from the Ilios API.
        $handlerstack = HandlerStack::create(new MockHandler([
            // API responses for first sync run.
            new Response(200, [], json_encode([
                'cohorts' => [
                    [
                        'id' => 1,
                        'users' => ['1'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'users' => [], // Don't include the user in the payload, this will trigger unenrollment downstream.
            ])),
            // Second sync run responses.
            new Response(200, [], json_encode([
                'cohorts' => [
                    [
                        'id' => 1,
                        'users' => ['1'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'users' => [
                    [
                        'id' => 1,
                        'campusId' => 'xx1000001',
                        'enabled' => true, // Add user to payload, this will result in re-enrollment.
                    ],
                ],
            ])),
        ]));
        $container = [];
        $history = Middleware::history($container);
        $handlerstack->push($history);
        di::set(http_client::class, new http_client(['handler' => $handlerstack]));

        // Get a handle of the enrolment handler.
        $plugin = enrol_get_plugin('ilios');

        // Minimal setup here, one course with one actively enrolled user is enough.
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->assertNotEmpty($studentrole);

        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000001']);

        // Instantiate an enrolment instance that targets cohort members in Ilios.
        $ilioscohortid = 1; // Ilios cohort ID.
        $synctype = 'cohort'; // Enrol from cohort.
        $plugin->add_instance($course, [
                'customint1' => $ilioscohortid,
                'customint2' => 0,
                'customchar1' => $synctype,
                'roleid' => $studentrole->id,
            ]
        );
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'ilios'], '*', MUST_EXIST);
        $CFG->enrol_plugins_enabled = 'ilios';
        $plugin->enrol_user($instance, $user->id, $studentrole->id);

        // Check user enrolments pre-sync.
        $this->assertEquals(
            1,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
        $this->assertNotEmpty(
            $DB->get_record(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'userid' => $user->id,
                    'contextid' => $context->id,
                ],
                strictness: MUST_EXIST
            )
        );
        $this->assertNotEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user->id,
                    'status' => ENROL_USER_ACTIVE,
                ],
                strictness: MUST_EXIST
            )
        );

        // Run enrolment sync.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

         // Check the logging output.
        $this->assertStringContainsString('0 Ilios users found.', $output);
        $this->assertStringContainsString(
            "unenrolling: {$user->id} ==> {$course->id} via Ilios {$synctype} {$ilioscohortid}",
            $output
        );

        // Check user enrolment post-sync.
        // Verify that the user has been fully unenrolled and has no role assigment in the course
        // by checking that the course has currently no enrolments and role assignments.
        $this->assertEquals(
            0,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(0, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));

        // Run the sync for a second time.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        // Check the captured request history.
        $this->assertEquals('ilios.demo', $container[0]['request']->getUri()->getHost());
        $this->assertEquals('/api/v3/cohorts/1', $container[0]['request']->getUri()->getPath());
        $this->assertEquals('/api/v3/users', $container[1]['request']->getUri()->getPath());
        $this->assertEquals(
            'filters[id][]=1',
            urldecode($container[1]['request']->getUri()->getQuery())
        );
        $this->assertEquals('ilios.demo', $container[2]['request']->getUri()->getHost());
        $this->assertEquals('/api/v3/cohorts/1', $container[2]['request']->getUri()->getPath());
        $this->assertEquals('/api/v3/users', $container[3]['request']->getUri()->getPath());
        $this->assertEquals(
            'filters[id][]=1',
            urldecode($container[3]['request']->getUri()->getQuery())
        );

        // Check the logging output.
        $this->assertStringContainsString('1 Ilios users found.', $output);
        $this->assertStringContainsString(
            'enrolling with ' . ENROL_USER_ACTIVE . " status: userid {$user->id} ==> courseid {$course->id}",
            $output
        );

        // Check user enrolment post-sync.
        // Verify that the user has been fully re-enrolled
        // and that the user has the student role assigment in the course.
        $this->assertEquals(
            1,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
        $this->assertNotEmpty(
            $DB->get_record(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'userid' => $user->id,
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertNotEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user->id,
                    'status' => ENROL_USER_ACTIVE,
                ]
            )
        );
    }

    /**
     * Test toggling of enrolment status.
     */
    public function test_sync_suspend_then_unsuspend_enrolment(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Configure the Ilios API client.
        $accesstoken = helper::create_valid_ilios_api_access_token();
        set_config('apikey', $accesstoken, 'enrol_ilios');
        set_config('host_url', 'http://ilios.demo', 'enrol_ilios');
        set_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES, 'enrol_ilios');

        // Mock out the responses from the Ilios API.
        $handlerstack = HandlerStack::create(new MockHandler([
            // API responses for first sync run.
            new Response(200, [], json_encode([
                'cohorts' => [
                    [
                        'id' => 1,
                        'users' => ['1'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'users' => [], // Don't include the user in the payload, this will trigger unenrollment downstream.
            ])),
            // Second sync run responses.
            new Response(200, [], json_encode([
                'cohorts' => [
                    [
                        'id' => 1,
                        'users' => ['1'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'users' => [
                    [
                        'id' => 1,
                        'campusId' => 'xx1000001',
                        'enabled' => true, // Add user to payload, this will result in re-enrollment.
                    ],
                ],
            ])),
        ]));
        $container = [];
        $history = Middleware::history($container);
        $handlerstack->push($history);
        di::set(http_client::class, new http_client(['handler' => $handlerstack]));

        // Get a handle of the enrolment handler.
        $plugin = enrol_get_plugin('ilios');

        // Minimal setup here, one course with one actively enrolled user is enough.
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->assertNotEmpty($studentrole);

        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000001']);

        // Instantiate an enrolment instance that targets cohort members in Ilios.
        $ilioscohortid = 1; // Ilios cohort ID.
        $synctype = 'cohort'; // Enrol from cohort.
        $plugin->add_instance($course, [
                'customint1' => $ilioscohortid,
                'customint2' => 0,
                'customchar1' => $synctype,
                'roleid' => $studentrole->id,
            ]
        );
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'ilios'], '*', MUST_EXIST);
        $CFG->enrol_plugins_enabled = 'ilios';
        $plugin->enrol_user($instance, $user->id, $studentrole->id);

        // Check user enrolments pre-sync.
        $this->assertEquals(
            1,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );

        // Check user enrollment pre-sync.
        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
        $this->assertNotEmpty(
            $DB->get_record(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'userid' => $user->id,
                    'contextid' => $context->id,
                ],
                strictness: MUST_EXIST
            )
        );
        $this->assertNotEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user->id,
                    'status' => ENROL_USER_ACTIVE,
                ],
                strictness: MUST_EXIST
            )
        );

        // Run enrolment sync.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        // Check the logging output.
        $this->assertStringContainsString('0 Ilios users found.', $output);
        $this->assertStringNotContainsString(
            "unenrolling: {$user->id} ==> {$course->id} via Ilios {$synctype} {$ilioscohortid}",
            $output
        );
        $this->assertStringContainsString(
            "suspending and unassigning all roles: userid {$user->id} ==> courseid {$course->id}",
            $output
        );

        // Check user enrolment post-sync.
        // Verify that the user's enrolment has been suspended
        // and that the user's student role has been removed from the course.
        $this->assertEquals(
            0,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
        $this->assertNotEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user->id,
                    'status' => ENROL_USER_SUSPENDED,
                ]
            )
        );

        // Run the sync for a second time.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        // Check the captured request history.
        $this->assertEquals('ilios.demo', $container[0]['request']->getUri()->getHost());
        $this->assertEquals('/api/v3/cohorts/1', $container[0]['request']->getUri()->getPath());
        $this->assertEquals('/api/v3/users', $container[1]['request']->getUri()->getPath());
        $this->assertEquals(
            'filters[id][]=1',
            urldecode($container[1]['request']->getUri()->getQuery())
        );
        $this->assertEquals('ilios.demo', $container[2]['request']->getUri()->getHost());
        $this->assertEquals('/api/v3/cohorts/1', $container[2]['request']->getUri()->getPath());
        $this->assertEquals('/api/v3/users', $container[3]['request']->getUri()->getPath());
        $this->assertEquals(
            'filters[id][]=1',
            urldecode($container[3]['request']->getUri()->getQuery())
        );

        // Check the logging output.
        $this->assertStringContainsString('1 Ilios users found.', $output);
        $this->assertStringContainsString(
            "changing enrollment status to '". ENROL_USER_ACTIVE
            . "' from '" . ENROL_USER_SUSPENDED . "': userid {$user->id} ==> courseid {$course->id}",
            $output
        );

        // Check user enrolment post-sync.
        // Verify that the user's enrolment has been reactivated and
        // that the user's student role has been re-assigned in the course.
        $this->assertEquals(
            1,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
        $this->assertNotEmpty(
            $DB->get_record(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'userid' => $user->id,
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertNotEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user->id,
                    'status' => ENROL_USER_ACTIVE,
                ]
            )
        );
    }

    /**
     * Test that suspended enrollments do not get re-activated for disabled Ilios users.
     */
    public function test_sync_do_not_reactivate_suspended_enrolment_for_disabled_ilios_users(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Configure the Ilios API client.
        $accesstoken = helper::create_valid_ilios_api_access_token();
        set_config('apikey', $accesstoken, 'enrol_ilios');
        set_config('host_url', 'http://ilios.demo', 'enrol_ilios');

        // Mock out the responses from the Ilios API.
        $handlerstack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'cohorts' => [
                    [
                        'id' => 1,
                        'users' => ['1'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'users' => [
                    [
                        'id' => 1,
                        'campusId' => 'xx1000001',
                        'enabled' => false, // Disabled in Ilios.
                    ],
                ],
            ])),
        ]));
        $container = [];
        $history = Middleware::history($container);
        $handlerstack->push($history);
        di::set(http_client::class, new http_client(['handler' => $handlerstack]));

        // Get a handle of the enrolment handler.
        $plugin = enrol_get_plugin('ilios');

        // Set up one course with one enrolled but suspended user is enough.
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->assertNotEmpty($studentrole);

        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000001']);

        // Instantiate an enrolment instance that targets cohort members in Ilios.
        $ilioscohortid = 1; // Ilios cohort ID.
        $synctype = 'cohort'; // Enrol from cohort.
        $plugin->add_instance($course, [
                'customint1' => $ilioscohortid,
                'customint2' => 0,
                'customchar1' => $synctype,
                'roleid' => $studentrole->id,
            ]
        );
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'ilios'], '*', MUST_EXIST);
        $CFG->enrol_plugins_enabled = 'ilios';

        // Enrol the user, then suspend the enrolment.
        $plugin->enrol_user($instance, $user->id, $studentrole->id);
        $plugin->update_user_enrol($instance, $user->id, ENROL_USER_SUSPENDED);

        // Check user enrolments pre-sync.
        $this->assertEquals(
            1,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
        $this->assertNotEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user->id,
                    'status' => ENROL_USER_SUSPENDED,
                ],
                strictness: MUST_EXIST
            )
        );

        // Run enrolment sync.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        // Check the captured request history.
        $this->assertEquals('ilios.demo', $container[0]['request']->getUri()->getHost());
        $this->assertEquals('/api/v3/cohorts/1', $container[0]['request']->getUri()->getPath());
        $this->assertEquals('/api/v3/users', $container[1]['request']->getUri()->getPath());
        $this->assertEquals(
            'filters[id][]=1',
            urldecode($container[1]['request']->getUri()->getQuery())
        );

        // Check the logging output.
        $this->assertStringContainsString('1 Ilios users found.', $output);
        $this->assertStringNotContainsString(
            "unenrolling: {$user->id} ==> {$course->id} via Ilios {$synctype} {$ilioscohortid}",
            $output
        );
        $this->assertStringNotContainsString(
            "suspending and unassigning all roles: userid {$user->id} ==> courseid {$course->id}",
            $output
        );

        // Check user enrolment post-sync.
        // Verify that the user's enrolment has been suspended
        // and that the user's student role has been removed from the course.
        $this->assertEquals(
            0,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
        $this->assertNotEmpty(
            $DB->get_record(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'userid' => $user->id,
                    'status' => ENROL_USER_SUSPENDED,
                ],
                strictness: MUST_EXIST
            )
        );
    }

    /**
     * Test that disabled Ilios users are not enrolled in the first place.
     */
    public function test_sync_do_not_enrol_disabled_ilios_users(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Configure the Ilios API client.
        $accesstoken = helper::create_valid_ilios_api_access_token();
        set_config('apikey', $accesstoken, 'enrol_ilios');
        set_config('host_url', 'http://ilios.demo', 'enrol_ilios');

        // Mock out the responses from the Ilios API.
        $handlerstack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'cohorts' => [
                    [
                        'id' => 1,
                        'users' => ['1'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'users' => [
                    [
                        'id' => 1,
                        'campusId' => 'xx1000001',
                        'enabled' => false, // Disabled in Ilios.
                    ],
                ],
            ])),
        ]));
        $container = [];
        $history = Middleware::history($container);
        $handlerstack->push($history);
        di::set(http_client::class, new http_client(['handler' => $handlerstack]));

        // Get a handle of the enrolment handler.
        $plugin = enrol_get_plugin('ilios');

        // Minimal setup here, one course without user enrolments.
        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000001']);

        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->assertNotEmpty($studentrole);

        // Instantiate an enrolment instance that targets cohort members in Ilios.
        $ilioscohortid = 1; // Ilios cohort ID.
        $synctype = 'cohort'; // Enrol from cohort.
        $plugin->add_instance($course, [
                'customint1' => $ilioscohortid,
                'customint2' => 0,
                'customchar1' => $synctype,
                'roleid' => $studentrole->id,
            ]
        );
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'ilios'], '*', MUST_EXIST);
        $CFG->enrol_plugins_enabled = 'ilios';

        // Check user enrolments pre-sync.
        $this->assertEquals(
            0,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(0, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));

        // Run enrolment sync.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        // Check the captured request history.
        $this->assertEquals('ilios.demo', $container[0]['request']->getUri()->getHost());
        $this->assertEquals('/api/v3/cohorts/1', $container[0]['request']->getUri()->getPath());
        $this->assertEquals('/api/v3/users', $container[1]['request']->getUri()->getPath());
        $this->assertEquals(
            'filters[id][]=1',
            urldecode($container[1]['request']->getUri()->getQuery())
        );

        // Check the logging output.
        $this->assertStringContainsString('1 Ilios users found.', $output);
        $this->assertStringNotContainsString(
            "unenrolling: {$user->id} ==> {$course->id} via Ilios {$synctype} {$ilioscohortid}",
            $output
        );
        $this->assertStringNotContainsString(
            'enrolling with ' . ENROL_USER_ACTIVE . " status: userid {$user->id} ==> courseid {$course->id}",
            $output
        );
        $this->assertStringNotContainsString(
            "suspending and unassigning all roles: userid {$user->id} ==> courseid {$course->id}",
            $output
        );

        // Check user enrolments post-sync.
        // Verify that not users have been enrolled and no user roles have been assigned in the course.
        $this->assertEquals(
            0,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',

                    'contextid' => $context->id,
                ]
            )
        );
        $this->assertEquals(0, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
    }

    /**
     * Test that disabled enrollment instances do not get processed.
     */
    public function test_sync_ignore_disabled_instances(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Get a handle of the enrolment handler.
        $plugin = enrol_get_plugin('ilios');

        // Minimal setup here, one course without user enrolments.
        $course = $this->getDataGenerator()->create_course();

        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->assertNotEmpty($studentrole);

        // Create a disabled enrolment instance that targets cohort members in Ilios.
        $ilioscohortid = 1; // Ilios cohort ID.
        $synctype = 'cohort'; // Enrol from cohort.
        $plugin->add_instance($course, [
                'customint1' => $ilioscohortid,
                'customint2' => 0,
                'customchar1' => $synctype,
                'roleid' => $studentrole->id,
                'status' => ENROL_INSTANCE_DISABLED,
            ]
        );
        $CFG->enrol_plugins_enabled = 'ilios';

        // Run enrolment sync.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        // Check the logging output.
        // There should be nothing in here but the task start/end notifications.
        $this->assertEquals(
            "Starting user enrolment synchronisation...\n"
            . "...user enrolment synchronisation finished."
            , trim($output));

        // No need to check enrolments, nothing happened during the sync.
    }

    /**
     * Test that users without campus ID are filtered out from the sync.
     */
    public function test_sync_ignore_ilios_users_without_campus_id(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Configure the Ilios API client.
        $accesstoken = helper::create_valid_ilios_api_access_token();
        set_config('apikey', $accesstoken, 'enrol_ilios');
        set_config('host_url', 'http://ilios.demo', 'enrol_ilios');

        // Mock out the responses from the Ilios API.
        $handlerstack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'cohorts' => [
                    [
                        'id' => 1,
                        'users' => ['1'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'users' => [
                    [
                        'id' => 1,
                        'campusId' => null,
                        'enabled' => true,
                    ],
                ],
            ])),
        ]));
        $container = [];
        $history = Middleware::history($container);
        $handlerstack->push($history);
        di::set(http_client::class, new http_client(['handler' => $handlerstack]));

        // Get a handle of the enrolment handler.
        $plugin = enrol_get_plugin('ilios');

        // Minimal setup here, one course without user enrolments.
        $course = $this->getDataGenerator()->create_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->assertNotEmpty($studentrole);

        // Instantiate an enrolment instance that targets cohort members in Ilios.
        $ilioscohortid = 1; // Ilios cohort ID.
        $synctype = 'cohort'; // Enrol from cohort.
        $plugin->add_instance($course, [
                'customint1' => $ilioscohortid,
                'customint2' => 0,
                'customchar1' => $synctype,
                'roleid' => $studentrole->id,
            ]
        );
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'ilios'], '*', MUST_EXIST);
        $CFG->enrol_plugins_enabled = 'ilios';

        // Run enrolment sync.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        // Check the logging output.
        $this->assertStringContainsString('1 Ilios users found.', $output);
        $this->assertStringContainsString("skipping: Ilios user 1 does not have a 'campusId' field.", $output);

        // Check enrollments. There shouldn't be any.
        $this->assertEquals(0, $DB->count_records('user_enrolments', ['enrolid' => $instance->id]));
    }

    /**
     * Test that deduplication of Ilios users by campus ID works as intended..
     */
    public function test_sync_deduplication_of_ilios_users_by_campus_id(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Configure the Ilios API client.
        $accesstoken = helper::create_valid_ilios_api_access_token();
        set_config('apikey', $accesstoken, 'enrol_ilios');
        set_config('host_url', 'http://ilios.demo', 'enrol_ilios');

        // Mock out the responses from the Ilios API.
        // This sets it up for two sync-runs, returning the same data on each one.
        $cohortspayload = json_encode([
            'cohorts' => [
                [
                    'id' => 1,
                    'users' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13'],
                ],
            ],
        ]);
        $userspayload = json_encode([
            'users' => [
                // First - third user, all with a different mix of disabled and enabled duplicate accounts.
                [
                    'id' => 1,
                    'campusId' => 'xx1000001',
                    'enabled' => false,
                ],
                [
                    'id' => 2,
                    'campusId' => 'xx1000001',
                    'enabled' => true,
                ],
                [
                    'id' => 3,
                    'campusId' => 'xx1000001',
                    'enabled' => false,
                ],
                [
                    'id' => 4,
                    'campusId' => 'xx1000002',
                    'enabled' => false,
                ],
                [
                    'id' => 5,
                    'campusId' => 'xx1000002',
                    'enabled' => false,
                ],
                [
                    'id' => 6,
                    'campusId' => 'xx1000002',
                    'enabled' => true,
                ],
                [
                    'id' => 7,
                    'campusId' => 'xx1000003',
                    'enabled' => true,
                ],
                [
                    'id' => 8,
                    'campusId' => 'xx1000003',
                    'enabled' => false,
                ],
                [
                    'id' => 9,
                    'campusId' => 'xx1000003',
                    'enabled' => false,
                ],
                // Fourth user, all duplicates are enabled.
                [
                    'id' => 10,
                    'campusId' => 'xx1000004',
                    'enabled' => true,
                ],
                [
                    'id' => 11,
                    'campusId' => 'xx1000004',
                    'enabled' => true,
                ],
                // Fifth user, all duplicates are disabled.
                [
                    'id' => 12,
                    'campusId' => 'xx1000005',
                    'enabled' => false,
                ],
                [
                    'id' => 13,
                    'campusId' => 'xx1000005',
                    'enabled' => false,
                ],
            ],
        ]);

        $handlerstack = HandlerStack::create(new MockHandler([
            // First sync.
            new Response(200, [], $cohortspayload),
            new Response(200, [], $userspayload),
            // Second sync.
            new Response(200, [], $cohortspayload),
            new Response(200, [], $userspayload),
        ]));
        $container = [];
        $history = Middleware::history($container);
        $handlerstack->push($history);
        di::set(http_client::class, new http_client(['handler' => $handlerstack]));

        // Get a handle of the enrolment handler.
        $plugin = enrol_get_plugin('ilios');

        // Sets up a course and create the student role.
        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);

        // Instantiate an enrolment instance that targets cohort members in Ilios.
        $ilioscohortid = 1; // Ilios cohort ID.
        $synctype = 'cohort'; // Enrol from cohort.
        $plugin->add_instance($course, [
                'customint1' => $ilioscohortid,
                'customint2' => 0,
                'customchar1' => $synctype,
                'roleid' => $studentrole->id,
            ]
        );
        $CFG->enrol_plugins_enabled = 'ilios';
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'ilios'], '*', MUST_EXIST);

        // Create 5 users and enroll them as students into the course.
        $user1 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000001']);
        $user2 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000002']);
        $user3 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000003']);
        $user4 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000004']);
        $user5 = $this->getDataGenerator()->create_user(['idnumber' => 'xx1000005']);

        $plugin->enrol_user($instance, $user1->id, $studentrole->id);
        $plugin->enrol_user($instance, $user2->id, $studentrole->id);
        $plugin->enrol_user($instance, $user3->id, $studentrole->id);
        $plugin->enrol_user($instance, $user4->id, $studentrole->id);
        $plugin->enrol_user($instance, $user5->id, $studentrole->id);

        // Check user enrollment and role assignments pre-sync.
        // All users should be actively enrolled as students in the given course.
        $this->assertEquals(
            5,
            $DB->count_records(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'status' => ENROL_USER_ACTIVE,
                ],
            )
        );
        $this->assertEquals(
            5,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        foreach ([$user1, $user2, $user3, $user4, $user5] as $user) {
            $this->assertNotEmpty(
                $DB->get_record(
                    'user_enrolments',
                    [
                        'enrolid' => $instance->id,
                        'userid' => $user->id,
                        'status' => ENROL_USER_ACTIVE,
                    ],
                    strictness: MUST_EXIST
                )
            );
            $this->assertNotEmpty(
                $DB->get_record(
                    'role_assignments',
                    [
                        'roleid' => $studentrole->id,
                        'component' => 'enrol_ilios',
                        'userid' => $user->id,
                        'contextid' => $context->id,
                    ],
                    strictness: MUST_EXIST
                )
            );
        }

        // Run enrolment sync.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        // Check the logging output.
        $this->assertStringContainsString('13 Ilios users found.', $output);
        $this->assertStringContainsString(
            "Suspending enrollment for disabled Ilios user: userid  {$user5->id} ==> courseid {$course->id}",
            $output,
        );
        $this->assertStringContainsString(
            "Unenrolling users from Course ID {$course->id} with Role ID {$studentrole->id} that no longer"
            . " associate with Ilios Sync ID {$instance->id}",
            $output,
        );
        $this->assertStringContainsString(
            "unassigning role: {$user5->id} ==> {$course->id} as {$studentrole->shortname}",
            $output,
        );

        // Check user enrollments and role assignments post-sync.
        // Users 1-4 should still be actively enrolled as students.
        // User 5 should have been unenrolled and their role assigment should have been removed.
        $this->assertEquals(
            4,
            $DB->count_records(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'status' => ENROL_USER_ACTIVE,
                ],
            )
        );
        $this->assertEquals(
            4,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        foreach ([$user1, $user2, $user3, $user4] as $user) {
            $this->assertNotEmpty(
                $DB->get_record(
                    'user_enrolments',
                    [
                        'enrolid' => $instance->id,
                        'userid' => $user->id,
                        'status' => ENROL_USER_ACTIVE,
                    ],
                    strictness: MUST_EXIST
                )
            );
            $this->assertNotEmpty(
                $DB->get_record(
                    'role_assignments',
                    [
                        'roleid' => $studentrole->id,
                        'component' => 'enrol_ilios',
                        'userid' => $user->id,
                        'contextid' => $context->id,
                    ],
                    strictness: MUST_EXIST
                )
            );
        }

        // Run the sync again, with the same payload from Ilios.
        $trace = new progress_trace_buffer(new text_progress_trace(), false);
        $this->assertEquals(0, $plugin->sync($trace, null));
        $output = $trace->get_buffer();
        $trace->finished();
        $trace->reset_buffer();

        // Check the logging output.
        // There should be nothing in there pertaining to new (un-)enrollments nor role (un-)assignments.
        $this->assertStringNotContainsString('unenrolling:', $output);
        $this->assertStringNotContainsString('unassigning role:', $output);
        $this->assertStringNotContainsString(
            'enrolling with ' . ENROL_USER_ACTIVE . ' status:',
            $output
        );
        $this->assertStringNotContainsString('suspending and unassigning all roles:', $output);

        // Check user enrollments and role assignments post-sync.
        // These should NOT have changed since the last run.
        // To recap:
        // Users 1-4 should be actively enrolled as students.
        // User 5 should not be enrolled and should have no role assignment in the course.
        $this->assertEquals(
            4,
            $DB->count_records(
                'user_enrolments',
                [
                    'enrolid' => $instance->id,
                    'status' => ENROL_USER_ACTIVE,
                ],
            )
        );
        $this->assertEquals(
            4,
            $DB->count_records(
                'role_assignments',
                [
                    'roleid' => $studentrole->id,
                    'component' => 'enrol_ilios',
                    'contextid' => $context->id,
                ]
            )
        );
        foreach ([$user1, $user2, $user3, $user4] as $user) {
            $this->assertNotEmpty(
                $DB->get_record(
                    'user_enrolments',
                    [
                        'enrolid' => $instance->id,
                        'userid' => $user->id,
                        'status' => ENROL_USER_ACTIVE,
                    ],
                    strictness: MUST_EXIST
                )
            );
            $this->assertNotEmpty(
                $DB->get_record(
                    'role_assignments',
                    [
                        'roleid' => $studentrole->id,
                        'component' => 'enrol_ilios',
                        'userid' => $user->id,
                        'contextid' => $context->id,
                    ],
                    strictness: MUST_EXIST
                )
            );
        }
    }

    /**
     * Test group enrolment during sync.
     */
    public function test_sync_group_enrolment(): void {
        $this->markTestIncomplete('to be done.');
    }

    /**
     * Test that an explicitly set instance name is returned.
     */
    public function test_get_instance_name_from_instance_name(): void {
        $this->markTestIncomplete('to be done.');
    }

    /**
     * Test that the correct plugin name is created from the sync-info as a fallback in case the instance has no name.
     */
    public function test_get_instance_name_from_sync_info(): void {
        $this->markTestIncomplete('to be done.');
    }

    /**
     * Test that the correct plugin name is returned if not instance is given.
     */
    public function test_get_instance_name_no_instance(): void {
        $this->markTestIncomplete('to be done.');
    }
}

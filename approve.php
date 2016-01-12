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
 * Copyright (C) 2007-2011 Catalyst IT (http://www.catalyst.net.nz)
 * Copyright (C) 2011-2013 Totara LMS (http://www.totaralms.com)
 * Copyright (C) 2014 onwards Catalyst IT (http://www.catalyst-eu.net)
 *
 * @package    mod
 * @subpackage facetoface
 * @copyright  2014 onwards Catalyst IT <http://www.catalyst-eu.net>
 * @author     Stacey Walker <stacey@catalyst-eu.net>
 * @author     Alastair Munro <alastair.munro@totaralms.com>
 * @author     Aaron Barnes <aaron.barnes@totaralms.com>
 * @author     Francois Marier <francois@catalyst.net.nz>
 */

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');
require_login();

// Face-to-face session ID.
$s = required_param('s', PARAM_INT);
$cancelform = optional_param('cancelform', false, PARAM_BOOL); // Cancel request.
$backtoallsessions = optional_param('backtoallsessions', 0, PARAM_INT); // Face-to-face activity to return to.

// Load data.
if (!$session = facetoface_get_session($s)) {
    print_error('error:incorrectcoursemodulesession', 'facetoface');
}
if (!$facetoface = $DB->get_record('facetoface', array('id' => $session->facetoface))) {
    print_error('error:incorrectfacetofaceid', 'facetoface');
}
if (!$course = $DB->get_record('course', array('id' => $facetoface->course))) {
    print_error('error:coursemisconfigured', 'facetoface');
}
if (!$cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id)) {
    print_error('error:incorrectcoursemodule', 'facetoface');
}

// Load attendees.
$attendees = facetoface_get_attendees($session->id);

// list of requests
$requests = facetoface_get_requests($session->id);

// Load cancellations.
$cancellations = facetoface_get_cancellations($session->id);

$context = context_course::instance($course->id);
$contextmodule = context_module::instance($cm->id);

$PAGE->set_context($context);
/*
 * Handle submitted data
 */
if ($form = data_submitted()) {
    if (!confirm_sesskey()) {
        print_error('confirmsesskeybad', 'error');
    }

    $return = "{$CFG->wwwroot}/mod/facetoface/approve.php?s={$s}&backtoallsessions={$backtoallsessions}";

    if ($cancelform) {
        redirect($return);
    } else if (!empty($form->requests)) {
        // Approve requests.
        if (facetoface_approve_requests($form)) {
            // Logging and events trigger.
            $params = array(
                'context'  => $contextmodule,
                'objectid' => $session->id
            );
            $event = \mod_facetoface\event\approve_requests::create($params);
            $event->add_record_snapshot('facetoface_sessions', $session);
            $event->add_record_snapshot('facetoface', $facetoface);
            $event->trigger();
        }

        redirect($return);
    }
}

// Logging and events trigger.
$params = array(
    'context'  => $contextmodule,
    'objectid' => $session->id
);
$event = \mod_facetoface\event\attendees_viewed::create($params);
$event->add_record_snapshot('facetoface_sessions', $session);
$event->add_record_snapshot('facetoface', $facetoface);
$event->trigger();

$pagetitle = format_string($facetoface->name);

$PAGE->set_url('/mod/facetoface/approve.php', array('s' => $s));
$PAGE->set_context($context);
$PAGE->set_cm($cm);

$PAGE->set_title($pagetitle);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->box_start();
echo $OUTPUT->heading(format_string($facetoface->name));

$OUTPUT->heading(get_string('unapprovedrequests', 'facetoface'));

$action = new moodle_url('approve.php', array('s' => $s));
echo html_writer::start_tag('form', array('action' => $action->out(), 'method' => 'post'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => $USER->sesskey));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 's', 'value' => $s));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'backtoallsessions', 'value' => $backtoallsessions)) . html_writer::end_tag('p');

$table = new html_table();
$table->summary = get_string('requeststablesummary', 'facetoface');
$table->head = array(get_string('name'), get_string('timerequested', 'facetoface'),
                    get_string('decidelater', 'facetoface'), get_string('decline', 'facetoface'), get_string('approve', 'facetoface'));
$table->align = array('left', 'center', 'center', 'center', 'center');

foreach ($requests as $attendee) {
    $usercontext = context_user::instance($attendee->id);
    if($canbookuser = has_capability('mod/facetoface:approveuser', $usercontext)) {
        $data = array();
        $attendeelink = new moodle_url('/user/view.php', array('id' => $attendee->id, 'course' => $course->id));
        $data[] = html_writer::link($attendeelink, format_string(fullname($attendee)));
        $data[] = userdate($attendee->timerequested, get_string('strftimedatetime'));
        $data[] = html_writer::empty_tag('input', array('type' => 'radio', 'name' => 'requests['.$attendee->id.']', 'value' => '0', 'checked' => 'checked'));
        $data[] = html_writer::empty_tag('input', array('type' => 'radio', 'name' => 'requests['.$attendee->id.']', 'value' => '1'));
        $disabled = ($canbookuser) ? array() : array('disabled' => 'disabled');
        $data[] = html_writer::empty_tag('input', array_merge(array('type' => 'radio', 'name' => 'requests['.$attendee->id.']', 'value' => '2'), $disabled));
        $table->data[] = $data;
    }
}

echo html_writer::table($table);

echo html_writer::tag('p', html_writer::empty_tag('input', array('type' => 'submit', 'value' => get_string('updaterequests', 'facetoface'))));
echo html_writer::end_tag('form');

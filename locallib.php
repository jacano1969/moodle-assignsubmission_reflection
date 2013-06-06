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
 * Library class for the reflection submission plugin.
 *
 * @package assignsubmission_reflection
 * @copyright 2013 Department of Computer and System Sciences,
 *					Stockholm University  {@link http://dsv.su.se}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/submissionplugin.php');
require_once($CFG->dirroot . '/mod/assign/submission/reflection/lib.php');

class assign_submission_reflection extends assign_submission_plugin {

    /**
     * Get the name of the submission plugin
     * @return string
     */
    public function get_name() {
        return get_string('reflection', 'assignsubmission_reflection');
    }

    /**
     * Get the default settings for the submission plugin.
     *
     * @param MoodleQuickForm $mform The form to append the elements to.
     */
    public function get_settings(MoodleQuickForm $mform) {
        global $CFG, $DB, $COURSE, $OUTPUT;

        $cmid = optional_param('update', 0, PARAM_INT);

        $studentsdefault = $this->get_config('students');
        if ($studentsdefault === false) {
            $studentsdefault = get_config('assignsubmission_reflection', 'students');
        }

        $mform->setDefault('assignsubmission_file_enabled', 0);
        $mform->setDefault('assignsubmission_blog_enabled', 0);
        $mform->setDefault('assignsubmission_online_enabled', 0);
        $mform->disabledIf('assignsubmission_file_enabled', 'assignsubmission_reflection_enabled', 'eq', 1);
        $mform->disabledIf('assignsubmission_blog_enabled', 'assignsubmission_reflection_enabled', 'eq', 1);
        $mform->disabledIf('assignsubmission_onlinetext_enabled', 'assignsubmission_reflection_enabled', 'eq', 1);
        $mform->setDefault('submissiondrafts', 1);
        $mform->disabledIf('submissiondrafts', 'assignsubmission_reflection_enabled', 'eq', 1);
        $mform->setDefault('teamsubmission', 0);
        $mform->disabledIf('teamsubmission', 'assignsubmission_reflection_enabled', 'eq', 1);
        //$mform->setDefault('assignsubmission_mailsimulator_enabled', 0);
        //$mform->disabledIf('assignsubmission_mailsimulator_enabled', 'assignsubmission_reflection_enabled', 'eq', 1);

        // Select number of students in a group.
        $maxstudents = array();
        for ($i=1; $i <= ASSIGNSUBMISSION_REFLECTION_MAXSTUDENTS; $i++) {
            $maxstudents[$i] = $i;
        }
        $mform->addElement('select', 'assignsubmission_reflection_students',
            get_string('students', 'assignsubmission_reflection'),
            $maxstudents);
        $mform->setDefault('assignsubmission_reflection_students', $studentsdefault);
        $mform->addHelpButton('assignsubmission_reflection_students', 'students',
            'assignsubmission_reflection');
        // Moodle 2.5.
        // $mform->disabledIf('assignsubmission_mailsimulator_filesubmissions', 'assignsubmission_mailsimulator_enabled', 'notchecked');
        // Moodle 2.4.
        $mform->disabledIf('assignsubmission_reflection_students', 'assignsubmission_reflection_enabled', 'eq', 0);

    }

    /**
     * Save the settings for the plugin
     * 
     * @param stdClass $data
     * @return bool
     */
    public function save_settings(stdClass $data) {
        $updateid = optional_param('update', 0, PARAM_INT);
        $this->set_config('students', $data->assignsubmission_reflection_students);
        if ($data->assignsubmission_reflection_enabled && !$updateid) {
            $forumid = $this->create_grouping_and_forum($data);
            $this->set_config('forumid', $forumid);
        }
        return true;
    }

    /**
     * Initialize the forum and grouping for the plugin
     * 
     * @param stdClass $data
     * @return bool
     */
    public function create_grouping_and_forum(stdClass $data) {
        global $CFG, $DB, $COURSE;
        require_once($CFG->dirroot.'/group/lib.php');
        require_once($CFG->dirroot.'/mod/forum/lib.php');

        // First add a course module for a forum.
        $newcm = new stdClass();
        $newcm->course           = $COURSE->id;
        $newcm->module           = 9; // Forum
        $newcm->section          = $data->section;
        $newcm->instance         = 0; // not known yet, will be updated later (this is similar to restore code)
        $newcm->visible          = $data->visible;
        $newcm->visibleold       = $data->visible;
        $newcm->groupmode        = 1; // Separate groups.
        $newcm->groupingid       = 0; // Not known yet, will be updated later.
        $newcm->groupmembersonly = 1;
        if(!empty($CFG->enableavailability)) {
            $newcm->availablefrom             = $data->availablefrom;
            $newcm->availableuntil            = $data->availableuntil;
            $newcm->showavailability          = $data->showavailability;
        }

        $coursemodule = add_course_module($newcm);

        // Then add a forum.
        $forum = new stdClass();
        $forum->course = $COURSE->id;
        $forum->name = $data->name.' '.get_string('forum', 'forum');
        $forum->type = 'eachuser';
        $obj = new stdClass();
        $obj->name = $data->name;
        $obj->href = '<a href="'.$CFG->wwwroot.'/mod/assign/view.php?id='.$data->coursemodule.'">';
        $forum->intro = get_string('forumintro', 'assignsubmission_reflection', $obj);
        $forum->section = $data->section;
        $forum->coursemodule = $coursemodule;
        $forum->forcesubscribe = 1; // Auto subscription.
        $forum->maxbytes = 512000;
        $forum->maxattachments = 1;
        $forum->cmidnumber = $data->cmidnumber; // Not sure why it is needed, but this prevents errors.
        $forumid = forum_add_instance($forum);

        // Configure the newly created module to be assosicated with the newly created forum.
        $DB->set_field('course_modules', 'instance', $forumid, array('id'=>$coursemodule));

        // !!!remove as much as we can if forum has not been created.... - todo!!!

        // course_modules and course_sections each contain a reference
        // to each other, so we have to update one of them twice.
        $sectionid = course_add_cm_to_section($COURSE, $coursemodule, $data->section);
        // make sure visibility is set correctly (in particular in calendar)
        // note: allow them to set it even without moodle/course:activityvisibility
        set_coursemodule_visible($coursemodule, $data->visible);

        // Create a grouping. Control variable idnumber = forumid.
        $grouping = new stdClass();
        $grouping->name = $data->name.' '.get_string('grouping','group');
        $grouping->courseid = $COURSE->id;
        $grouping->idnumber = $forumid;
        $grouping->description = "Reflection grouping";
        $grouping->id = groups_create_grouping($grouping);

        // Configure the newly created forum to be associated with the newly created grouping.
        $DB->set_field('course_modules', 'groupingid', $grouping->id, array('id'=>$coursemodule));

        // Create a group for waiting students
        $group = new stdClass();
        $group->courseid = $COURSE->id;
        $group->name = 'Waiting group for '. $data->name;
        $group->description = "Reflection activity waiting group";
        $group->idnumber = $forumid;
        $group->id = groups_create_group($group);

        return $forumid;
    }

    /**
     * Here the action is to be performed.
     * 
     * @param mixed $submission stdClass|null
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @return bool
     */
    public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data) {
        global $CFG, $DB, $COURSE, $USER;
        require_once($CFG->dirroot.'/group/lib.php');

        $cmid = required_param('id', PARAM_INT);
        $forumid = $this->get_config('forumid');
        $discussionopened = $DB->get_record('forum_discussions', array('userid' => $USER->id, 'forum' => $forumid));
        $groupingid = $DB->get_field('groupings', 'id', array('idnumber' => $forumid));
/*
        $sql = "SELECT * FROM {groups_members} gm 
            INNER JOIN {groups} g ON gm.groupid = g.id 
            INNER JOIN {groupings_groups} gg ON gg.groupid = g.id WHERE 
            gg.groupingid = " . $groupingid . " AND gm.userid = " . $USER->id;

        $userisingroup = $DB->get_records_sql($sql);

        if (!$submission && !$userisingroup) {
            $groupsize = $this->get_config('students');
            $lastgroupid = $DB->get_field('groupings_groups', 'groupid', array('groupingid' => $groupingid));
            
            if ($lastgroupid) {
                $memberslastgroup = $DB->count_records('groups_members', array('groupid' => $lastgroupid));
            } else {
                $memberslastgroup = 0;
            }

            if ($memberslastgroup == 0 || $memberslastgroup >= $groupsize) {
                // Create a new group.
                $timenow = time();
                $group = new stdClass();
                $group->name = get_string('pluginname', 'assignsubmission_reflection').get_string('group','group').date("ymdHis", $timenow);
                $group->courseid = $COURSE->id;
                $group->description = "Reflection activity group";
                $group->id = groups_create_group($group);
                groups_assign_grouping($groupingid, $group->id);
                // Add a student to a group.
                if (!groups_add_member($group->id, $USER->id)) {
                    print_error('erroraddremoveuser', 'group');
                }
            } else {
                // Add a student to a group.
                if (!groups_add_member($lastgroupid, $USER->id)) {
                    print_error('erroraddremoveuser', 'group');
                }
            }
        }

        if ($discussionopened) {
            $forumcm = get_coursemodule_from_instance('forum', $forumid);
            $redirecturl = new moodle_url($CFG->wwwroot . '/mod/forum/view.php', array('id' => $forumcm->id));
        } else {
            $this->update_user_submission($USER->id);
            $redirecturl = new moodle_url($CFG->wwwroot . '/mod/forum/post.php', array('forum' => $forumid));
        }
*/
        $redirecturl = new moodle_url($CFG->wwwroot . '/mod/assign/submission/reflection/post.php', array(
            'id' => $cmid, 
            'forumid' => $forumid,
            'gid' => $groupingid));
        redirect($redirecturl);

        return true;
    }

    /**
     * Check if a user have a registered submission to an assignment.
     *
     * @param mixed $userid
     * @param mixed $assignment_instance
     * @return mixed False if no submission, else the submission record.
     */
    function user_have_registered_submission($userid, $assignment_instance) {
        global $DB;

        $submission = $DB->get_record('assign_submission', array(
            'assignment' => $assignment_instance,
            'userid' => $userid
        ));

        return $submission;
    }

    /**
     * Update the information about user's submission
     *
     * @param int $userid
     */
    function update_user_submission($userid) {
        global $DB;

        $cmid = required_param('id', PARAM_INT);
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);

        $existingsubmission = $this->user_have_registered_submission($userid, $cm->instance);
        $submission = $this->assignment->get_user_submission($userid, true);

        if ($existingsubmission) {
            $submission->timemodified = time();
            $DB->update_record('assign_submission', $submission);
        }
    }

    /**
     * Displays all posts and reflection comments for this assignment from a specified student.
     *
     * @param stdClass $submission
     * @return string
     */
    public function view(stdClass $submission) {
        global $CFG, $DB, $OUTPUT, $PAGE, $COURSE;
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $id         = required_param('id', PARAM_INT);
        $sid        = optional_param('sid', $submission->id, PARAM_INT);
        $gid        = optional_param('gid', 0, PARAM_INT);
        $cm         = get_coursemodule_from_id('assign', $id, 0, false, MUST_EXIST);
        $course     = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
        $forumid    = $this->get_config('forumid');
        $forum      = $DB->get_record('forum', array('id' => $forumid));
        $user       = $DB->get_record('user', array('id' => $submission->userid));

        list($entries, $comments) = $this->get_entries_and_comments($submission->userid, false);

        ob_start();
        echo $OUTPUT->heading(get_string('postsmadebyuser', 'forum', fullname($user)), 2);

        echo $OUTPUT->heading(get_string('pluginname', 'assignsubmission_reflection', 3));
        $post = forum_get_post_full(current($entries)->id);
        $discussion = $DB->get_record('forum_discussions', array('userid' => $submission->userid, 'forum' => $forumid));   
        forum_print_post($post, $discussion, $forum, $cm, $course);

        if (count($comments) > 0) {
            echo $OUTPUT->heading(get_string('comments'), 3);
            foreach ($comments as $comment) {
                $post = forum_get_post_full($comment->id);
                $discussion = $DB->get_record('forum_discussions', array('id' => $post->discussion));   
                forum_print_post($post, $discussion, $forum, $cm, $course);
            }
        }
        $result = ob_get_contents();
        ob_clean();

        return $result;
    }

    /**
     * Displays the summary of the submission
     *
     * @param stdClass $submission The submission to show a summary of
     * @param bool $showviewlink Will be set to true to enable the view link
     * @return string
     */

    public function view_summary(stdClass $submission, & $showviewlink) {
        global $DB;

        $showviewlink = true;
        $userid     = $submission->userid+0;
        $forumid    = $this->get_config('forumid');

        list($entries, $comments) = $this->get_entries_and_comments($submission->userid, true);
        $postmade = ($entries) ? get_string('yes') : get_string('no');
        $sql = "SELECT DISTINCT p.discussion FROM {forum_posts} p 
            INNER JOIN {forum_discussions} d 
            ON p.discussion = d.id WHERE
            forum = " . $forumid . " and p.userid = " . $userid;
        $reflecteddiscussions = $DB->get_records_sql($sql);
        $students = $this->get_config('students');
        $allpostsreflected = (count($reflecteddiscussions) == ($students)) ? 1 : 0;

        if ($allpostsreflected) {
            $divclass = 'submissionstatussubmitted';
        } else {
            $divclass = 'submissionstatus';
        }

        $result = html_writer::start_tag('div', array('class' => $divclass));
        $result .= get_string('postmade', 'assignsubmission_reflection') . $postmade;
        $result .= html_writer::empty_tag('br');
        $result .= get_string('comments', 'assignsubmission_reflection') . $comments;
        if (!$allpostsreflected) {
            $result .= html_writer::empty_tag('br');
            $result .= get_string('commentmissing', 'assignsubmission_reflection');
        }
        $result .= html_writer::end_tag('div');

        return $result;

    }


    /**
     * Fetches or counts (depending on the value of the parameter $countentries) all entries and comments that a specified user 
     * have submitted to this assignment.
     * 
     * @param int $userid
     * @param bool $countentries If true, the method returns a count of the number of entries and comments by the user. If false
     *     the method returns the entries and comments. Default value is false.
     * @return void
     */
    private function get_entries_and_comments($userid, $countentries = false) {
        global $DB, $COURSE, $USER;

        if ($countentries) {
            $selectstatement = 'SELECT COUNT(p.id) ';
        } else {
            $selectstatement = 'SELECT p.id ';
        }


        $entriesquery = $selectstatement.'FROM {forum_posts} p JOIN {forum_discussions} d ON d.id = p.discussion WHERE p.userid = ? '.
                        'AND d.forum = ? AND p.parent = 0';

        $commentsquery = $selectstatement.'FROM {forum_posts} p JOIN {forum_discussions} d ON d.id = p.discussion WHERE p.userid = ? '.
                        'AND d.forum = ? AND p.parent <> 0';

        if (!empty($this->assignment->get_instance()->preventlatesubmissions)) {
            $daterestriction = ' AND p.created BETWEEN '.$this->assignment->get_instance()->allowsubmissionsfromdate.
                               ' AND '.$this->assignment->get_instance()->duedate;
            $entriesquery  .= $daterestriction;
            $commentsquery .= $daterestriction;
        }

        if ($countentries) {
            $entries = $DB->count_records_sql($entriesquery, array($userid, $this->get_config('forumid')));
            $comments = $DB->count_records_sql($commentsquery, array($userid, $this->get_config('forumid')));
        } else {
            $entries = $DB->get_records_sql($entriesquery, array($userid, $this->get_config('forumid')));
            $comments = $DB->get_records_sql($commentsquery, array($userid, $this->get_config('forumid')));
        }

        return array($entries, $comments);
    }


    /**
     * Produce a list of files suitable for export that represents this submission
     * 
     * @param stdClass $submission
     * @param stdClass $user
     * @return array an array of files indexed by filename
     */
    public function get_files(stdClass $submission, stdClass $user) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $id         = required_param('id', PARAM_INT);
        $cm         = get_coursemodule_from_id('assign', $id, 0, false, MUST_EXIST);
        $course     = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
        $forumid    = $this->get_config('forumid');
        $forum      = $DB->get_record('forum', array('id' => $forumid));
        $user       = $DB->get_record('user', array('id' => $submission->userid));

        $files = array();
        list($entries, $comments) = $this->get_entries_and_comments($submission->userid);

        if ($entries) {
            $user = $DB->get_record('user', array(
                'id' => $submission->userid
            ), 'id, username, firstname, lastname', MUST_EXIST);

            $finaltext  = html_writer::start_tag('html');
            $finaltext .= html_writer::start_tag('head');
            $finaltext .= html_writer::start_tag('title');
            $finaltext .= get_string('postsmadebyuser', 'forum', fullname($user)) .' on '.$this->assignment->get_instance()->name;
            $finaltext .= html_writer::end_tag('title');
            $finaltext .= html_writer::empty_tag('meta', array(
                'http-equiv' => 'Content-Type',
                'content' => 'text/html; charset=utf-8'
            ));
            $finaltext .= html_writer::end_tag('head');
            $finaltext .= html_writer::start_tag('body');

            ob_start();
            echo html_writer::tag('h3', get_string('pluginname', 'assignsubmission_reflection', fullname($user)));
            $post = forum_get_post_full(current($entries)->id);
            $discussion = $DB->get_record('forum_discussions', array('userid' => $submission->userid, 'forum' => $forumid));   
            forum_print_post($post, $discussion, $forum, $cm, $course);

            if (count($comments) > 0) {
                echo html_writer::tag('h3', get_string('comments'));
                foreach ($comments as $comment) {
                    $post = forum_get_post_full($comment->id);
                    $discussion = $DB->get_record('forum_discussions', array('id' => $post->discussion));   
                    forum_print_post($post, $discussion, $forum, $cm, $course);
                }
            }
            $finaltext .= ob_get_contents();
            ob_clean();

            $finaltext .= html_writer::end_tag('body');
            $finaltext .= html_writer::end_tag('html');
            $files[get_string('reflectionfilename', 'assignsubmission_reflection')] = array($finaltext);
        }

        return $files;
    }

    /**
     * No text is set for this plugin
     *
     * @param stdClass $submission
     * @return bool
     */
    public function is_empty(stdClass $submission) {
        global $DB;

        $postsquery = 'SELECT p.id FROM {forum_posts} p JOIN {forum_discussions} d ON d.id = p.discussion WHERE p.userid = ? '.
                        'AND d.forum = ?';
        if (!empty($this->assignment->get_instance()->preventlatesubmissions)) {
            $daterestriction = ' AND p.created BETWEEN '.$this->assignment->get_instance()->allowsubmissionsfromdate.
                               ' AND '.$this->assignment->get_instance()->duedate;
            $postsquery  .= $daterestriction;
        }

        $posts = $DB->record_exists_sql($postsquery, array($submission->userid+0, $this->get_config('forumid')));

        return empty($posts);
    }  

}

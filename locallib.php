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
 * Local classes and functions for Meta MNet enrolment plugin.
 *
 * @package     enrol_metamnet
 * @author      Tim Butler
 * @copyright   2016 Harcourts International Limited {@link http://www.harcourtsacademy.com}
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/mnet/service/enrol/locallib.php');

/**
 * Event handler for Meta MNet enrolment plugin.
 *
 * We try to keep everything in sync via listening to events,
 * it may fail sometimes, so we always do a full sync in a
 * scheduled task too.
 */
class enrol_metamnet_handler {

    /**
     * Synchronise Meta MNet enrolments of this user in this course
     * 
     * @param int $courseid
     * @param int $userid
     * @return void
     */
    public function sync_course_instances($courseid, $userid) {
        
        $helper = new enrol_metamnet_helper();
        $helper->sync_user_in_course($courseid, $userid);
    }
}

/**
 * Helper for Meta MNet enrolment plugin.
 *
 */
class enrol_metamnet_helper {
    
    public $mnetservice;
    
    public function __construct() {
        $this->mnetservice = mnetservice_enrol::get_instance();
    }
    
    /**
     * Fetches updated course enrolments from remote courses
     *
     * @param int $hostid of MNet host
     * @param int $courseid of MNet course
     * @param bool $usecache true to force remote refresh
     * @return null
     */
    protected function check_cache($hostid, $courseid, $usecache = true) {
        $lastfetchenrolments = get_config('mnetservice_enrol', 'lastfetchenrolments');

        if (!$usecache or empty($lastfetchenrolments) or (time()-$lastfetchenrolments > 600)) {
            // fetch fresh data from remote if forced or every 10 minutes
            $usecache = false;
            $result = $this->mnetservice->req_course_enrolments($hostid, $courseid, $usecache);
            if ($result !== true) {
                error_log($this->mnetservice->format_error_message($result));
            }
        }

        return;
    }
    
    /**
     * Get all **non-metamnet** enrolment ids from an array of enrolment instances
     *
     * @param stdClass[] array of all enrolment instances
     * @return stdClass[]|null array of all enrolment instance ids
     */
    protected function filter_enrolment_ids($courseenrolmentinstances) {
        $enrolmentids = array();

        foreach ($courseenrolmentinstances as $instance) {
            // skip metamnet enrolment instances
            if ($instance->enrol == 'metamnet') {
                continue;
            }
            // avoid duplicates
            $enrolmentids[$instance->id] = $instance->id;
        }

        return $enrolmentids;
    }

    /**
     * Get all metamnet enrolment instances for a course
     *
     * @param stdClass[] $courseenrolmentinstances
     * @return stdClass[]|null array of metamnet enrolment instances
     */
    protected function filter_metamnet_enrolment_instances($courseenrolmentinstances) {
        $metamnetenrolmentinstances = array();

        foreach ($courseenrolmentinstances as $instance) {
            if ($instance->enrol == 'metamnet' and $instance->status == ENROL_INSTANCE_ENABLED) {
                $metamnetenrolmentinstances[] = $instance;
            }
        }

        return $metamnetenrolmentinstances;
    }
    
    /**
     * Get all active metamnet enrolment instances for all courses
     *
     * @return stdClass[]|null array of all enrolment instances for all courses
     */
    protected function get_all_metamnet_enrolment_instances() {
        global $DB;
        return $DB->get_records('enrol', array('enrol'=>'metamnet', 'status'=>ENROL_INSTANCE_ENABLED), '', '*');
    }

    /**
     * Get an enrolment instances from the id
     *
     * @param int $enrolid the enrolment id
     * @return stdClass|null the enrolment instance
     */
    protected function get_enrolment_instance($enrolid) {
        global $DB;
        return $DB->get_record('enrol', array('id'=>$enrolid), '*');
    }
    
    /**
     * Get all enrolment instances for a course
     *
     * @param int $courseid one course id, empty mean all
     * @return stdClass[]|null array of all enrolment instances for the course(s)
     */
    protected function get_enrolment_instances($courseid) {
        global $DB;
        return $DB->get_records('enrol', array('courseid'=>$courseid), '', '*');
    }
    
    /**
     * Get all userid for locally enrolled users not enrolled remotely
     *
     * @param stdClass[] $localuserenrolments array of user_enrolment objects
     * @param stdClass[] $remoteuserenrolments array of mnetservice_enrol_enrolments objects
     * @return stdClass[]|null array of all local user enrolment objects not enrolled remotely
     */
    protected function get_local_users_to_enrol($localuserenrolments, $remoteuserenrolments) {
        return array_udiff($localuserenrolments, $remoteuserenrolments, 'compare_by_userid');
    }

    /**
     * Get a remote MNet course
     *
     * @param int $hostid the MNet host id
     * @param int $courseid the MNet course id
     * @return stdClass|null the remote course
     */
    protected function get_remote_course($hostid, $courseid) {
        global $DB;
        return $DB->get_record('mnetservice_enrol_courses', array('remoteid'=>$courseid, 'hostid'=>$hostid), '*', MUST_EXIST);
    }
    
    /**
     * Get remote MNet course enrolments
     *
     * @param int $mnetcourseid of the remote course in the mnetservice_enrol_courses table
     * @return stdClass[]|null remote enrolments
     */
    protected function get_remote_course_enrolments($mnetcourseid) {
        global $DB;
        
        $remotecourses = $this->get_remote_host_and_course_ids($mnetcourseid);
        $this->check_cache($remotecourses->hostid, $remotecourses->remoteid);
        
        
        return $DB->get_records('mnetservice_enrol_enrolments', array(
                                        'hostid'=>$remotecourses->hostid,
                                        'remotecourseid'=>$remotecourses->remoteid),
                                '', '*');
    }

    /**
    * Get the remote host and course ids
    *
    * @param int $mnetcourseid of the remote course in the mnetservice_enrol_courses table
    * @return int[]|null array containing the remote host and course ids
    */
    protected function get_remote_host_and_course_ids($mnetcourseid) {
        global $DB;
        return $DB->get_record('mnetservice_enrol_courses', array('id'=>$mnetcourseid),'hostid,remoteid', MUST_EXIST);
    }
    
    protected function get_remote_users_to_unenrol() {
        
    }

    /**
     * Get all user enrolments for a single user or all users
     *
     * @param int[] $enrolmentinstanceids array of enrolment instance ids
     * @param int $userid
     * @return stdClass[]|null array of all user enrolments
     */
    protected function get_user_enrolments($enrolmentinstanceids, $userid = null) {
        global $DB;
        
        if (!empty($userid)) {
            $sql = "SELECT *
                    FROM {user_enrolments} ue
                    WHERE ue.enrolid in (:enrolids)
                      AND ue.userid = :userid
                      AND ue.status = :status";
            $params = array('enrolids'=>implode(',', $enrolmentinstanceids),
                            'userid'=>$userid,
                            'status'=>ENROL_USER_ACTIVE
                            );
        } else {
            $sql = "SELECT *
                    FROM {user_enrolments} ue
                    WHERE ue.enrolid in (:enrolids)
                      AND ue.status = :status";
            $params = array('enrolids'=>implode(',', $enrolmentinstanceids),
                            'status'=>ENROL_USER_ACTIVE
                            );
        }

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Enrol the user(s) in the remote MNet course
     *
     * @param int[] $enroluserids
     * @param int $mnetcourseid id of the course (mnetservice_enrol_courses)
     * @return bool true if successful
     */
    protected function remote_enrol($enroluserids, $mnetcourseid) {
        global $DB;
        
        error_log('Remote enrolling $enroluserids from ' . $mnetcourseid . ': ' . print_r($enroluserids, true));

        $mnetserviceenrolcourses = $this->get_remote_host_and_course_ids($mnetcourseid);
        error_log('$mnetserviceenrolcourses: ' . print_r($mnetserviceenrolcourses, true));

        $this->check_cache($mnetserviceenrolcourses->hostid, $mnetserviceenrolcourses->remoteid);

        $remotecourse = $this->get_remote_course($mnetserviceenrolcourses->hostid,
                                          $mnetserviceenrolcourses->remoteid);

        error_log('$remotecourse: ' . print_r($remotecourse, true));

        foreach($enroluserids as $enroluser) {
            $user = $DB->get_record('user', array('id'=>$enroluser), '*', MUST_EXIST);
            $result = $this->mnetservice->req_enrol_user($user, $remotecourse);
            if ($result !== true) {
                error_log($this->mnetservice->format_error_message($result));
            }
        }
    }

    /**
     * Unenrol the user(s) in the remote MNet course
     *
     * @param int[] $unenroluserids
     * @param int $mnetcourseid id of the course (mnetservice_enrol_courses)
     * @return bool true if successful
     */
    protected function remote_unenrol($unenroluserids, $mnetcourseid) {
        global $DB;
        
        error_log('Remote un-enrolling $unenroluserids from ' . $mnetcourseid . ': ' . print_r($unenroluserids, true));

        $mnetserviceenrolcourses = $this->get_remote_host_and_course_ids($mnetcourseid);
        error_log('$mnetserviceenrolcourses: ' . print_r($mnetserviceenrolcourses, true));

        $this->check_cache($mnetserviceenrolcourses->hostid, $mnetserviceenrolcourses->remoteid);

        $remotecourse = $this->get_remote_course($mnetserviceenrolcourses->hostid,
                                          $mnetserviceenrolcourses->remoteid);

        error_log('$remotecourse: ' . print_r($remotecourse, true));

        foreach($unenroluserids as $unenroluser) {
            $user = $DB->get_record('user', array('id'=>$unenroluser), '*', MUST_EXIST);
            $result = $this->mnetservice->req_unenrol_user($user, $remotecourse);
            if ($result !== true) {
                error_log($this->mnetservice->format_error_message($result));
            }
        }

    }
    
    /**
     * Sync one meta mnet enrolment instance.
     *
     * @param stdClass $enrolinstance one enrolment instance
     * @return true on success
     */
    public function sync_instance($enrolinstance) {
        global $DB;
        
        if (!enrol_is_enabled('metamnet')) {
            // Ignore if the plugin is disabled.
            return false;
        }

        // Get all enrolment instances for the course
        $courseenrolmentinstances = $this->get_enrolment_instances($enrolinstance->courseid);
        
        $enrolmentinstanceids = $this->filter_enrolment_ids($courseenrolmentinstances);
        error_log('$enrolment_instance_ids: ' . print_r($enrolmentinstanceids, true));
        
        // Get active (non-metamnet) user enrolments for all users
        $userenrolments = $this->get_user_enrolments($enrolmentinstanceids);
        error_log('$userenrolments: ' . print_r($userenrolments, true));
        
        // Get remote cached remote enrolments
        $remoteenrolments = $this->get_remote_course_enrolments($enrolinstance->customint1);
        
        error_log('$remoteenrolments: ' . print_r($remoteenrolments, true));
        
        $addusers = $this->get_local_users_to_enrol($userenrolments, $remoteenrolments);
        
        
//        if (empty($userenrolments)) {
//            // unenrol the user from all metamnet enrolled courses
//            foreach ($metamnetenrolinstances as $metamnetinstance) {
//                $this->remote_unenrol(array($userid), $metamnetinstance->customint1);
//            }
//        } else {
//            // enrol the user from all metamnet enrolled courses
//            foreach ($metamnetenrolinstances as $metamnetinstance) {
//                $this->remote_enrol(array($userid), $metamnetinstance->customint1);
//            }
//        }

        return true;

    }
    
    /**
     * Sync all meta mnet enrolment instances.
     *
     * @param int $enrolid one enrolment id, empty mean all
     * @return int 0 means ok, 1 means error, 2 means plugin disabled
     */
    public function sync_instances($enrolid = NULL) {

        if (!enrol_is_enabled('metamnet')) {
            // Ignore if the plugin is disabled.
            return 2;
        }

        if (empty($enrolid)) {
            $allinstances = $this->get_all_metamnet_enrolment_instances();
        } else {
            $allinstances = $this->get_enrolment_instance($enrolid);
        }
        
        foreach ($allinstances as $instance) {
            $this->sync_instance($instance);
        }

        return 0;

    }
    
    /**
     * Sync a user in a course with a remote mnet course
     *
     * @param int $courseid of the local course
     * @param int $userid of the local user
     * @return null
     */
    public function sync_user_in_course($courseid, $userid) {
        // Get all enrolment instances for the course
        $courseenrolmentinstances = $this->get_enrolment_instances($courseid);
        $metamnetenrolinstances = $this->filter_metamnet_enrolment_instances($courseenrolmentinstances);
        if (empty($metamnetenrolinstances)) {
            // Skip if there are no metamnet enrolment instances
            return;
        }
        
        $enrolmentinstanceids = $this->filter_enrolment_ids($courseenrolmentinstances);
        error_log('$enrolment_instance_ids: ' . print_r($enrolmentinstanceids, true));
        
        // Get active (non-metamnet) user enrolments for the user in the course
        $userenrolments = $this->get_user_enrolments($enrolmentinstanceids, $userid);
        
        if (empty($userenrolments)) {
            // unenrol the user from all metamnet enrolled courses
            foreach ($metamnetenrolinstances as $metamnetinstance) {
                $this->remote_unenrol(array($userid), $metamnetinstance->customint1);
            }
        } else {
            // enrol the user from all metamnet enrolled courses
            foreach ($metamnetenrolinstances as $metamnetinstance) {
                $this->remote_enrol(array($userid), $metamnetinstance->customint1);
            }
        }
    }
}

/**
 * Compare function for arrays of user enrolments objects
 *
 * @param stdClass[] $a array of user_enrolments or mnetservice_enrol_enrolments objects
 * @param stdClass[] $b array of user_enrolments or mnetservice_enrol_enrolments objects
 * @return int
 */
function compare_by_userid($a, $b) {
    if ($a->userid < $b->userid) {
        return -1;
    } elseif ($a->userid > $b->userid) {
        return 1;
    } else {
        return 0;
    }
}

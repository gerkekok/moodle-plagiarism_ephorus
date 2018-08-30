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
 * Privacy Subsystem implementation for plagiarism_ephorus.
 *
 * @package    plagiarism_ephorus
 * @copyright  2018 John McGettrick <jmcgettrick@turnitin.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_ephorus\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\writer;

class provider implements
    // This plugin does store personal user data.
    \core_privacy\local\metadata\provider,
    \core_plagiarism\privacy\plagiarism_provider {

    // This trait must be included to provide the relevant polyfill for the metadata provider.
    use \core_privacy\local\legacy_polyfill;

    // This trait must be included to provide the relevant polyfill for the plagiarism provider.
    use \core_plagiarism\privacy\legacy_polyfill;

    /**
     * Return the fields which contain personal data.
     *
     * @param $collection collection a reference to the collection to use to store the metadata.
     * @return $collection the updated collection of metadata items.
     */
    public static function _get_metadata(collection $collection) {

        $collection->add_database_table(
            'plagiarism_eph_document',
            [
                'guid' => 'privacy:metadata:plagiarism_eph_document:guid',
                'filename' => 'privacy:metadata:plagiarism_eph_document:filename',
                'contenthash' => 'privacy:metadata:plagiarism_eph_document:contenthash',
                'student_name' => 'privacy:metadata:plagiarism_eph_document:student_name',
                'student_number' => 'privacy:metadata:plagiarism_eph_document:student_number',
                'date_created' => 'privacy:metadata:plagiarism_eph_document:date_created',
                'percentage' => 'privacy:metadata:plagiarism_eph_document:percentage',
                'duplicate_guid' => 'privacy:metadata:plagiarism_eph_document:duplicate_guid',
                'duplicate_student_name' => 'privacy:metadata:plagiarism_eph_document:duplicate_student_name',
                'duplicate_student_number' => 'privacy:metadata:plagiarism_eph_document:duplicate_student_number',
                'summary' => 'privacy:metadata:plagiarism_eph_document:summary',
                'submission' => 'privacy:metadata:plagiarism_eph_document:submission'
            ],
            'privacy:metadata:plagiarism_eph_document'
        );

        $collection->add_database_table(
            'plagiarism_eph_result',
            [
                'document_guid' => 'privacy:metadata:plagiarism_eph_result:document_guid',
                'original_guid' => 'privacy:metadata:plagiarism_eph_result:original_guid',
                'percentage' => 'privacy:metadata:plagiarism_eph_result:percentage',
                'student_name' => 'privacy:metadata:plagiarism_eph_result:student_name',
                'student_number' => 'privacy:metadata:plagiarism_eph_result:student_number'
            ],
            'privacy:metadata:plagiarism_eph_result'
        );

        $collection->link_external_location('plagiarism_eph_client', [
            'firstname' => 'privacy:metadata:plagiarism_eph_client:firstname',
            'lastname' => 'privacy:metadata:plagiarism_eph_client:lastname',
            'email' => 'privacy:metadata:plagiarism_eph_client:email',
            'id' => 'privacy:metadata:plagiarism_eph_client:id',
            'file' => 'privacy:metadata:plagiarism_eph_client:file',
            'filename' => 'privacy:metadata:plagiarism_eph_client:filename'
        ], 'privacy:metadata:plagiarism_eph_client');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid the userid.
     * @return contextlist the list of contexts containing user info for the user.
     */
    public static function _get_contexts_for_userid($userid) {

        $params = ['modulename' => 'assign',
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid];

        $sql = "SELECT ctx.id
                FROM {course_modules} cm
                JOIN {modules} m ON cm.module = m.id AND m.name = :modulename
                JOIN {assign} a ON cm.instance = a.id
                JOIN {context} ctx ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                LEFT JOIN {assign_submission} asub ON asub.assignment = cm.instance
                LEFT JOIN {plagiarism_eph_document} ed ON ed.submission = asub.id
                WHERE asub.userid = :userid";

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }


    /**
     * Export all plagiarism data from each plagiarism plugin for the specified userid and context.
     *
     * @param   int         $userid The user to export.
     * @param   \context    $context The context to export.
     * @param   array       $subcontext The subcontext within the context to export this information to.
     * @param   array       $linkarray The weird and wonderful link array used to display information for a specific item
     */
    public static function _export_plagiarism_user_data($userid, \context $context, array $subcontext, array $linkarray) {
        global $DB;

        if (empty($userid)) {
            return;
        }

        $user = $DB->get_record('user', array('id' => $userid));

        $params = ['userid' => $user->id];

        $sql = "SELECT ed.id, ed.guid, ed.filename, ed.contenthash, ed.student_name, ed.student_number, 
                ed.date_created, ed.percentage, ed.summary, ed.submission
                FROM {plagiarism_eph_document} ed
                LEFT JOIN {files} f ON ed.fileid = f.id 
                WHERE f.userid = :userid";
        $submissions = $DB->get_records_sql($sql, $params);

        foreach ($submissions as $submission) {
            $cm = $DB->get_record_sql("SELECT cm.* FROM {course_modules} cm
            LEFT JOIN {modules} modu ON modu.id = cm.module
            LEFT JOIN {assign_submission} sub ON sub.assignment = cm.instance
            LEFT JOIN {plagiarism_eph_document} ed ON ed.submission = sub.id 
            WHERE modu.name = 'assign' AND ed.guid = ? AND ed.submission = ?", array($submission->guid, $submission->submission));

            $context = \context_module::instance($cm->id);
            self::_export_plagiarism_ephorus_data_for_user((array)$submission, $context, $user);
        }
    }

    /**
     * Export the supplied personal data for a single activity, along with any generic data or area files.
     *
     * @param array $submissiondata the personal data to export.
     * @param \context_module $context the module context.
     * @param \stdClass $user the user record
     */
    protected static function _export_plagiarism_ephorus_data_for_user(array $submissiondata, \context_module $context, \stdClass $user) {
        // Fetch the generic module data.
        $contextdata = helper::get_context_data($context, $user);

        // Merge with module data and write it.
        $contextdata = (object)array_merge((array)$contextdata, $submissiondata);
        writer::with_context($context)->export_data([], $contextdata);

        // Write generic module intro files.
        helper::export_context_files($context, $user);
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context the context to delete in.
     */
    public static function _delete_plagiarism_for_context(\context $context) {
        global $DB;

        if (empty($context)) {
            return;
        }

        if (!$context instanceof \context_module) {
            return;
        }

        $instanceid = $DB->get_field('course_modules', 'instance', ['id' => $context->instanceid], MUST_EXIST);

        $cm = get_coursemodule_from_instance('assign', $instanceid);

        // Get all submissions in context.
        $submissions = $DB->get_records('{assign_submission}', ['assignment' => $cm->id]);
        $submissions = array_keys($submissions);

        list($insql, $inparams) = $DB->get_in_or_equal($submissions);

        // Delete all submissions.
        $DB->delete_records_select('plagiarism_eph_document', 'submission '.$insql, $inparams);
    }

    /**
     * Delete all user information for the provided user and context.
     *
     * @param  int      $userid    The user to delete
     * @param  \context $context   The context to refine the deletion.
     */
    public static function _delete_plagiarism_for_user($userid, \context $context) {
        global $DB;

        $DB->delete_records('plagiarism_eph_document', ['student_number' => $userid]);
    }
}
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
 * DB connect class of the external database of logs.
 *
 * Defines the db connection used by fn_mentor
 *
 * @package    block_fn_mentor
 * @author     Sheilla Rindahl <srindahl@erdc.k12.mn.us>
 * @copyright  2016 cmERDC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die;


class Database {
/**
 * Here is the Moodle external database connection functions
 * using the built in tools in Moodle.
 */
 
    private $dbtype = "mysqli";
	private $dbhost = "localhost";
    private $dbname = "DB_NAME";
    private $dbuser = "DB_USER";
    private $dbpass = "DB_PASSWORD";
	private $tablesession = "TABLE_NAME";
	private $dbencoding = "utf-8";
	private $dbsetupsql = "SET NAMES 'utf8'";
	private $dbdebug = false;
	private $dbsybasequoting = false;
	public $conn;
	
	/**
     * Tries to make connection to the external database.
     *
     * @return null|ADONewConnection
     */
    protected function db_init() {
        global $CFG;

        require_once($CFG->libdir.'/adodb/adodb.inc.php');

        // Connect to the external database (forcing new connection).
        $extdb = ADONewConnection($this->dbtype);
        if ($this->dbdebug) {
            $extdb->debug = true;
            ob_start(); // Start output buffer to allow later use of the page headers.
        }

        // The dbtype my contain the new connection URL, so make sure we are not connected yet.
        if (!$extdb->IsConnected()) {
            $result = $extdb->Connect($this->dbhost, $this->dbuser, $this->dbpass, $this->dbname, true);
            if (!$result) {
                return null;
            }
        }

        $extdb->SetFetchMode(ADODB_FETCH_ASSOC);
        if ($this->dbsetupsql) {
            $extdb->Execute($this->dbsetupsql);
        }
        return $extdb;
    }

    protected function db_addslashes($text) {
        // Use custom made function for now - it is better to not rely on adodb or php defaults.
        if ($this->dbsybasequoting) {
            $text = str_replace('\\', '\\\\', $text);
            $text = str_replace(array('\'', '"', "\0"), array('\\\'', '\\"', '\\0'), $text);
        } else {
            $text = str_replace("'", "''", $text);
        }
        return $text;
    }

    protected function db_encode($text) {
        $dbenc = $this->dbencoding;
        if (empty($dbenc) or $dbenc == 'utf-8') {
            return $text;
        }
        if (is_array($text)) {
            foreach($text as $k=>$value) {
                $text[$k] = $this->db_encode($value);
            }
            return $text;
        } else {
            return core_text::convert($text, 'utf-8', $dbenc);
        }
    }

    protected function db_decode($text) {
        $dbenc = $this->dbencoding;
        if (empty($dbenc) or $dbenc == 'utf-8') {
            return $text;
        }
        if (is_array($text)) {
            foreach($text as $k=>$value) {
                $text[$k] = $this->db_decode($value);
            }
            return $text;
        } else {
            return core_text::convert($text, $dbenc, 'utf-8');
        }
    }
	
	function db_get_sql($table, array $conditions, array $fields, $distinct = false, $sort = "") {
        $fields = $fields ? implode(',', $fields) : "*";
        $where = array();
        if ($conditions) {
            foreach ($conditions as $key=>$value) {
                $value = $this->db_encode($this->db_addslashes($value));

                $where[] = "$key = '$value'";
            }
        }
        $where = $where ? "WHERE ".implode(" AND ", $where) : "";
        $sort = $sort ? "ORDER BY $sort" : "";
        $distinct = $distinct ? "DISTINCT" : "";
        $sql = "SELECT $distinct $fields
                  FROM $table
                 $where
                  $sort";

        return $sql;
    }
	
	/* Add external database connection for
	 * displaying the session times for courses.
	*/
	function get_extdb_sessions($enrolledcourse) {
		global $CFG, $OUTPUT;
		// /classes/ext_db/db_connect.php needed.
		$table = "session_times";
		$conditions = array("course" => $enrolledcourse,);
		$fields = array("meeting");
		$adodb = $this->db_init();
		if (!$adodb or !$adodb->IsConnected()) {
            $this->config->debugdb = $olddebugdb;
            $CFG->debug = $olddebug;
            ini_set('display_errors', $olddisplay);
            error_reporting($CFG->debug);
            ob_end_flush();

            echo $OUTPUT->notification('Cannot connect the database.', 'notifyproblem');
            return;
        }
		$sql = $this->db_get_sql($table, $conditions, $fields);
		if (!empty($enrolledcourse)) {
            $rs = $adodb->Execute($sql);
            if (!$rs) {
                echo $OUTPUT->notification('Can not read external enrol table.', 'notifyproblem');

            } else if ($rs->EOF) {
				$session = false;
                $rs->Close();

            } else {
                $session = $rs->FetchRow();
                $rs->Close();
            }
        }
		$adodb->Close();
		return $session;
	}
	
}

<?php
/*
 * Virtual/SQL driver
 *
 * @package	plugins
 * @uses	rcube_plugin
 * @author	Jasper Slits <jaspersl at gmail dot com>
 * @version	1.9
 * @license     GPL
 * @link	https://sourceforge.net/projects/rcubevacation/
 * @todo	See README.TXT
 */

class Virtual extends VacationDriver {

    private $db, $domain, $domain_id, $goto = "";
    private $db_user;

    /**
     * Initialize the DB connection
     */
    public function init() {

        // Use the DSN from db.inc.php or a dedicated DSN defined in config.ini
        if (empty($this->cfg['dsn'])) {
            $this->db = $this->rcmail->db;
            if (!class_exists('rcube_db')) {
                $dsn = MDB2::parseDSN($this->rcmail->config->get('db_dsnw'));
            } else {
                $dsn = rcube_db::parse_dsn($this->rcmail->config->get('db_dsnw'));
            }
        } else {
            if(!class_exists('rcube_db')) {
                $this->db = new rcube_mdb2($this->cfg['dsn'], '', FALSE);
                $dsn = MDB2::parseDSN($this->cfg['dsn']);
            } else {
                $this->db = rcube_db::factory($this->cfg['dsn'], '', FALSE);
                $dsn = rcube_db::parse_dsn($this->cfg['dsn']);
            }
            $this->db->db_connect('w');

            $this->db->set_debug((bool) $this->rcmail->config->get('sql_debug'));
            $this->db->set_debug(true);

        }
        // Save username for error handling
        $this->db_user = $dsn['username'];

        if (isset($this->cfg['createvacationconf']) && $this->cfg['createvacationconf']) {

            $this->createVirtualConfig($dsn);
        }
    }

    /**
     * Pull DB data for GET request on configuration form
     * @return array form data
     */
    public function _get() {
        $vacArr = array("subject"=>"", "body"=>"");
        //   print_r($vacArr);
        $fwdArr = $this->virtual_alias();

        $sql = sprintf("SELECT subject,body,active FROM %s.vacation WHERE email='%s'",
                $this->cfg['dbase'], Q($this->user->data['username']));

		
        $res = $this->db->query($sql);
        if ($error = $this->db->is_error()) {
            raise_error(array('code' => 601, 'type' => 'db', 'file' => __FILE__,
                        'message' => "Vacation plugin: query on {$this->cfg['dbase']}.vacation failed. Check DSN and verify that SELECT privileges on {$this->cfg['dbase']}.vacation are granted to user '{$this->db_user}'. <br/><br/>Error message:  " . $error), true, true);
        }



        if ($row = $this->db->fetch_assoc($res)) {
            $vacArr['body'] = $row['body'];
            $vacArr['subject'] = $row['subject'];
            $vacArr['enabled'] = ($row['active'] == 1) && ($fwdArr['enabled'] == 1);
        }


        return array_merge($fwdArr, $vacArr);
    }

    /*
	 * @return boolean True on succes, false on failure
    */
   
    /**
     * Store the vacation data in the DB
     * @return boolean status indicator of save
     */
    public function setVacation() {

        // Get the original vacation data
        $origVacArr = $this->_get();
        $origVacArr['active'] = ($origVacArr['active'] == 1);

        // Check for changes
        if ($origVacArr['subject'] != $this->subject ||
            $origVacArr['body'] != $this->body ||
            $origVacArr['active'] != $this->enable ||
            $origVacArr['keepcopy'] != $this->keepcopy) {
            
            // We store since version 1.6 all data into one row.
            $aliasArr = array();

            // Sets class property
            $this->domain_id = $this->domainLookup();

            $sql = sprintf("UPDATE %s.vacation SET created=now(),active=0 WHERE email='%s'", $this->cfg['dbase'], Q($this->user->data['username']));

            $this->db->query($sql);

            $update = ($this->db->affected_rows() == 1);

            // Delete the alias for the vacation transport (Postfix)
            $sql = $this->translate($this->cfg['delete_query']);

            $this->db->query($sql);
            if ($error = $this->db->is_error()) {
                if (strpos($error, "no such field")) {
                    $error = " Configure either domain_lookup_query or use %d in config.ini's delete_query rather than %i. <br/><br/>";
                }

                raise_error(array('code' => 601, 'type' => 'db', 'file' => __FILE__,
                            'message' => "Vacation plugin: Error while saving records to {$this->cfg['dbase']}.vacation table. <br/><br/>" . $error
                        ), true, true);

            }

            // Delete the entires for people already notified
            $sql = sprintf("DELETE FROM %s.vacation_notification WHERE on_vacation='%s'", $this->cfg['dbase'], Q($this->user->data['username']));

            $this->db->query($sql);
            if ($error = $this->db->is_error()) {
                raise_error(array('code' => 601, 'type' => 'db', 'file' => __FILE__,
                            'message' => "Vacation plugin: Error while deleting old notification records from {$this->cfg['dbase']}.vacation_notification table. <br/><br/>"
                        ), true, true);
            }


            if (!$update) {
                $sql = "INSERT INTO {$this->cfg['dbase']}.vacation  VALUES (?,?,?,'',?,NOW(),1)";
            } else {
                $sql = "UPDATE {$this->cfg['dbase']}.vacation SET email=?,subject=?,body=?,domain=?,active=? WHERE email=?";
            }

            // Set as active if user requested AND subject and body are nonempty
            if ($this->enable) {
                if ($this->body != "" && $this->subject != "") {
                    $enable_vacation = 1;
                } else {
                    // FIXME: Does this display to the user?  We want to display this in the saved message!
                    raise_error(array('code' => 601, 'type' => 'db', 'file' => __FILE__,
                                'message' => "Vacation plugin: Cannot activate vacation message if subject or body is blank! <br/><br/>"
                            ), true, true);
                }
            } else {
                $enable_vacation = 0;
            }

            $this->db->query($sql, Q($this->user->data['username']), $this->subject, $this->body, $this->domain, $enable_vacation, Q($this->user->data['username']));
            if ($error = $this->db->is_error()) {
                if (strpos($error, "no such field")) {
                    $error = " Configure either domain_lookup_query or use \%d in config.ini's insert_query rather than \%i<br/><br/>";
                }

                raise_error(array('code' => 601, 'type' => 'db', 'file' => __FILE__,
                            'message' => "Vacation plugin: Error while saving records to {$this->cfg['dbase']}.vacation table. <br/><br/>" . $error
                        ), true, true);
            }
            $aliasArr[] = '%g';


            // Keep a copy of the mail if explicitly asked for or when using vacation
            $always = (isset($this->cfg['always_keep_copy']) && $this->cfg['always_keep_copy']);
            if ($this->keepcopy || in_array('%g', $aliasArr) || $always) {
                $aliasArr[] = '%e';
            }

            // Set a forward
            if ($this->forward != null) {
                $aliasArr[] = '%f';
            }

            // Aliases are re-created if $sqlArr is not empty.
            $sql = $this->translate($this->cfg['delete_query']);
            $this->db->query($sql);

            // One row to store all aliases
            if (!empty($aliasArr)) {

                $alias = join(",", $aliasArr);
                $sql = str_replace('%g', $alias, $this->cfg['insert_query']);
                $sql = $this->translate($sql);

                $this->db->query($sql);
                if ($error = $this->db->is_error()) {
                    raise_error(array('code' => 601, 'type' => 'db', 'file' => __FILE__,
                                'message' => "Vacation plugin: Error while executing {$this->cfg['insert_query']} <br/><br/>" . $error
                            ), true, true);
                }
            }
        }
        return true;
    }

    /**
     * Translate the parameters in the passed SQL query with the proper parameters
     * @param  string $query Generic SQL query
     * @return string        Translated SQL query
     */
    private function translate($query) {
        return str_replace(array('%e', '%d', '%i', '%g', '%f', '%m'),
                array($this->user->data['username'], $this->domain, $this->domain_id,
                    Q($this->user->data['username']) . "@" . $this->cfg['transport'], $this->forward, $this->cfg['dbase']), $query);
    }

    /**
     * Determine the proper domain_id to use
     * @return string domain name to use
     */
    private function domainLookup() {
        // Sets the domain
        list($username, $this->domain) = explode("@", $this->user->get_username());
        if (!empty($this->cfg['domain_lookup_query'])) {
            $res = $this->db->query($this->translate($this->cfg['domain_lookup_query']));

            if (!$row= $this->db->fetch_array($res)) {
                raise_error(array('code' => 601, 'type' => 'db', 'file' => __FILE__,
                            'message' => "Vacation plugin: domain_lookup_query did not return any row. Check config.ini <br/><br/>" . $this->db->is_error()
                        ), true, true);

            }
            return $row[0];
        } else {
            return $this->domain;
        }
    }

    /**
     * Creates a configuration file for vacation.pl
     * @param  array  $dsn DB Connection information
     */
    private function createVirtualConfig(array $dsn) {

        $virtual_config = "/etc/postfixadmin/";
        if (!is_writeable($virtual_config)) {
            raise_error(array('code' => 601, 'type' => 'php', 'file' => __FILE__,
                        'message' => "Vacation plugin: Cannot create {$virtual_config}vacation.conf . Check permissions.<br/><br/>"
                    ), true, true);
        }

        // Fix for vacation.pl
        if ($dsn['phptype'] == 'pgsql') {
            $dsn['phptype'] = 'Pg';
        }

        $virtual_config .= "vacation.conf";
        // Only recreate vacation.conf if config.ini has been modified since
        if (!file_exists($virtual_config) || (filemtime("plugins/vacation/config.ini") > filemtime($virtual_config))) {
            $config = sprintf("
        \$db_type = '%s';
        \$db_username = '%s';
        \$db_password = '%s';
        \$db_name     = '%s';
        \$vacation_domain = '%s';", $dsn['phptype'], $dsn['username'], $dsn['password'], $this->cfg['dbase'], $this->cfg['transport']);
            file_put_contents($virtual_config, $config);
        }
    }

    /**
     * Retrieve the local copy and/or forward settings.
     * @return array local copy/forward settins
     */
    private function virtual_alias() {
        $forward = "";
        $enabled = false;
        $goto = Q($this->user->data['username']) . "@" . $this->cfg['transport'];

        // Backwards compatiblity. Since >=1.6 this is no longer needed
        $sql = str_replace("='%g'", "<>''", $this->cfg['select_query']);

        $res = $this->db->query($this->translate($sql));

        $rows = array();

        while (list($row) = $this->db->fetch_array($res)) {

            // Postfix accepts multiple aliases on 1 row as well as an alias per row
            if (strpos($row, ",") !== false) {
                $rows = explode(",", $row);
            } else {
                $rows[] = $row;
            }
        }



        foreach ($rows as $row) {
            // Source = destination means keep a local copy
            if ($row == $this->user->data['username']) {
                $keepcopy = true;
            } else {
                // Neither keepcopy or postfix transport means it's an a forward address
                if ($row == $goto) {
                    $enabled = true;
                } else {
                    // Support multi forwarding addresses
                    $forward .= $row . ",";
                }
            }

        }
        // Substr removes any trailing comma
        return array("forward"=>substr($forward, 0,  - 1), "keepcopy"=>$keepcopy, "enabled"=>$enabled);
    }

    /**
     * Destroy the database connection.
     */
    public function __destruct() {
        if (!empty($this->cfg['dsn']) && is_resource($this->db)) {
            $this->db = null;
        }
    }
}

?>

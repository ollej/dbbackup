<?php
/*
Copyright 2010 Olle Johansson. All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are
permitted provided that the following conditions are met:

   1. Redistributions of source code must retain the above copyright notice, this list of
      conditions and the following disclaimer.

   2. Redistributions in binary form must reproduce the above copyright notice, this list
      of conditions and the following disclaimer in the documentation and/or other materials
      provided with the distribution.

THIS SOFTWARE IS PROVIDED BY Olle Johansson ``AS IS'' AND ANY EXPRESS OR IMPLIED
WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND
FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL Olle Johansson OR
CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF
ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

The views and conclusions contained in the software and documentation are those of the
authors and should not be interpreted as representing official policies, either expressed
or implied, of Olle Johansson.

*/

/**
 * DbBackup Class
 */
class DbBackup {
    /** @var String $email Email address to send backup information to. */
    public $email = "";
    /** @var String $dbhost Database Hostname */
    public $dbhost = "";
    /** @var String $dbuser Database Username */
    public $dbuser = "";
    /** @var String $dbpass Database Password */
    public $dbpass = "";
    /** @var String $dbname Database Name */
    public $dbname = "";
    /** @var String $path Full server path to the directory where you want the backup files (no trailing slash) */
    public $path = "";
    /** @var String $password Password needed to send in as URL parameter in order to allow backup. */
    public $password = "";
    /** @var String $dateformat Date format to use in email */
    public $dateformat = "";
    /** @var int $compress Set to COMPRESS_GZIP or COMPRESS_BZIP for compression. */
    public $compress = 0;
    /** @var String $filenameformat How to name backup file, will be formatted with strftime and {DBNAME} will be replaced with $dbname */
    public $filenameformat = "{DBNAME}-%u.sql";
    /** @var String $filename Full path to the backup file. */
    private $filename = "";
    /** @var Boolean $authenticated Must be set to true to allow backup. */
    private $authenticated = false;
    /** @var int $timespent Time spent dumping database. */
    private $timespent = 0;
    
    /** @var int COMPRESS_GZIP Set $compress to this value for gzip compression. */
    const COMPRESS_GZIP = 1;
    /** @var int COMPRESS_BZIP Set $compress to this value for bzip2 compression. */
    const COMPRESS_BZIP = 2;
    
    /** @var Array $lang Language strings for translation. */
    private $lang = array(
        'all_done' => 'Backup has been finished.',
        'error_incorrect_password' => "Incorrect password.",
        'error_not_auth' => "Must be authenticated to make backup.",
        'error_no_file' => "Backup directory doesn't exist, please create it, or change the value: ",
        'error_not_writable' => "Can't write to backup directory, please change the directory permissions on directory: ",
        'error_not_dir' => "Path is a file, not a directory, please change the value: ",
        'error_no_filename' => "Must have a filename to dump database to.",
        'seconds' => 'seconds',
        'minutes' => 'minutes',
        'hours' => 'hours',
        'days' => 'days',
        'email_subject' => "Backup of database",
        'email_body' => "Backup of {DBNAME}:
Time of backup: {BACKUPTIME}
Backup took: {TIMESPENT}
Return code of dump command: {SUCCESS}
Filename of backup: {FILENAME}
Backup file size: {SIZE}",
    );
    
    public function __construct($dbuser, $dbpass, $dbname, $password="",
                                $path="/tmp", $dbhost="localhost", $email="",
                                $compress=0, $dateformat="Y-m-d H:i:s", $filenameformat=NULL) {
        $this->dbuser = $dbuser;
        $this->dbpass = $dbpass;
        $this->dbname = $dbname;
        $this->password = $password;
        $this->path = $path;
        $this->dbhost = $dbhost;
        $this->email = $email;
        $this->compress = $compress;
        $this->dateformat = $dateformat;
        if (isset($filenameformat)) {
            $this->filenameformat = $filenameformat;
        }
        
        # Set backup filename to contain the day of the month.
        $this->filename = $this->parseFilename($this->filenameformat);
        if (!$this->filename) {
            throw new Exception($this->lang['error_no_filename']);
        }
        $this->filename = $this->path . '/' . $this->filename;
        
        # Make sure we can save the backup file.
        if (!file_exists($this->path)) {
            throw new Exception($this->lang['error_no_file'] + $this->path);    
        }
        if (!is_writable($this->path)) {
            throw new Exception($this->lang['error_not_writable'] + $this->path);    
        }
        if (is_file($this->path)) {
            throw new Exception($this->lang['error_not_dir'] + $this->path);    
        }
    }
    
    /**
     * Authenticate with password to allow backup.
     * @param String $password Password to authenticate with.
     */
    public function authenticate($password) {
        if ($password != $this->password) {
            throw new Exception($this->lang['error_incorrect_password']);
        }
        $this->authenticated = true;
    }
    
    /**
     * Make a db backup and send info email, if emailaddress is set.
     */
    public function backup() {
        $result = $this->dumpDatabase();
        $size = $this->getFilesize($this->filename);
        $this->sendMail($result, $size);
        echo $this->lang['all_done'];
    }
    
    /**
     * Parses $filename using strftime() and replaces {DBNAME} with the name of the current db.
     * @param String $filename Filename format string to use as basis for created filename.
     * @return String Parsed filename.
     */
    private function parseFilename($filename) {
        $filename = strftime($filename);
        $filename = str_replace('{DBNAME}', $this->dbname, $filename);
        return $filename;
    }

    /**
     * Make the actual mysql dump.
     * @return int Return value of the mysqldump command. Should be 0 on success.
     */
    private function dumpDatabase() {
        if ($this->authenticated !== true) {
            throw new Exception($this->lang['error_not_auth']);
        }
        
        # Check if the backup file should be compressed.
        $compress = "";
        if ($this->compress === self::COMPRESS_GZIP) {
            $this->filename .= ".gz";
            $compress = "| gzip";
        } else if ($this->compress === self::COMPRESS_BZIP) {
            $this->filename .= ".bz2";
            $compress = "| bzip2";
        }
        
        # If the backup file already exists, delete it. Only keep latest month worth of files.
        if (file_exists($this->filename)) {
            unlink($this->filename);
        }
        
        # Make sure the script gets to run until it is finished.
        ini_set('max_execution_time', 0);
        
        # Run mysqldump program to do the actual dump.
        $cmd = "mysqldump --user=$this->dbuser --password=$this->dbpass --host=$this->dbhost $this->dbname $compress > $this->filename";
        $time_start = microtime(true);
        system($cmd, $result);
        $time_end = microtime(true);
        $this->timespent = $this->formatTime($time_end - $time_start, 0);
        
        return $result;
    }
    
    /**
     * Utility function to convert time in seconds to human readable format.
     * @param float $seconds Number of seconds to convert.
     * @param int $precision Number of decimal points to round value to.
     * @return String Readable time in seconds/minutes/hours/days
     * @todo Should preferrably convert to: 1 day 5 hours 2 minutes 34 seconds
     */
    private function formatTime($seconds, $precision=2) {
        $time = "";
        if ($seconds < 60) {
            $time = round($seconds, $precision) . ' ' . $this->lang['seconds'];
        } else if ($seconds < 3600) {
            $time = round($seconds / 60, $precision) . ' ' . $this->lang['minutes'];
        } else if ($seconds < 86400) {
            $time = round($size / 3600, $precision) . ' ' . $this->lang['hours'];
        } else {
            $time = round($size / 86400, $precision) . ' ' . $this->lang['days'];
        }
        return $time;
    }
    
    /**
     * Utility function to get size of a file in readable format.
     * @param String $filename Full path and name of file.
     * @param int $precision Number of decimal points to round value to.
     * @return String Readable filesize in bytes/KB/MB/GB/TB
     */
    private function getFilesize($filename, $precision=2) {
        $size = filesize($filename);
        if ($size < 1024) {
            $size = $size . " bytes";
        } else if ($size < 1048576) {
            $size = round($size / 1024, $precision) . " KB";
        } else if ($size < 1073741824) {
            $size = round($size / 1048576, $precision) . " MB";
        } else if ($size < 1099511627776) {
            $size = round($size / 1073741824, $precision) . " GB";
        } else {
            $size = round($size / 1099511627776, $precision) . " TB";
        }
        return $size;
    }
    
    /**
     * Send information email about the sql dump, if email address is configured.
     * @param int $success Success value of database dump.
     * @param string $size Size of created file.
     * @return int Return value of mail() function.
     */
    private function sendMail($success, $size) {
        if (!$this->email) {
            return;
        }
        $body = str_replace(array(
            '{DBNAME}',
            '{FILENAME}',
            '{SUCCESS}',
            '{SIZE}',
            '{BACKUPTIME}',
            '{TIMESPENT}',
        ), array(
            $this->dbname,
            $this->filename,
            $success,
            $size,
            date($this->dateformat),
            $this->timespent,
        ), 
        $this->lang['email_body']);
        return mail($this->email, $this->lang['email_subject'] , $body, "From: DbBackup <$this->email>");
    }
}

$backup = new DbBackup("dbuser", "dbpassword", "dbname", 'secret', "/server/path/to/backups", "localhost", "your_email_address@example.com");
$backup->authenticate($_GET['pass']);
$backup->backup();

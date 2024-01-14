<?php

/**
 * DokuWiki Plugin doxycode (Buildmanager Helper Component)
 * 
 * @license     GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */


if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

use \dokuwiki\Extension\Plugin;
use dokuwiki\plugin\sqlite\SQLiteDB;
use dokuwiki\ErrorHandler;

/**
 * Class helper_plugin_doxycode_buildmanager
 * 
 * This class manages the build of code snippets with doxygen for cross referencing.
 * 
 * Job: A build job is a single code snippet.
 * JobID: md5 of doxygen config + code.
 * Task: A build task has one or multiple build jobs.
 * TaskID: md5 of doxygen config.
 * 
 * The build is executed in build tasks that are either directly executed or scheduled with
 * the help of the sqlite plugin. If the sqlite plugin is not available, the scheduling fails.
 * Since the task is then not scheduled and no cache file is available, the snippet syntax
 * should then try to build the code snippet again.
 * 
 * Depending on the used tag files for doxygen, each build can both take long and be ressource
 * hungry. It therefore is only allowed to have one doxygen instance running at all times. This
 * is enforced with a lock that indicates if doxygen is running or not. In case of immediate build
 * tasks through tryBuildNow() the buildmanager will then try to schedule the build task.
 * 
 * If a build with the same TaskID is already running, a new TaskID will be randomly created.
 * This way we ensure that we don't mess with an already running task.
 * 
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */
class helper_plugin_doxycode_buildmanager extends Plugin {
    const STATE_NON_EXISTENT = 1;
    const STATE_RUNNING = 2;
    const STATE_SCHEDULED = 3;
    const STATE_FINISHED = 4;
    const STATE_ERROR = 5;

    /**
     * @var string Filename of the lock file for only allowing one doxygen process.
     */
    const LOCKFILENAME = '_plugin_doxycode.lock';

    protected $db = null;


    /**
     * @var array Allowed configuration strings that are relevant for doxygen.
     */
    private $conf_doxygen_keys = array(
        'tagfiles',
        'doxygen_conf',
        'language'
    );

    function __construct()
    {
        // check if sqlite is available
        if(!plugin_isdisabled('sqlite')) {
            if ($this->db === null) {
                try {
                    $this->db = new SQLiteDB('doxycode', DOKU_PLUGIN . 'doxycode/db/');
                } catch (\Exception $exception) {
                    if (defined('DOKU_UNITTEST')) throw new \RuntimeException('Could not load SQLite', 0, $exception);
                    ErrorHandler::logException($exception);
                    msg('Couldn\'t load sqlite.', -1);
                }
            }
        }
    }

    public function addBuildJob($jobID,&$config,$content) {
        if($this->db === null) {
            return false;
        }

        // TODO: is a race condition possible where we add a job to a task and during that operation the task runner start executing the task?

        // check if the Task is already running
        $row = $this->db->queryRecord('SELECT * FROM Tasks WHERE TaskID = ?', [$config['taskID']]);

        switch($row['State']) {
            case self::STATE_ERROR: // fall through
            case self::STATE_FINISHED: {
                // this means that the build directory probably was already deleted
                // we can just recreate the directory or put our job into the existing build directory
                $id = $this->db->exec('UPDATE Tasks SET Timestamp = CURRENT_TIMESTAMP, State = ? WHERE TaskID = ?',
                    [self::STATE_SCHEDULED, $config['taskID']]);
                break;
            }
            case self::STATE_SCHEDULED: {
                // just update the timestamp so we don't accidentally delete this task
                $id = $this->db->exec('UPDATE Tasks SET Timestamp = CURRENT_TIMESTAMP WHERE TaskID = ?', [$config['taskID']]);
                break;
            }
            case self::STATE_RUNNING: {
                // Generate an new TaskID
                $config['taskID'] = md5(microtime(true) . mt_rand());
            }
            case null;
            case '': {
                // we need to create a new task!
                $id = $this->db->exec('INSERT INTO Tasks (TaskID, State, Timestamp, Configuration) VALUES (?, ?, CURRENT_TIMESTAMP, ?)',
                    [$config['taskID'], self::STATE_SCHEDULED, json_encode($this->filterDoxygenAttributes($config,false),true)]);
                break;
            }
        }

        // create the job file with the code snippet content
        $tmp_dir = $this->_createJobFile($jobID,$config,$content);

        if(strlen($tmp_dir) == 0) {
            return false;
        }
        
        // create the job in sqlite

        $data = [
            'JobID' => $jobID,
            'TaskID' => $config['taskID'],
            'Configuration' => json_encode($this->filterDoxygenAttributes($config,true),true)
        ];

        $new = $this->db->saveRecord('Jobs', $data);

        return true;
    }

    public function tryBuildNow($jobID,&$config,$content,$tag_conf) {
        global $conf;

        // first try to detect if a doxygen instance is already running
        if(!$this->_lock()) {
            // we cannot build now because only one doxygen instance is allowed at a time!
            // this will return false if task runner is not available
            // otherwise it will create a task and a job
            return $this->addBuildJob($jobID,$conf,$content);
            
            $config['render_task'] = true;
        }

        // no doxygen instance is running - we can immediately build the file!

        // TODO: should we also create entries for Task and Job in sqlite if we directly build the snippet?

        // create the directory where rendering with doxygen takes place
        $tmp_dir = $this->_createJobFile($jobID,$config,$content);

        if(strlen($tmp_dir) == 0) {
            $this->_unlock();

            return false;
        }

        // run doxygen on our file with XML output
        $this->_runDoxygen($tmp_dir, $tag_conf);

        // delete tmp_dir
        if(!$conf['allowdebug']) {
            $this->_deleteTaskDir($tmp_dir);
        }


        $this->_unlock();

        return true;
    }

    public function getTaskState($id) {
        if($this->db === null) {
            // TODO: better return value?
            return self::STATE_NON_EXISTENT;
        }

        $row = $this->db->queryRecord('SELECT * FROM Tasks WHERE TaskID = ?', $id);

        if($row !== null) {
            return $row['State'];
        } else {
            return self::STATE_NON_EXISTENT;
        }
    }

    public function getJobState($jobID) {
        if($this->db === null) {
            // TODO: better return value?
            return self::STATE_NON_EXISTENT;
        }

        // get the TaskID from sqlite
        $row = $this->db->queryRecord('SELECT * FROM Jobs WHERE JobID = ?', $jobID);

        // check the task state and return as job state
        if($row !== null) {
            // TODO: can we directly retreive the Task from our reference in sqlite?
            return $this->getTaskState($row['TaskID']);
        } else {
            return self::STATE_NON_EXISTENT;
        }
    }

    public function getTaskConf($taskID) {
        if($this->db === null) {
            // TODO: better return value?
            return [];
        }

        $row = $this->db->queryRecord('SELECT Configuration FROM Tasks WHERE TaskID = ?', $taskID);

        if($row !== null) {
            return json_decode($row['Configuration'],true);
        } else {
            return [];
        }
    }

    public function getJobTaskConf($jobID) {
        if($this->db === null) {
            // TODO: better return value?
            return [];
        }

        // get the TaskID from sqlite
        $row = $this->db->queryRecord('SELECT * FROM Jobs WHERE JobID = ?', $jobID);

        // get the Configuration from the Task
        if($row !== null) {
            return $this->getTaskConf($row['TaskID']);
        } else {
            return [];
        }
    }

    public function getJobConf($jobID) {
        if($this->db === null) {
            // TODO: better return value?
            return [];
        }

        // get the TaskID from sqlite
        $row = $this->db->queryRecord('SELECT Configuration FROM Jobs WHERE JobID = ?', $jobID);

        if($row !== null) {
            return json_decode($row['Configuration'],true);
        } else {
            return [];
        }
    }

    private function _isRunning() {
        global $conf;

        $lock = $conf['lockdir'] . self::LOCKFILENAME;

        return file_exists($lock);
    }

    private function _lock() {
        global $conf;

        $lock = $conf['lockdir'] . self::LOCKFILENAME;

        if (file_exists($lock)) {
            if(time() - @filemtime($lock) > $this->getConf('')) {
                // looks like a stale lock - remove it
                unlink($lock);
            } else {
                return false;
            }
        }

        // try creating the lock file
        io_savefile($lock, "");

        return true;
    }

    private function _unlock() {
        global $conf;
        $lock = $conf['lockdir'] . self::LOCKFILENAME;
        return unlink($lock);
    }

    public function runTask($taskID) {
        global $conf;

        if(!$this->_lock()) {
            // a task is already running
            return false;
        }

        // get the config from sqlite!
        $row = $this->db->queryRecord('SELECT * FROM Tasks WHERE TaskID = ?', $taskID);

        // we only want to run if we have a scheduled job!
        if($row === null || $row['State'] === self::STATE_SCHEDULED) {
            $this->_unlock();
            return false;
        }

        $config = json_decode($row['Configuration'],true);


        /** @var helper_plugin_doxycode_tagmanager $tagmanager */
        $tagmanager = plugin_load('helper', 'doxycode_tagmanager');
        // load the tag_config from the tag file list
        if(!is_array($config['tagfiles'])) {
            $config['tagfiles'] = [$config['tagfiles']];
        }
        $tag_config = $tagmanager->getFilteredTagConfig($config['tagfiles']);

        // update the maximum execution time according to configuration
        // TODO: maybe check if this configuration is present?
        set_time_limit($this->getConf('runner_max_execution_time'));

        // this just returns the build dir if already existent
        $tmpDir = $this->_createTaskDir($taskID);

        // update the task state
        // we do not update the timestamp of the task here
        $this->db->exec('UPDATE Tasks SET State = ? WHERE TaskID = ?',
        [self::STATE_RUNNING, $taskID]);

        // execute doxygen and move cache files into position
        $success = $this->_runDoxygen($tmpDir,$tag_config);

        // update the task state
        if($success) {
            $this->db->exec('UPDATE Tasks SET State = ? WHERE TaskID = ?',
            [self::STATE_FINISHED, $taskID]);
        } else {
            $this->db->exec('UPDATE Tasks SET State = ? WHERE TaskID = ?',
            [self::STATE_ERROR, $taskID]);
        }


        // delete tmp_dir
        if(!$conf['allowdebug']) {
            $this->_deleteTaskDir($tmpDir);
        }

        $this->_unlock();

        return true;
    }


    private function _runDoxygen($build_dir, $tag_conf = null) {
        if(!is_dir($build_dir)) {
            // the directory does not exist
            return false;
        }

        // TODO: add plugin configuration for setting the path
        $doxygenExecutable = '/usr/bin/doxygen';

        // TODO: check if doxygen executable exists!

        // Path to your Doxygen configuration file
        // TODO: use default doxygen config or allow admin to upload a doxygen configuration that is not overwritten by plugin updates
        $doxygenConfig = DOKU_PLUGIN . $this->getPluginName() . '/doxygen.conf';


        /** @var helper_plugin_doxycode_tagmanager $tagmanager */
        $tagmanager = plugin_load('helper', 'doxycode_tagmanager');

        // TAGFILES variable value you want to set
        $tagfiles = '';
        $index = 0;
        foreach($tag_conf as $key => $conf) {
            if($index > 0) {
                $tagfiles .= ' ';
            }

            $tagfiles .= '"' . $tagmanager->getTagFileDir() . $key . '.xml=' . $conf['docu_url'] . '"';
            $index++;
        }

        // TODO: allow more configuration settings for doxygen execution through the doxygen_conf parameter


        // Running the Doxygen command with overridden TAGFILES
        exec("cd $build_dir && ( cat $doxygenConfig ; echo 'TAGFILES=$tagfiles' ) | $doxygenExecutable -", $output, $returnVar);

        // now extract the XML files from the build directory
        // Find all XML files in the directory
        $files = glob($build_dir. '/xml/*_8*.xml');
    
        foreach ($files as $file) {
            // Get the file name without extension
            $filename = pathinfo($file, PATHINFO_FILENAME);

            $cache_name = pathinfo($filename, PATHINFO_FILENAME);

            $cache_name = explode('_8', $cache_name);;

            // move XML to cache file position
            $cache_name = getCacheName($cache_name[0],".xml");

            copy($file,$cache_name);
        }

        return $returnVar === 0;
    }

  
    /**
     * The function _getXMLOutputName takes a filename in the Doxygen workspace and converts it to the output XML filename.
     * TODO: can probably be deleted!
     * 
     * @param string Name of a source file in the doxygen workspace.
     * 
     * @return string Name of the XML file output by Doxygen for the given source file.
     */
    private function _getXMLOutputName($filename) {
        return str_replace(".","_8",$filename) . '.xml';
    }

    /**
     * The function creates a temporary directory for building the Doxygen documentation.
     * 
     * @return string Directory where Doxygen can be executed.
     */
    private function _createTaskDir($taskID) {
        global $conf;

        $tempDir = $conf['tmpdir'] . '/doxycode/';

        // check if we already have a doxycode directory
        if(!is_dir($tempDir)) {
            mkdir($tempDir);
        }
    
        $tempDir .= $taskID;
        if(!is_dir($tempDir)) {
            mkdir($tempDir);
        }

        return $tempDir;
    }

    private function _deleteTaskDir($dirPath) {
        if (! is_dir($dirPath)) {
            throw new InvalidArgumentException("$dirPath must be a directory");
        }

        io_rmdir($dirPath,true);
    }


    private function _createJobFile($jobID,&$config,$content) {
        // if we do not already have a job directory, create it
        $tmpDir = $this->_createTaskDir($config['taskID']);

        if(!is_dir($tmpDir)) {
            return '';
        }

        // we expect a cache filename (md5) - the xml output from the build job will have this filename
        // thereby the doxygen builder can correctly identify where the XML output file should be placed
        // getCacheName() can later be used to get the correct place for the cache file
        $render_file_name = $jobID . '.' . $config['language'];

        // Attempt to write the content to the file
        $result = file_put_contents($tmpDir . '/' . $render_file_name, $content);

        // TODO: maybe throw error here and try catch where used...

        return $tmpDir;
    }

    public function getBuildTasks($amount = PHP_INT_MAX) {
        // get build tasks from SQLite
        // the order should be the same for all functions
        // first one is the one currently built or the next one do build
        if($this->db === null) {
            return false;
        }

        // get the oldest task first
        $rows = $this->db->queryAll('SELECT TaskID FROM Tasks WHERE State = ? ORDER BY Timestamp ASC LIMIT ?', [self::STATE_SCHEDULED, $amount]);
    

        return $rows;
    }



    public function filterDoxygenAttributes($config, $exclude = false) {
        $filtered_config = [];

        // filter tag_config by tag_names
        if(!$exclude) {
            $filtered_config = array_intersect_key($config, array_flip($this->conf_doxygen_keys));
        } else {
            $filtered_config = array_diff_key($config, array_flip($this->conf_doxygen_keys));
        }

        $filtered_config = is_array($filtered_config) ? $filtered_config : [$filtered_config];

        return $filtered_config;
    }
}
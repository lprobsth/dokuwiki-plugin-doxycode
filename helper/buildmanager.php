<?php

/**
 * DokuWiki Plugin doxycode (Buildmanager Helper Component)
 *
 * @license     GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */

use dokuwiki\Extension\Plugin;
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
class helper_plugin_doxycode_buildmanager extends Plugin
{
    public const STATE_NON_EXISTENT = 1;
    public const STATE_RUNNING = 2;
    public const STATE_SCHEDULED = 3;
    public const STATE_FINISHED = 4;
    public const STATE_ERROR = 5;

    /**
     * @var string Filename of the lock file for only allowing one doxygen process.
     */
    protected const LOCKFILENAME = '_plugin_doxycode.lock';

    protected $db = null;


    /**
     * @var array Allowed configuration strings that are relevant for doxygen.
     */
    private $conf_doxygen_keys = array(
        'tagfiles',
        'doxygen_conf',
        'language'
    );

    /**
     * @var String[] Configuration strings that are only relevant for the snippet syntax.
     */
    private $conf_doxycode_keys = array(
        'render_task'
    );

    public function __construct()
    {
        // check if sqlite is available
        if (!plugin_isdisabled('sqlite')) {
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

    /**
     * Add a build job to the task runner builder and create or update a build task if necessary.
     *
     * This function adds a new build job to the Jobs table in sqlite.
     * Each build job corresponds to a build task. If no build task exists for the build job it will create a new one
     * and insert it to the Tasks table in sqlite.
     *
     * If the build task for this build job is existing it will try to
     * change its state to 'STATE_SCHEDULED' to run it again.
     * If the build task is already running, we don't want to interfere the doxygen build process. In that case
     * we create a new build task for this build job with a random taskID.
     *
     * @param String $jobID Identifier for this build job
     * @param Array &$config Arguments from the snippet syntax containing the configuration for the snippet
     * @param String $content Code snippet content
     * @return Bool If adding the build job was successful
     */
    public function addBuildJob($jobID, &$config, $content)
    {
        if ($this->db === null) {
            return false;
        }

        // TODO: is a race condition possible where we add a job to a
        // task and during that operation the task runner start executing the task?

        // check if the Task is already running
        $row = $this->db->queryRecord('SELECT * FROM Tasks WHERE TaskID = ?', [$config['taskID']]);

        switch ($row['State']) {
            case self::STATE_ERROR: // fall through
            case self::STATE_FINISHED: {
                // this means that the build directory probably was already deleted
                // we can just recreate the directory or put our job into the existing build directory
                $id = $this->db->exec(
                    'UPDATE Tasks SET Timestamp = CURRENT_TIMESTAMP, State = ? WHERE TaskID = ?',
                    [self::STATE_SCHEDULED, $config['taskID']]
                );
                break;
            }
            case self::STATE_SCHEDULED: {
                // just update the timestamp so we don't accidentally delete this task
                $id = $this->db->exec(
                    'UPDATE Tasks SET Timestamp = CURRENT_TIMESTAMP WHERE TaskID = ?',
                    [$config['taskID']]
                );
                break;
            }
            case self::STATE_RUNNING: {
                // Generate an new TaskID
                $config['taskID'] = md5(microtime(true) . mt_rand());
            }
            case null;
            case '': {
                // we need to create a new task!
                $id = $this->db->exec(
                    'INSERT INTO Tasks (TaskID, State, Timestamp, Configuration) VALUES (?, ?, CURRENT_TIMESTAMP, ?)',
                    [
                        $config['taskID'],
                        self::STATE_SCHEDULED,
                        json_encode(
                            $this->filterDoxygenAttributes($config, false),
                            true
                        )
                    ]
                );
                break;
            }
        }

        // create the job file with the code snippet content
        $tmp_dir = $this->createJobFile($jobID, $config, $content);

        if (strlen($tmp_dir) == 0) {
            return false;
        }
        
        // create the job in sqlite

        $data = [
            'JobID' => $jobID,
            'TaskID' => $config['taskID'],
            'Configuration' => json_encode($this->filterDoxygenAttributes($config, true), true)
        ];

        $new = $this->db->saveRecord('Jobs', $data);

        return true;
    }

    /**
     * Try to immediately build a code snippet.
     *
     * If a lock for the doxygen process is present doxygen is already running.
     * In that case we try to add the build job to the build queue (if sqlite is available).
     *
     * @param String $jobID Identifier for this build job
     * @param Array &$config Arguments from the snippet syntax containing the configuration for the snippet
     * @param String $content Code snippet content
     * @param Array $tag_conf Tag file configuration used for passing the tag files to doxygen
     * @return Bool If build or adding it to the build queue as a build job was successful
     */
    public function tryBuildNow($jobID, &$config, $content, $tag_conf)
    {
        global $conf;

        // first try to detect if a doxygen instance is already running
        if (!$this->lock()) {
            // we cannot build now because only one doxygen instance is allowed at a time!
            // this will return false if task runner is not available
            // otherwise it will create a task and a job
            return $this->addBuildJob($jobID, $conf, $content);
            
            $config['render_task'] = true;
        }

        // no doxygen instance is running - we can immediately build the file!

        // TODO: should we also create entries for Task and Job in sqlite if we directly build the snippet?

        // create the directory where rendering with doxygen takes place
        $tmp_dir = $this->createJobFile($jobID, $config, $content);

        if (strlen($tmp_dir) == 0) {
            $this->unlock();

            return false;
        }

        // run doxygen on our file with XML output
        $this->runDoxygen($tmp_dir, $tag_conf);

        // delete tmp_dir
        if (!$conf['allowdebug']) {
            $this->deleteTaskDir($tmp_dir);
        }


        $this->unlock();

        return true;
    }

    /**
     * Get the state of a build task from the Tasks table in sqlite.
     *
     * If no entry for this task could be found in sqlite we return STATE_NON_EXISTENT.
     *
     * @param String $id TaskID of the build task
     * @return Num Task State
     */
    public function getTaskState($id)
    {
        if ($this->db === null) {
            // TODO: better return value?
            return self::STATE_NON_EXISTENT;
        }

        $row = $this->db->queryRecord('SELECT * FROM Tasks WHERE TaskID = ?', $id);

        if ($row !== null) {
            return $row['State'];
        } else {
            return self::STATE_NON_EXISTENT;
        }
    }

    /**
     * Get the state of a build job.
     *
     * Here we first lookup the corresponding build task for the build job and then lookup
     * the task state with getTaskState.
     *
     * @param String $jobID JobID for this build job
     * @return Num Job State
     */
    public function getJobState($jobID)
    {
        if ($this->db === null) {
            // TODO: better return value?
            return self::STATE_NON_EXISTENT;
        }

        // get the TaskID from sqlite
        $row = $this->db->queryRecord('SELECT * FROM Jobs WHERE JobID = ?', $jobID);

        // check the task state and return as job state
        if ($row !== null) {
            // TODO: can we directly retreive the Task from our reference in sqlite?
            return $this->getTaskState($row['TaskID']);
        } else {
            return self::STATE_NON_EXISTENT;
        }
    }

    /**
     * Return the doxygen relevant build task configuration of a build task.
     *
     * This is useful in a context where the configuration can not be obtained from the snippet syntax.
     *
     * An example for this is the 'plugin_doxycode_get_snippet_html' ajax call where the doxygen output XML is parsed.
     * There we need the configuration for matching the used tag files.
     *
     * @param String $taskID TaskID for this build task
     * @return Array Task configuration including the used tag files
     */
    public function getTaskConf($taskID)
    {
        if ($this->db === null) {
            // TODO: better return value?
            return [];
        }

        $row = $this->db->queryRecord('SELECT Configuration FROM Tasks WHERE TaskID = ?', $taskID);

        if ($row !== null) {
            return json_decode($row['Configuration'], true);
        } else {
            return [];
        }
    }

    /**
     * Return the doxygen relevant build task configuration configuration of a build job.
     *
     * This is useful in a context where the configuration can not be obtained from the snippet syntax.
     *
     * We first obtain the corresponding TaskID from the Jobs table in sqlite.
     * We then call getTaskConf to get the task configuration.
     *
     * @param String $jobID JobID for this Job
     * @return Array Task configuration including the used tag files
     */
    public function getJobTaskConf($jobID)
    {
        if ($this->db === null) {
            // TODO: better return value?
            return [];
        }

        // get the TaskID from sqlite
        $row = $this->db->queryRecord('SELECT * FROM Jobs WHERE JobID = ?', $jobID);

        // get the Configuration from the Task
        if ($row !== null) {
            return $this->getTaskConf($row['TaskID']);
        } else {
            return [];
        }
    }

    /**
     * Get the HTML relevant configuration of a build job.
     *
     * This is useful in a context where the configuration can not be obtained from the snippet syntax.
     *
     * @param String $jobID JobID for this Job
     * @return Array Task configuration including linenumbers, filename, etc.
     */
    public function getJobConf($jobID)
    {
        if ($this->db === null) {
            // TODO: better return value?
            return [];
        }

        // get the TaskID from sqlite
        $row = $this->db->queryRecord('SELECT Configuration FROM Jobs WHERE JobID = ?', $jobID);

        if ($row !== null) {
            return json_decode($row['Configuration'], true);
        } else {
            return [];
        }
    }

    /**
     * Check if a lock for the doxygen process is present.
     *
     * @return Bool Is a lock present?
     */
    private function isRunning()
    {
        global $conf;

        $lock = $conf['lockdir'] . self::LOCKFILENAME;

        return file_exists($lock);
    }

    /**
     * Create a lock for the doxygen process.
     *
     * This is used for ensuring that only one doxygen process can be present at all times.
     *
     * If a lock is present we check if the lock file is older than the maximum allowed execution time
     * of the doxygen task runner. We then assume that the lock is stale and remove it.
     *
     * If a lock is present and not stale locking fails.
     *
     * @return Bool Was locking successful?
     */
    private function lock()
    {
        global $conf;

        $lock = $conf['lockdir'] . self::LOCKFILENAME;

        if (file_exists($lock)) {
            if (time() - @filemtime($lock) > $this->getConf('runner_max_execution_time')) {
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

    /**
     * Remove the doxygen process lock file.
     *
     * @return Bool Was removing the lock successful?
     */
    private function unlock()
    {
        global $conf;
        $lock = $conf['lockdir'] . self::LOCKFILENAME;
        return unlink($lock);
    }

    /**
     * Execute a doxygen task runner build task.
     *
     * We obtain the build task from the Tasks table in sqlite.
     * The build task row includes used tag files from the snippet syntax.
     *
     * We then load the tag file configuration for those tag files and try to execute the build.
     *
     * After the doxygen process exited we update the build task state in sqlite.
     *
     * @param String $taskID TaskID for this build task
     * @return Bool Was building successful?
     */
    public function runTask($taskID)
    {
        global $conf;

        if (!$this->lock()) {
            // a task is already running
            return false;
        }

        // get the config from sqlite!
        $row = $this->db->queryRecord('SELECT * FROM Tasks WHERE TaskID = ?', $taskID);

        // we only want to run if we have a scheduled job!
        if ($row === null || $row['State'] != self::STATE_SCHEDULED) {
            $this->unlock();
            return false;
        }

        $config = json_decode($row['Configuration'], true);


        /** @var helper_plugin_doxycode_tagmanager $tagmanager */
        $tagmanager = plugin_load('helper', 'doxycode_tagmanager');
        // load the tag_config from the tag file list
        if (!is_array($config['tagfiles'])) {
            $config['tagfiles'] = [$config['tagfiles']];
        }
        $tag_config = $tagmanager->getFilteredTagConfig($config['tagfiles']);

        // update the maximum execution time according to configuration
        // TODO: maybe check if this configuration is present?
        set_time_limit($this->getConf('runner_max_execution_time'));

        // this just returns the build dir if already existent
        $tmpDir = $this->createTaskDir($taskID);

        // update the task state
        // we do not update the timestamp of the task here
        $this->db->exec(
            'UPDATE Tasks SET State = ? WHERE TaskID = ?',
            [self::STATE_RUNNING, $taskID]
        );

        // execute doxygen and move cache files into position
        $success = $this->runDoxygen($tmpDir, $tag_config);

        // update the task state
        if ($success) {
            $this->db->exec(
                'UPDATE Tasks SET State = ? WHERE TaskID = ?',
                [self::STATE_FINISHED, $taskID]
            );
        } else {
            $this->db->exec(
                'UPDATE Tasks SET State = ? WHERE TaskID = ?',
                [self::STATE_ERROR, $taskID]
            );
        }


        // delete tmp_dir
        if (!$conf['allowdebug']) {
            $this->deleteTaskDir($tmpDir);
        }

        $this->unlock();

        return true;
    }

    /**
     * Execute doxygen in a shell.
     *
     * The doxygen configuration is passed to doxygen via a pipe and the TAGFILES parameter
     * is overridden with the tag file configuration passed to this function.
     *
     * In the xml output directory all matching XML output files are extracted and placed
     * where the other plugin components expect the XML cache file. This is done by extracting the XML
     * cache ID from the doxygen XML output filename (the source files in the doxygen directory where named
     * after the cache ID).
     *
     * @param String $build_dir Directory where doxygen should be executed.
     * @param Array $tag_conf Tag file configuration
     * @return Bool Was the execution successful?
     */
    private function runDoxygen($build_dir, $tag_conf = null)
    {
        if (!is_dir($build_dir)) {
            // the directory does not exist
            return false;
        }

        $doxygenExecutable = $this->getConf('doxygen_executable');

        // check if doxygen executable exists!
        if (!file_exists($doxygenExecutable)) {
            return false;
        }

        // Path to your Doxygen configuration file
        // TODO: use default doxygen config or allow admin to upload
        // a doxygen configuration that is not overwritten by plugin updates
        $doxygenConfig = DOKU_PLUGIN . $this->getPluginName() . '/doxygen.conf';


        /** @var helper_plugin_doxycode_tagmanager $tagmanager */
        $tagmanager = plugin_load('helper', 'doxycode_tagmanager');

        // TAGFILES variable value you want to set
        $tagfiles = '';
        $index = 0;
        foreach ($tag_conf as $key => $conf) {
            if ($index > 0) {
                $tagfiles .= ' ';
            }

            $tagfiles .= '"' . $tagmanager->getTagFileDir() . $key . '.xml=' . $conf['docu_url'] . '"';
            $index++;
        }

        // TODO: allow more configuration settings for doxygen execution through the doxygen_conf parameter


        // Running the Doxygen command with overridden TAGFILES
        exec(
            "cd $build_dir && ( cat $doxygenConfig ; echo 'TAGFILES=$tagfiles' ) | $doxygenExecutable -",
            $output,
            $returnVar
        );

        // now extract the XML files from the build directory
        // Find all XML files in the directory
        $files = glob($build_dir . '/xml/*_8*.xml');
    
        foreach ($files as $file) {
            // Get the file name without extension
            $filename = pathinfo($file, PATHINFO_FILENAME);

            $cache_name = pathinfo($filename, PATHINFO_FILENAME);

            $cache_name = explode('_8', $cache_name);

            // move XML to cache file position
            $cache_name = getCacheName($cache_name[0], ".xml");

            copy($file, $cache_name);
        }

        return $returnVar === 0;
    }

  
    /**
     * The function _getXMLOutputName takes a filename
     * in the Doxygen workspace and converts it to the output XML filename.
     *
     * @todo: can probably be deleted!
     *
     * @param string Name of a source file in the doxygen workspace.
     *
     * @return string Name of the XML file output by Doxygen for the given source file.
     */
    private function getXMLOutputName($filename)
    {
        return str_replace(".", "_8", $filename) . '.xml';
    }

    /**
     * The function creates a temporary directory for building the Doxygen documentation.
     *
     * @return string Directory where Doxygen can be executed.
     */
    private function createTaskDir($taskID)
    {
        global $conf;

        $tempDir = $conf['tmpdir'] . '/doxycode/';

        // check if we already have a doxycode directory
        if (!is_dir($tempDir)) {
            mkdir($tempDir);
        }
    
        $tempDir .= $taskID;
        if (!is_dir($tempDir)) {
            mkdir($tempDir);
        }

        return $tempDir;
    }

    /**
     * Delete the temporary build task directory.
     *
     * @param String $dirPath Build directory where doxygen is executed.
     */
    private function deleteTaskDir($dirPath)
    {
        if (! is_dir($dirPath)) {
            throw new InvalidArgumentException("$dirPath must be a directory");
        }

        io_rmdir($dirPath, true);
    }

    /**
     * Create the source file in the build directory of the build task.
     *
     * The $config variable includes the corresponding TaskID.
     * First we try to create the temporary build directory.
     *
     * We than place the content of the code snippet in a source file in the build directory.
     *
     * The extension of the source file is the 'language' variable in $config.
     * The filename of the source file is the cache ID (=JobID) of the XML cache file.
     * This can later be used to place the doxygen output XML at the appropriate XML cache file name.
     *
     * @param String $jobID JobID for this build job
     * @param Array &$config Configuration from the snippet syntax
     * @param String $content Content from the code snippet
     */
    private function createJobFile($jobID, &$config, $content)
    {
        // if we do not already have a job directory, create it
        $tmpDir = $this->createTaskDir($config['taskID']);

        if (!is_dir($tmpDir)) {
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

    /**
     * Get a list of scheduled build tasks from the Tasks table in sqlite.
     *
     * @param Num $amount Amount of build tasks to return.
     * @return Array Build tasks
     */
    public function getBuildTasks($amount = PHP_INT_MAX)
    {
        // get build tasks from SQLite
        // the order should be the same for all functions
        // first one is the one currently built or the next one do build
        if ($this->db === null) {
            return false;
        }

        // get the oldest task first
        $rows = $this->db->queryAll(
            'SELECT TaskID FROM Tasks WHERE State = ? ORDER BY Timestamp ASC LIMIT ?',
            [self::STATE_SCHEDULED, $amount]
        );
    

        return $rows;
    }


    /**
     * Filter the doxygen relevant attributes from a configuration array.
     *
     * The doxygen relevant attributes are parameters that are passed to doxygen when building.
     * Examples: tag file configuration
     *
     * The configuration also includes attributes that only influence task scheduling (e.g. 'render_task' which forces
     * task runner build from the syntax). Here we filter those values out.
     *
     * This function is especially useful for generating the cache file IDs.
     *
     * @param Array $config Configuration from the snippet syntax.
     * @param bool $exclude Return only doxygen relevant configuration or everying else
     * @return Array filtered configuration
     */
    public function filterDoxygenAttributes($config, $exclude = false)
    {
        $filtered_config = [];

        // filter tag_config by tag_names
        if (!$exclude) {
            $filtered_config = array_intersect_key($config, array_flip($this->conf_doxygen_keys));
        } else {
            $filtered_config = array_diff_key($config, array_flip($this->conf_doxygen_keys));
        }


        // filter out keys that are only relevant for the snippet syntax
        $filtered_config = array_diff_key($filtered_config, array_flip($this->conf_doxycode_keys));

        $filtered_config = is_array($filtered_config) ? $filtered_config : [$filtered_config];

        return $filtered_config;
    }
}

<?php
/**
 * DokuWiki Plugin doxycode (Action Component)
 *
 * @license     GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */

if(!defined('DOKU_INC')) die();

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\HTTP\DokuHTTPClient;
use dokuwiki\Remote\Api;
use dokuwiki\Extension\Event;
use dokuwiki\Cache\Cache; 

/**
 * Class action_plugin_doxycode
 * 
 * This action component of the doxygen plugin handles the download of remote tag files,
 * build job/task execution, cache invalidation, and ajax calls for checking the job/task
 * execution and dynamic loading of finished code snippets.
 * 
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */
class action_plugin_doxycode extends ActionPlugin {

    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('INDEXER_TASKS_RUN', 'AFTER', $this, 'loadRemoteTagFiles');
        $controller->register_hook('INDEXER_TASKS_RUN', 'AFTER', $this, 'renderDoxyCodeSnippets');
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'beforeParserCacheUse');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'ajaxCall');
        $controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'insertTagButton');
        $controller->register_hook('RPC_CALL_ADD','AFTER',$this,'add_rpc_all');
    }
    
    
    public function loadRemoteTagFiles(Event $event, $param) {
        // get the tag files from the helper

        /** @var helper_plugin_doxycode_tagmanager $tagmanager */
        $tagmanager = plugin_load('helper', 'doxycode_tagmanager');

        $config = $tagmanager->loadTagFileConfig();

        // loop over all tag file configurations
        foreach($config as $key => &$conf) {
            if(!$tagmanager->isValidRemoteConfig($conf)) {
                // only try to download a tag file if it is a remote file!
                continue;
            }

            $now = time();
            $timestamp = $conf['last_update'] ? $conf['last_update'] : 0;
            $update_period = $conf['update_period'] ? $conf['update_period'] :  $this->getConf('update_period');
            $filename = $tagmanager->getTagFileDir() . $key . '.xml';
            
            // only try to update every $update_period amount of seconds
            if($now - $update_period >= $timestamp) {
                // only process one thing per task runner run
                $event->stopPropagation();
                $event->preventDefault();

                $conf['last_update'] = $now;
                // save the new timestamp - regardless of the success of the rest
                // on connection failures, we don't want to run the task runner constantly on failures!
                // true: do not update the mtime of the configuration!
                // if a new tag file is available we invalidate the cache if this tag file was used in a page!
                $tagmanager->saveTagFileConfig($config,true);

                $exists = False;    // if the file does not exist - just write now!
                $cachedHash = '';   // this is used to check if we really have a new file

                // first read the old file and free memory so we do not exeed the limit!
                if($cachedContent = @file_get_contents($filename)) {
                    $exists = True;

                    $cachedHash = md5($cachedContent);

                    unset($cachedContent);
                }

                // it's time to reload the tag file
                $http = new DokuHTTPClient();
                $url = $conf['remote_url'];
                if (!$http->get($url)) {
                    $error = 'could not get ' . hsc($url) . ', HTTP status ' . $http->status . '. ';
                    throw new Exception($error);
                }

                // here we have the new content
                $newContent = $http->resp_body;
                $newHash = md5($newContent);

                if (!$exists || $cachedHash !== $newHash) {
                    // save the new tag file
                    file_put_contents($filename, $newContent);
                }


                return; // we only ever want to run one file!
            }
        }
    }

    public function renderDoxyCodeSnippets(Event $event) {
        global $ID;

        /** @var helper_plugin_doxycode_buildmanager $buildmanager */
        $buildmanager = plugin_load('helper', 'doxycode_buildmanager');

        $tasks = $buildmanager->getBuildTasks();

        if(sizeof($tasks) > 0) {
            $event->stopPropagation();
            $event->preventDefault();

            foreach($tasks as $task) {
                $buildmanager->runTask($task['TaskID']);
            }
        }
    }

    public function beforeParserCacheUse(Event $event) {
        global $ID;
        $cache = $event->data;
        if (isset($cache->mode) && ($cache->mode == 'xhtml')) {
            // load doxycode meta that includes the used tag files and the cache files for the snippets
            $doxycode_meta = p_get_metadata($ID, 'doxycode');

            if($doxycode_meta == null) {
                // doxycode was not used in this page!
                return;
            }

            $depends = [];

            // if the tagfiles were updated in the background, we need to rebuild the snippets
            // in this case we first need to invalidate the page cache
            $tagfiles = $doxycode_meta['tagfiles'];

            if($tagfiles != null) {
                // transform the list of tag files into an array that can be used by the helper
                // ['tag_name' => []]: empty array as value since we do not have a loaded tag configuration here!
                $tag_config = array_fill_keys($tagfiles, []);
    
                /** @var helper_plugin_doxycode $helper */
                $helper = plugin_load('helper', 'doxycode');
    
                // transform the tag names to full file paths
                $helper->getTagFiles($depends,$tag_config);
            };
    
            // load the PHP file dependencies
            // if any file was changed, we want to do a reload
            // the other dependencies (cache files) might not be enough, if e.g. the way we generate the hash names change
            // this might happen if the old cache files still exist and the meta data was not updated
            $helper->getPHPFileDependencies($depends);

            // add these to the dependency list
            if (!empty($depends) && isset($depends['files'])) {
                $this->addDependencies($cache, $depends['files']);
            }

            // if the XML cache is not existent we should check if the build is scheduled in the main syntax component
            $cache_files = $doxycode_meta['xml_cachefiles'];

            foreach($cache_files as $cacheID) {
                $cache_name = getCacheName($cacheID,".xml");

                if(!@file_exists($cache_name)) {
                    $event->preventDefault();
                    $event->stopPropagation();
                    $event->result = false;
                }

                $this->addDependencies($cache, [$cache_name]);
            }

            // if the HTML cache is not existent we should parse the XML in the main syntax component before the page loads
            $cache_files = $doxycode_meta['html_cachefiles'];

            foreach($cache_files as $cacheID) {
                $cache_name = getCacheName($cacheID,".html");

                if(!@file_exists($cache_name)) {
                    $event->preventDefault();
                    $event->stopPropagation();
                    $event->result = false;
                }

                $this->addDependencies($cache, [$cache_name]);
            }
        }
    }


    /**
     * Add extra dependencies to the cache
     * 
     * copied from changes plugin
     */
    protected function addDependencies($cache, $depends)
    {
        // Prevent "Warning: in_array() expects parameter 2 to be array, null given"
        if (!is_array($cache->depends)) {
            $cache->depends = [];
        }
        if (!array_key_exists('files', $cache->depends)) {
            $cache->depends['files'] = [];
        }

        foreach ($depends as $file) {
            if (!in_array($file, $cache->depends['files']) && @file_exists($file)) {
                $cache->depends['files'][] = $file;
            }
        }
    }


    /**
     * handle ajax requests
     */
    public function ajaxCall(Event $event) {
        switch($event->data) {
            case 'plugin_doxycode_check_status':
            case 'plugin_doxycode_get_snippet_html':
            case 'plugin_doxycode_get_tag_files':
                break;
            default:
                return;
        }

        // no other ajax call handlers needed
        $event->stopPropagation();
        $event->preventDefault();

        if($event->data === 'plugin_doxycode_check_status') {
            // the main syntax component has put placeholders into the page while rendering
            // the client tries to get the newest state for the doxygen builds executed through the buildmanager

            /** @var helper_plugin_doxycode_buildmanager $buildmanager */
            $buildmanager = plugin_load('helper', 'doxycode_buildmanager');
     
            // the client sends us an array with the cache names (md5 hashes)
            global $INPUT;
            $hashes = $INPUT->arr('hashes');
    
            // get the job state for each XML file
            foreach($hashes as &$hash) {
                if(strlen($hash['xmlHash']) > 0) {
                    $hash['state'] = $buildmanager->getJobState($hash['xmlHash']);
                }
            }
     
            //set content type
            header('Content-Type: application/json');
            echo json_encode($hashes);

            return;
        } // plugin_doxycode_check_status

        if($event->data === 'plugin_doxycode_get_snippet_html') {
            header('Content-Type: application/json');
            
            // a client tries to dynamically load the rendered code snippet
            global $INPUT;
            $hashes = $INPUT->arr('hashes');

            $xml_cacheID = $hashes['xmlHash'];
            $html_cacheID = $hashes['htmlHash'];

            /** @var helper_plugin_doxycode $helper */
            $helper = plugin_load('helper', 'doxycode');
            /** @var helper_plugin_doxycode_buildmanager $buildmanager */
            $buildmanager = plugin_load('helper', 'doxycode_buildmanager');
            /** @var helper_plugin_doxycode_parser $parser */
            $parser = plugin_load('helper', 'doxycode_parser');
            /** @var helper_plugin_doxycode_tagmanager $tagmanager */
            $tagmanager = plugin_load('helper', 'doxycode_tagmanager');

            // maybe the snippet was already rendered in the meantime (by another ajax call or through page reload)
            $html_cache = new Cache($html_cacheID, '.html');
            $xml_cache = new Cache($xml_cacheID, '.xml');

            $task_config = $buildmanager->getJobTaskConf($xml_cacheID);
            $tag_conf = $tagmanager->getFilteredTagConfig($task_config['tagfiles']);

            $depends = [];
            $helper->getHTMLFileDependencies($depends,$html_cacheID,$tag_conf);

            $data = [
                'success' => false,
                'hashes' => $hashes,
                'html' => ''
            ];

            // how will we generate the dependencies?
            if($html_cache->useCache($depends)) {
                // we have a valid HTML rendered!

                if($cachedContent = @file_get_contents($html_cache->cache)) {
                    // add HTML cache to response

                    $data['html'] = $cachedContent;
                    $data['success'] = true;

                    echo json_encode($data);
                    return;
                }
            }

            $job_config = $buildmanager->getJobConf($xml_cacheID);

            $depends = [];
            $helper->getXMLFileDependencies($depends,$tag_conf);
            //set content type

            if($xml_cache->useCache($depends)) {
                // we have a valid XML!

                $xml_content = @file_get_contents($xml_cache->cache);

                $rendered_text = $parser->renderXMLToDokuWikiCode($xml_content,$job_config['linenumbers'],$tag_conf);
                
                // save content to cache
                @file_put_contents($html_cache->cache,$rendered_text);

                // add HTML to response
                $data['html'] = $rendered_text;
                $data['success'] = true;

                echo json_encode($data);

                return;
            }

            echo json_encode($data);
            return;
        } // plugin_doxycode_get_snippet_html

        if($event->data === 'plugin_doxycode_get_tag_files') {
            // the client has requested a list of available tag file configurations

            /** @var helper_plugin_doxycode_tagmanager $tagmanager */
            $tagmanager = plugin_load('helper', 'doxycode_tagmanager');

            // load the tag file configuration
            $tag_config = $tagmanager->loadTagFileConfig();

            // filter only enabled configuration
            $tagmanager->filterEnabledConfig($tag_config);

            header('Content-Type: application/json');

            // send data
            echo json_encode($tag_config);

            return;
        }
    }

    public function insertTagButton(Event $event) {
        $event->data[] = array (
            'type' => 'doxycodeTagSelector',
            'title' => 'doxycode',
            'icon' => DOKU_REL.'lib/plugins/doxycode/images/toolbar/doxycode.png',
            'open'   => '<doxycode>',
            'close'  => '</doxycode>',
            'block'  => false
        );
    }

    public function add_rpc_all(&$event, $param){
        $my_rpc_call=array(
            'doxycode.listTagFiles' => array('doxycode', 'listTagFiles'),
            'doxycode.uploadTagFile'=>array('doxycode', 'uploadTagFile')
        );
        $event->data=array_merge($event->data,$my_rpc_call);
    }
}

?>
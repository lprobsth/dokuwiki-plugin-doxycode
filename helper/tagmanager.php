<?php
/**
 * DokuWiki Plugin doxycode (Tagmanager Helper Component)
 *
 * @license     GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */

if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');


use \dokuwiki\Extension\Plugin;

class helper_plugin_doxycode_tagmanager extends Plugin {

    private $tagfile_dir;   // convenience variable for accessing the tag files

    function __construct() {
        $this->tagfile_dir = DOKU_PLUGIN . $this->getPluginName() . '/tagfiles/';
    }

    /**
     * The function "listTagFiles" returns an array with file names (without extension) as keys and empty
     * arrays as values. This ensures compatibility with the tag file configuration from loadTagFileConfig().
     * The list of tag files can then be merged with the tag file configuration.
     * 
     * @return array associative array where the keys are the file names (without extension) of all XML files
     * in the directory, and the values are empty arrays.
     */
    public function listTagFiles() {
        // Find all XML files in the directory
        $files = glob($this->tagfile_dir. '*.xml');
    
        // Array to hold file names without extension
        $fileNames = [];
    
        foreach ($files as $file) {
            // Get the file name without extension
            $fileNames[] = pathinfo($file, PATHINFO_FILENAME);
        }
    
        return array_fill_keys($fileNames, []);
    }

    public function getTagFileDir() {
        return $this->tagfile_dir;
    }


    /**
     * The function `loadTagFileConfig()` reads and decodes the contents of a JSON file containing tagfile
     * configuration, returning the decoded configuration array.
     * 
     * @return array configuration array loaded from the tagconfig.json file. If the file does not exist or
     * if there is an error reading or decoding the JSON content, an empty array is returned.
     */
    public function loadTagFileConfig() {
        // /path/to/dokuwiki/lib/plugins/doxycode/tagfiles/

        $filename = $this->tagfile_dir . 'tagconfig.json';
        
        // Check if the file exists
        if (!file_exists($filename)) {
            // admin needs to update the tagfile configuration
            return [];
        }

        // Read the contents of the file
        $jsonContent = file_get_contents($filename);
        if ($jsonContent === false) {
            return [];
        }

        // Decode the JSON content
        $config = json_decode($jsonContent, true);
        if ($config === null) {
            return [];
        }

        return $config;
    }

    public function saveTagFileConfig(&$tag_config,$restore_mtime = false) {
        $save_key_selection = ['remote_url','update_period','docu_url','enabled','last_update','force_runner','description'];

        $selectedKeys = [];

        $config_filename = $this->tagfile_dir . 'tagconfig.json';

        // loop over all configuration entries
        foreach($tag_config as $name => $tag_conf) {
            // loop over all keys in configuration
            foreach($tag_conf as $key => $value) {
                if(in_array($key,$save_key_selection)) {
                    $selectedKeys[$name][$key] = $value;
                }
            }
        }

        // Convert the selected keys to JSON
        $jsonData = json_encode($selectedKeys, JSON_PRETTY_PRINT);


        $original_mtime = filemtime($config_filename);

        file_put_contents($config_filename, $jsonData);

        if ($original_mtime !== false && $restore_mtime) {
            touch($config_filename, $original_mtime);
        }
    }

    public function getFileName($tag_name) {
        return $this->tagfile_dir . $tag_name . '.xml';
    }


    public function getFilteredTagConfig($tag_names = null) {
        $tag_conf = $this->loadTagFileConfig();

        // TODO: filter out tag files
        $tag_conf = array_filter($tag_conf,array($this, 'isConfigEnabled'));


        if($tag_names) {
            // convert to array if only one tag_name was given
            $tag_names = is_array($tag_names) ? $tag_names : [$tag_names];

            // filter tag_config by tag_names
            $tag_conf = array_intersect_key($tag_conf, array_flip($tag_names));
        }

        return $tag_conf;
    }

    public function filterEnabledConfig(&$tag_conf) {
        return array_filter($tag_conf,array($this, 'isConfigEnabled'));;
    }

    public function isConfigEnabled(&$tag_conf) {
        return boolval($tag_conf['enabled']);
    }

    public function isValidRemoteConfig(&$conf) {

        // TODO: should we check if the URL contains a valid XML extension?
        if(strlen($conf['remote_url']) > 0) {
            return True;
        } else {
            return False;
        }
    }

    public function isForceRenderTaskSet(&$tag_config) {
        $force_render = false;
        foreach($tag_config as $key => $tag_conf) {
            if($tag_conf['force_runner']) {
                $force_render = True;
                break;
            }
        }

        return $force_render;
    }
}
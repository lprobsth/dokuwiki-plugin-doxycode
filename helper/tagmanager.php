<?php

/**
 * DokuWiki Plugin doxycode (Tagmanager Helper Component)
 *
 * @license     GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */

use dokuwiki\Extension\Plugin;

class helper_plugin_doxycode_tagmanager extends Plugin
{
    private $tagfile_dir;   // convenience variable for accessing the tag files

    public function __construct()
    {
        $this->tagfile_dir = DOKU_PLUGIN . $this->getPluginName() . '/tagfiles/';
    }

    /**
     * List tag files in the tag file directory.
     *
     * Returns an array with file names (without extension) as keys and empty
     * arrays as values. This ensures compatibility with the tag file configuration from loadTagFileConfig().
     * The list of tag files can then be merged with the tag file configuration.
     *
     * @return array associative array where the keys are the file names (without extension) of all XML files
     * in the directory, and the values are empty arrays.
     */
    public function listTagFiles()
    {
        // Find all XML files in the directory
        $files = glob($this->tagfile_dir . '*.xml');
    
        // Array to hold file names without extension
        $fileNames = [];
    
        foreach ($files as $file) {
            // Get the file name without extension
            $fileNames[] = pathinfo($file, PATHINFO_FILENAME);
        }
    
        return array_fill_keys($fileNames, []);
    }

    public function getTagFileDir()
    {
        return $this->tagfile_dir;
    }


    /**
     * The function `loadTagFileConfig()` reads and decodes the contents of a JSON file containing tagfile
     * configuration, returning the decoded configuration array.
     *
     * @return array configuration array loaded from the tagconfig.json file. If the file does not exist or
     * if there is an error reading or decoding the JSON content, an empty array is returned.
     */
    public function loadTagFileConfig()
    {
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

    /**
     * The function checks if a directory exists and creates it if it doesn't.
     *
     * @return bool either the result of the `mkdir()` function if the directory does not exist and is
     * successfully created, or `true` if the directory already exists.
     */
    public function createTagFileDir()
    {
        if (!is_dir($this->tagfile_dir)) {
            return mkdir($this->tagfile_dir);
        } else {
            return true;
        }
    }

    /**
     * Save the tag file configuration as json in the tag file directory.
     *
     * This function filters the relevant keys from the tag file configuration
     * and saves all entries as a 'tagconfig.json' in the tag file directory.
     *
     * @param Array &$tag_config Array with tag file configuration entries.
     * @param Bool $restore_mtime Restore the file modification time so
     * that the cache files are not invalidated. Defaults to false.
     */
    public function saveTagFileConfig(&$tag_config, $restore_mtime = false)
    {
        /** @var String[] $save_key_selection Configuration keys that are allowed in the stored configuration file. */
        $save_key_selection = [
            'remote_url',
            'update_period',
            'docu_url',
            'enabled',
            'last_update',
            'force_runner',
            'description'];

        /**
         * @var String[] Copied tag file configuration entries.
         *
         * We copy over the allowed configuration $key => $value pairs so the original configuration is not modified.
         */
        $selected_config = [];

        // create the tag file directory if not existent (might happen after installing the plugin)
        $this->createTagFileDir();

        $config_filename = $this->tagfile_dir . 'tagconfig.json';

        // loop over all configuration entries
        foreach ($tag_config as $name => $tag_conf) {
            // loop over all keys in configuration
            foreach ($tag_conf as $key => $value) {
                if (in_array($key, $save_key_selection)) {
                    $selected_config[$name][$key] = $value;
                }
            }
        }

        // Convert the selected configuration to JSON
        $jsonData = json_encode($selected_config, JSON_PRETTY_PRINT);

        // save the mtime so we can restore it later
        $original_mtime = filemtime($config_filename);

        file_put_contents($config_filename, $jsonData);

        // restore the mtime if we have an original mtime and restoring is enabled
        if ($original_mtime !== false && $restore_mtime) {
            touch($config_filename, $original_mtime);
        }
    }

    /**
     * Convert the internal tag file name to a full file path with extension.
     *
     * @param String $tag_name Internal tag file name
     * @return String Full file path with extension for this tag file
     */
    public function getFileName($tag_name)
    {
        return $this->tagfile_dir . $tag_name . '.xml';
    }

    /**
     * Load the configuration of tag files and optionally filter them by names.
     *
     * @param String|Array $tag_names Internal tag file names (without extension) for filtering the configuration
     * @return Array Filtered tag file configuration
     */
    public function getFilteredTagConfig($tag_names = null)
    {
        $tag_conf = $this->loadTagFileConfig();

        // filter out tag files
        $tag_conf = $this->filterConfig($tag_conf, 'isConfigEnabled');


        if ($tag_names) {
            // convert to array if only one tag_name was given
            $tag_names = is_array($tag_names) ? $tag_names : [$tag_names];

            // filter tag_config by tag_names
            $tag_conf = array_intersect_key($tag_conf, array_flip($tag_names));
        }

        return $tag_conf;
    }

    /**
     * Filter a tag file configuration array for entries that are enabled.
     *
     * @param Array &$tag_conf Array with tag file configuration entries.
     * @return Array Array with enabled tag file configuration entries.
     */
    public function filterConfig($tag_config, $filter, $inverse = false)
    {
        $filter = is_array($filter) ? $filter : [$filter];

        foreach ($filter as $function) {
            if ($inverse) {
                // Apply the inverse filter
                $tag_config = array_filter($tag_config, function ($item) use ($function) {
                    return !$this->$function($item);
                });
            } else {
                // Apply the standard filter
                $tag_config = array_filter($tag_config, array($this, $function));
            }
        }
        return $tag_config;
    }

    /**
     * Check if a tag file configuration is enabled.
     *
     * Tag file configurations can be disabled through the admin interface.
     * The parameters of the tag file (remote config, ...) will still be saved.
     * But the tag file can't be used.
     *
     * This function is used in @see filterEnabledConfig to filter a tag file configuration array for
     * entries that are enabled.
     *
     * @param Array &$tag_config Tag file configuration entry
     * @return bool Is this tag file configuration enabled?
     */
    public function isConfigEnabled(&$tag_config)
    {
        return boolval($tag_config['enabled']);
    }

    /**
     * Check if a tag file configuration represents a remote tag File
     *
     * @param Array &$tag_config Tag file configuration entry
     * @return bool Is this a remote tag file configuration?
     */
    public function isValidRemoteConfig(&$tag_config)
    {

        // TODO: should we check if the URL contains a valid XML extension?
        // TODO: should we also check if a valid period was set?
        // otherwise we could simply fall back to the default update period in the task runner action
        if (strlen($tag_config['remote_url']) > 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Check if a tag file configuration has the force runner flag enabled.
     *
     * Some tag files are huge and cause long building times.
     * We want to build the doxygen code snippet through the dokuwiki task runner in those cases.
     * Otherwise the loading time of the page might exceed the maximum php execution time.
     * This flag can be set through the admin interface.
     *
     * @param Array &$tag_config Tag file configuration entry
     * @return bool Is this the force runner flag enabled?
     */
    public function isForceRenderTaskSet(&$tag_config)
    {
        $force_render = false;
        foreach ($tag_config as $key => $tag_conf) {
            if ($tag_conf['force_runner']) {
                $force_render = true;
                break;
            }
        }

        return $force_render;
    }
}

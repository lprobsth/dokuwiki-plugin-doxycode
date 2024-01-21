<?php

/**
 * DokuWiki Plugin doxycode (Remote Component)
 *
 * @license     GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */

use dokuwiki\Extension\RemotePlugin;
use dokuwiki\Remote\AccessDeniedException;

/**
 * Class remote_plugin_doxycode
 *
 * This remote component implements the following methods for the dokuwiki remote API:
 * - receive the current tag file configuration of doxycode
 * - upload a doxygen tag file (e.g. from a CI/CD pipeline)
 *
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */
class remote_plugin_doxycode extends RemotePlugin
{
    /**
     * Returns details about the remote plugin methods
     *
     * @return array Information about all provided methods. {@see dokuwiki\Remote\RemoteAPI}
     */
    public function _getMethods()
    {
        return array(
            'listTagFiles' => array(
                'args' => array(),
                'return' => 'Array of Tag File Configurations',
                'doc' => 'Get the current tag file configuration of doxycode.',
            ),'uploadTagFile' => array(
                'args' => array('string', 'string'),
                'return' => 'bool',
                'name' => 'uploadTagFile',
                'doc' => 'Upload a tag file to the tag file directory.'
            ),
        );
    }

    /**
     * List all Tag File Configurations for Doxycode
     *
     * @return Array Doxyoce tag file configuration
     */
    public function listTagFiles()
    {
        /** @var helper_plugin_doxycode_tagmanager $tagmanager */
        $tagmanager = plugin_load('helper', 'doxycode_tagmanager');
        
        $tag_config = $tagmanager->loadTagFileConfig();

        return $tag_config;
    }

    /**
     * Upload a Doxycode Tag File
     *
     * The tag file will only be accepted if a configuration exists for it and if it is enabled.
     * Uploads for remote tag files will not be accepted
     *
     * @param string $filename The filename of the doxygen tag file
     * @param string $file Contents of the doxygen tag file
     * @return bool If the upload was succesful
     */
    public function uploadTagFile($filename, $file)
    {
        $tagname = pathinfo($filename, PATHINFO_FILENAME);

        /** @var helper_plugin_doxycode_tagmanager $tagmanager */
        $tagmanager = plugin_load('helper', 'doxycode_tagmanager');
        
        $tag_config = $tagmanager->loadTagFileConfig();

        // filter out disabled items
        $tag_config = $tagmanager->filterConfig($tag_config, ['isConfigEnabled']);

        // filter out remote configurations (we do not allow uploading them)
        $tag_config = $tagmanager->filterConfig($tag_config, ['isValidRemoteConfig'], true);

        // check file against existing configuration
        if (!in_array($tagname, array_keys($tag_config))) {
            return array(false);
        }

        // we have a valid configuration

        // TODO: should we always update the file?
        // we could also check if the file is updated, so mtime is not update
        // this way the cache does not get invalidated!
        $existing_hash = '';

        $existing_file = $tagmanager->getFileName($tagname);
        if (file_exists($existing_file)) {
            $existing_content = @file_get_contents($existing_file);
    
            $existing_hash = md5($existing_content);
        }
        $new_hash = md5($file);

        if ($existing_hash !== $new_hash) {
            // move file into position

            // TODO: we should also check if we have a valid tag file on hand!
            // possibilities: parse XML, check project name (make setup more complicated!)

            @file_put_contents($existing_file, $file);
        }
        
        return array(true);
    }
}

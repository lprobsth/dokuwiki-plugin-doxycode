<?php

use dokuwiki\Extension\RemotePlugin;
use dokuwiki\Remote\AccessDeniedException;

/**
 * Class remote_plugin_acl
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
                'return' => 'Array of Tag Files',
                'doc' => 'Get the list of all ACLs',
            ),'uploadTagFile' => array(
                'args' => array('string', 'file', 'array'),
                'return' => 'int',
                'name' => 'uploadTagFile',
                'doc' => 'Adds a new ACL rule.'
            ),
        );
    }

    /**
     * List all Tag File Configurations for Doxygen
     *
     * @throws AccessDeniedException
     * @return dictionary {Scope: ACL}, where ACL = dictionnary {user/group: permissions_int}
     */
    public function listTagFiles()
    {
        /** @var helper_plugin_doxycode_tagmanager $tagmanager */
        $tagmanager = plugin_load('helper', 'doxycode_tagmanager');
        
        $tag_config = $tagmanager->loadTagFileConfig();

        return $tag_config;
    }

    /**
     * Add a new entry to ACL config
     *
     * @param string $file
     * @throws AccessDeniedException
     * @return bool
     */
    public function uploadTagFile($filename,$file)
    {
        $tagname = pathinfo($filename, PATHINFO_FILENAME);

        /** @var helper_plugin_doxycode_tagmanager $tagmanager */
        $tagmanager = plugin_load('helper', 'doxycode_tagmanager');
        
        $tag_config = $tagmanager->loadTagFileConfig();

        // check file against existing configuration
        // TODO: check only for configurations that are not remote!
        if(!in_array($tagname,array_keys($tag_config))) {
            return array(false);
        }

        // we have a valid configuration

        // TODO: should we always update the file?
        // we could also check if the file is updated, so mtime is not update
        // this way the cache does not get invalidated!
        $existing_hash = '';

        $existing_file = $tagmanager->getFileName($tagname);
        if(file_exists($existing_file)) {
            $existing_content = @file_get_contents($existing_file);
    
            $existing_hash = md5($existing_content);
        }
        $new_hash = md5($file);

        if($existing_hash !== $new_hash) {
            // move file into position

            // TODO: we should also check if we have a valid tag file on hand!
            // possibilities: parse XML, check project name (make setup more complicated!)

            @file_put_contents($existing_file,$file);
        }
        
        return array(true);
    }
}

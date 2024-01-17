<?php

/**
 * DokuWiki Plugin doxycode (Helper Component)
 *
 * @license     GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */

use dokuwiki\Extension\Plugin;

/**
 * Class helper_plugin_doxycode
 *
 * This helper plugin implements some common functions for the doxygen plugin.
 * Its main use is the creation of the file dependencies of XML and HTML cache files
 * for cache validation in the other components.
 *
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */
class helper_plugin_doxycode extends Plugin
{
    /** @var helper_plugin_doxycode_tagmanager $tagmanager */
    protected $tagmanager;

    public function __construct()
    {
        $this->tagmanager = plugin_load('helper', 'doxycode_tagmanager');
    }


    public function getXMLFileDependencies(&$dependencies, $tag_conf = null)
    {

        $this->getTagFiles($dependencies, $tag_conf);

        // add the configuration file
        $this->addDefaultDependencies($dependencies);

        return $dependencies;
    }

    public function getHTMLFileDependencies(&$dependencies, $xml_cacheID, $tag_conf = null)
    {

        $this->getTagFiles($dependencies, $tag_conf);

        // add the configuration file
        $this->addDefaultDependencies($dependencies);

        $xml_cache = getCacheName($xml_cacheID, '.xml');

        // add the configuration file
        $dependencies['files'][] = DOKU_PLUGIN . $this->getPluginName() . '/helper/parser.php';
        $dependencies['files'][] = $xml_cache;

        return $dependencies;
    }

    public function getTagFiles(&$dependencies, $tag_conf = null)
    {
        // generate the full filepaths for the $tag_conf entries

        foreach ($tag_conf as $key => $conf) {
            // TODO: should we check if the file exists?
            $dependencies['files'][] = $this->tagmanager->getTagFileDir() . $key . '.xml';
        }
    }

    protected function addDefaultDependencies(&$dependencies)
    {
        $dependencies['files'][] = $this->tagmanager->getTagFileDir() . 'tagconfig.json';
        // add the doxygen configuration template
        $dependencies['files'][] = DOKU_PLUGIN . $this->getPluginName()  . '/doxygen.conf';

        // easy cache invalidation on code change
        // these files affect all types - we should rebuild the doxygen snippets completetly
        $dependencies['files'][] = DOKU_PLUGIN . $this->getPluginName() . '/syntax/snippet.php';
        $dependencies['files'][] = DOKU_PLUGIN . $this->getPluginName() . '/helper/buildmanager.php';
        $dependencies['files'][] = DOKU_PLUGIN . $this->getPluginName() . '/helper/tagmanager.php';
        $dependencies['files'][] = DOKU_PLUGIN . $this->getPluginName() . '/helper.php';
    }

    public function getPHPFileDependencies(&$dependencies)
    {

        $dependencies['files'][] = $this->tagmanager->getTagFileDir() . 'tagconfig.json';
        // add the doxygen configuration template
        $dependencies['files'][] = DOKU_PLUGIN . $this->getPluginName()  . '/doxygen.conf';

        // easy cache invalidation on code change
        $dependencies['files'] = array_merge(
            $dependencies['files'],
            glob(DOKU_PLUGIN . $this->getPluginName() . '/*.php')
        );
        $dependencies['files'] = array_merge(
            $dependencies['files'],
            glob(DOKU_PLUGIN . $this->getPluginName() . '/syntax/*.php')
        );
        $dependencies['files'] = array_merge(
            $dependencies['files'],
            glob(DOKU_PLUGIN . $this->getPluginName() . '/helper/*.php')
        );
    }
}

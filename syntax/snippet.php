<?php
/**
 * DokuWiki Plugin doxycode (Snippet Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\Cache\Cache; 

/**
 * Class syntax_plugin_doxycode_snippet
 * 
 * This is the main syntax of the doxycode plugin.
 * It takes the code from a code snippet and renders it with doxygen for cross referencing.
 * 
 * The rendering is split into building of doxygen XML files with the helper_plugin_doxycode_buildmanager
 * helper and parsing of the XML files to HTML with the helper_plugin_doxycode_parser helper.
 * 
 * If the sqlite plugin is installed it builds the XML through task runner jobs/task if enabled by the user,
 * force enabled for a tag file or if a doxygen instance is already running.
 * 
 * Which tag files and which cache files are used in the page is stored in the meta data of the page. This
 * then used in the action_plugin_doxycode plugin for invalidating the cache.
 * 
 * If a snippet is build through the task runner a marker is placed in the code snippet for dynamically loading
 * the snippet and informing the user of the build progress through AJAX calls that are handled by the
 * action_plugin_doxycode plugin.
 */
class syntax_plugin_doxycode_snippet extends SyntaxPlugin {

    private $doc;

    function __construct() {
    }

    public function getType() {
        return 'substition';
    }

    public function getSort() {
        return 158;
    }

    public function connectTo($mode) {
        $this->Lexer->addEntryPattern('<doxycode.*?>(?=.*?</doxycode>)',$mode,'plugin_doxycode_snippet');
        $this->Lexer->addSpecialPattern('<doxycode.*?/>',$mode,'plugin_doxycode_snippet');
    }

    public function postConnect() {
        $this->Lexer->addExitPattern('</doxycode>','plugin_doxycode_snippet');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler){
        static $args;
        switch ($state) {
            case DOKU_LEXER_ENTER : 
            case DOKU_LEXER_SPECIAL : 
                // Parse the attributes and content here
                $args = $this->_parseAttributes($match);
                return [$state, $args];
            case DOKU_LEXER_UNMATCHED : 
                // Handle internal content if any
                return [$state, ['conf' => $args, 'text' => $match]];
            case DOKU_LEXER_EXIT :
                return [$state, $args];
        }
        return [];
    }

    private function _parseAttributes($string) {
        // Use regular expressions to parse attributes
        // Return an associative array of attributes

        $args = [];

        // Split the string by spaces and get the last element as the filename
        $parts = preg_split('/\s+/', trim($string));
        $lastPart = array_pop($parts); // Potentially the filename
    
        // Remove ">" if it is at the end of the last part
        $lastPart = rtrim($lastPart, '>');
    
        // Check if the last part is a filename with an extension
        if (preg_match('/^\w+\.\w+$/', $lastPart)) {
            $args['filename'] = $lastPart;
        } else {
            // If it's not a filename, add it back to the parts array
            $parts[] = $lastPart;
        }
    
        // Re-join the parts without the filename
        $remainingString = implode(' ', $parts);
    
        // Regular expression to match key="value" pairs and flag options
        $pattern = '/(\w+)="([^"]*)"|(\w+)/';
        preg_match_all($pattern, $remainingString, $matches, PREG_SET_ORDER);
    
        foreach ($matches as $m) {
            if (!empty($m[1])) {
                // This is a key="value" argument
                $args[$m[1]] = $m[2];
            } elseif (!empty($m[3])) {
                // This is a flag option
                $args[$m[3]] = 1;
            }
        }

        unset($args['doxycode']);

        // validate the settings
        // we need at least $text from DOKU_LEXER_UNMATCHED or VCS src
        // TODO: if VCS import is implemented later we need to implement this check here!

        // if we don't have filename, we need the language extension!
        if(!isset($args['language']) && isset($args['filename'])) {
            $args['language'] = pathinfo($args['filename'], PATHINFO_EXTENSION);
        }

        // TODO: sort arguments, so hashes for the attributes always stay the same
        // otherwise the hash might change if we change the order of the arguments in the page
    
        return $args;
    }

    private function _prepareText(&$text) {

        if($text[0] == "\n") {
            $text = substr($text, 1);
        }
        if(substr($text, -1) == "\n") {
            $text = substr($text, 0, -1);
        }
    }

    public function render($mode, Doku_Renderer $renderer, $data) {

        list($state, $data) = $data;
        if ($mode === 'xhtml') {

            $this->doc = '';

            // DOKU_LEXER_ENTER and DOKU_LEXER_SPECIAL: output the start of the code block
            if($state == DOKU_LEXER_SPECIAL || $state == DOKU_LEXER_ENTER) {
                $this->_startCodeBlock("file",$data['filename']);
            }

            // DOKU_LEXER_UNMATCHED: call renderer and output the content to the document
            if($state == DOKU_LEXER_UNMATCHED) {
                $conf = $data['conf'];
                $text = $data['text'];

                // strip empty lines at start and end
                $this->_prepareText($text);

                if(!isset($conf['language'])) {
                    $renderer->doc .= $this->getLang('error_language_missing');
                    return;
                }

                // load helpers
                // the helper functions were split so that tagmanager can be used alone in admin.php,
                // parser can be reused by other plugins, better structure, ...

                /** @var helper_plugin_doxycode_tagmanager $tagmanager */
                $tagmanager = plugin_load('helper', 'doxycode_tagmanager');
                /** @var helper_plugin_doxycode_parser $parser */
                $parser = plugin_load('helper', 'doxycode_parser');
                /** @var helper_plugin_doxycode_buildmanager $buildmanager */
                $buildmanager = plugin_load('helper', 'doxycode_buildmanager');
                /** @var helper_plugin_doxycode $helper */
                $helper = plugin_load('helper', 'doxycode');

                // get the tag file configuration from the tag file name list from the syntax
                $tag_conf = $tagmanager->getFilteredTagConfig($conf['tagfiles']);


                // load HTML from cache

                // TODO: is it ok to reuse the same HTML file for multiple instances with the same settings?
                // example problems: ACL? tag file settings per page?

                // the cache name is the hash from options + code
                $html_cacheID = md5(json_encode($buildmanager->filterDoxygenAttributes($conf,true)) . $text);  // cache identifier for this code snippet
                $xml_cacheID = md5(json_encode($buildmanager->filterDoxygenAttributes($conf,false)) . $text);  // cache identifier for this code snippet

                $html_cache = new Cache($html_cacheID, '.html');
                $xml_cache = new Cache($xml_cacheID, '.xml');

                // use the helper for loading the file dependencies (conf, tag_conf, tagfiles)
                $depends = [];
                $helper->getHTMLFileDependencies($depends,$xml_cacheID,$tag_conf);

                // check if we have parsed HTML ready
                if($html_cache->useCache($depends)) {
                    // we have a valid HTML!

                    if($cachedContent = @file_get_contents($html_cache->cache)) {
                        // append cached HTML to document
                        $renderer->doc .= $cachedContent;
                    } else {
                        msg($this->getLang('error_cache_not_readable'), 2);
                    }

                    // do not invoke other actions!
                    return;
                }

                // no valid HTML was found
                // we now try to use the cached XML

                $depends = [];
                $helper->getXMLFileDependencies($depends,$tag_conf);

                if(!$xml_cache->useCache($depends)) {
                    // no valid XML cache available

                    $conf['taskID'] = md5(json_encode($buildmanager->filterDoxygenAttributes($conf)));

                    // if the "render_task" option is set:
                    // output file to tmp folder for a configuration and save task in sqlite
                    // 'task runner' -> is doxygen task runner available for this page?
                    // -> loop over all meta entries
                    // -> each meta entry: unique settings comination for doxygen (tag files)
                    // -> run doxygen
                    // -> then check if rendered version is available? otherwise output information here
                    $conf['render_task'] = $tagmanager->isForceRenderTaskSet($tag_conf);

                    // if job handling through sqlite is not available, we get STATE_NON_EXISTENT
                    // if job handling is available the building of the XML might be already in progress
                    $job_state = $buildmanager->getJobState($xml_cacheID);

                    $buildsuccess = false;  // vary output depending on availability of job handling and doxygen builder

                    // if the state is finished or non existent, we need to either schedule or build now
                    if($job_state == helper_plugin_doxycode_buildmanager::STATE_FINISHED ||
                       $job_state == helper_plugin_doxycode_buildmanager::STATE_NON_EXISTENT ||
                       $job_state == helper_plugin_doxycode_buildmanager::STATE_ERROR) {
                        if(!$conf['render_task'] || plugin_isdisabled('sqlite')) {
                            // either job handling is not available or this snippet should immediately be rendered

                            // if lock is present: try to append as job!
                            $buildsuccess = $buildmanager->tryBuildNow($xml_cacheID,$conf,$text,$tag_conf);
                        } else {
                            // append as job
                            $buildmanager->addBuildJob($xml_cacheID,$conf,$text,$tag_conf);
                        }
                    }

                    // if snippet could not be build immediately or run through job handling
                    if(!$buildsuccess || $conf['render_task']) {
                        // get job state again
                        $job_state = $buildmanager->getJobState($xml_cacheID);

                        // add marker for javascript dynamic loading of snippet
                        $renderer->doc .= '<div class="doxycode_marker" data-doxycode-xml-hash="' . $xml_cacheID . 
                                            '" data-doxycode-html-hash="' . $html_cacheID . '">';

                        // check if we have a job for this snippet and what its state is
                        switch($job_state) {
                            case helper_plugin_doxycode_buildmanager::STATE_FINISHED: {
                                // this should be a good sign - next try to load the file
                                break;
                            }
                            case helper_plugin_doxycode_buildmanager::STATE_NON_EXISTENT: {
                                // task runner not available (missing sqlite?)
                                $renderer->doc .= $this->getLang('msg_not_existent');
                                break;
                            }
                            case helper_plugin_doxycode_buildmanager::STATE_RUNNING: {
                                $renderer->doc .= $this->getLang('msg_running');
                                break;
                            }
                            case helper_plugin_doxycode_buildmanager::STATE_SCHEDULED: {
                                $renderer->doc .= $this->getLang('msg_scheduled');
                                break;
                            }
                        }

                        $renderer->doc .= '</div';
                    }
                }

                // render task is only set if we previously determined with a missing XML cache file that
                // the snippet should be built through job handling
                if(!$conf['render_task'] || plugin_isdisabled('sqlite')) {
                    // here we ignore the default decision
                    // the XML should be available in this case
                    // otherwise purging leaves us with empty code snippets
                    if(file_exists($xml_cache->cache)) {
                        // we have a valid XML!

                        $xml_content = @file_get_contents($xml_cache->cache);

                        $rendered_text = $parser->renderXMLToDokuWikiCode($xml_content,$conf['linenumbers'],$tag_conf);
                        
                        // save content to cache
                        @file_put_contents($html_cache->cache,$rendered_text);

                        // append cached HTML to document
                        $renderer->doc .= $rendered_text;
                    }
                }

                return true;
            }

            // DOKU_LEXER_EXIT: output the end of the code block
            if($state == DOKU_LEXER_EXIT) {
                $this->_endCodeBlock("file",$data['filename']);
            }

            $renderer->doc .= $this->doc;

        } elseif ($mode === 'metadata') {
            if($state == DOKU_LEXER_SPECIAL || $state == DOKU_LEXER_ENTER) {
                /** @var helper_plugin_doxycode_tagmanager $tagmanager */
                $tagmanager = plugin_load('helper', 'doxycode_tagmanager');

                $tag_conf = $tagmanager->getFilteredTagConfig($data['tagfiles']);

                // save used tag files in this page for cache invalidation if a newer tag file is available
                // TODO: what happens if a tag file is already present in the meta data?
                foreach($tag_conf as $key => $conf) {
                    $renderer->meta['doxycode']['tagfiles'][] = $key;
                }
            }

            if($state == DOKU_LEXER_UNMATCHED) {
                /** @var helper_plugin_doxycode_buildmanager $buildmanager */
                $buildmanager = plugin_load('helper', 'doxycode_buildmanager');
                $conf = $data['conf'];
                $text = $data['text'];

                // this is needed so the cacheID is the same as in the xhtml context
                $this->_prepareText($text);

                $xml_cacheID = md5(json_encode($buildmanager->filterDoxygenAttributes($conf,false)) . $text);
                $html_cacheID = md5(json_encode($buildmanager->filterDoxygenAttributes($conf,true)) . $text);

                // add cache files to render context so page cache is invalidated if a new XML or HTML is available
                $renderer->meta['doxycode']['xml_cachefiles'][] = $xml_cacheID;
                $renderer->meta['doxycode']['html_cachefiles'][] = $html_cacheID;
            }
        }



        return true;
    }

    private function _startCodeBlock($type,$filename = null) {
        global $INPUT;
        global $ID;
        global $lang;

        $ext = '';
        if($filename) {
            // add icon
            list($ext) = mimetype($filename, false);
            $class = preg_replace('/[^_\-a-z0-9]+/i', '_', $ext);
            $class = 'mediafile mf_'.$class;

            $offset = 0;
            if ($INPUT->has('codeblockOffset')) {
                $offset = $INPUT->str('codeblockOffset');
            }
            $this->doc .= '<dl class="'.$type.'">'.DOKU_LF;
            $this->doc .= '<dt><a href="' .
                exportlink(
                    $ID,
                    'code',
                    array('codeblock' => $offset + 0)
                ) . '" title="' . $lang['download'] . '" class="' . $class . '">';
            $this->doc .= hsc($filename);
            $this->doc .= '</a></dt>'.DOKU_LF.'<dd>';
        }

        $class = 'code'; //we always need the code class to make the syntax highlighting apply
        if($type != 'code') $class .= ' '.$type;

        $this->doc .= "<pre class=\"$class $ext\">";
    }

    private function _endCodeBlock($type,$filename = null) {
        $class = 'code'; //we always need the code class to make the syntax highlighting apply
        if($type != 'code') $class .= ' '.$type;

        $this->doc .= '</pre>' . DOKU_LF;

        if($filename) {
            $this->doc .= '</dd></dl>'.DOKU_LF;
        }
    }
}

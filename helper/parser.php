<?php
/**
 * DokuWiki Plugin doxycode (Parser Helper Component)
 *
 * @license     GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */

if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');


use \dokuwiki\Extension\Plugin;

class helper_plugin_doxycode_parser extends Plugin {


    /* The `mapping` variable is an associative array that maps certain highlight classes in the
    XML file to their corresponding DokuWiki CSS classes. This mapping is used in the
    `renderXMLToDokuWikiCode()` method to convert the XML code to DokuWiki syntax. */
    private $mapping = array(
        'comment' => 'co1',
        'keywordtype' => 'kw0',
        'keywordflow' => 'kw1',
        'preprocessor' => 'co2',
        'stringliteral' => 'st0'
    );
    
    /**
     * The function `renderXMLToDokuWikiCode` takes an XML string, line number setting, and tag
     * configuration as input and returns DokuWiki code.
     * 
     * @param string A string containing XML code.
     * @param boolean A boolean value indicating whether line numbers should be included in the output
     * or not.
     * @param array The `tag_conf` parameter is an optional parameter that allows you to specify a
     * configuration for parsing specific XML tags. It is used in the `_parseDoxygenXMLElement` function,
     * which is called for each codeline element in the XML.
     * 
     * @return string output string, which contains the DokuWiki code generated from the XML input.
     */
    public function renderXMLToDokuWikiCode($xmlString,$line_numbers,$tag_conf = null) {
        $output = '';


        $dom = new DOMDocument();
        $dom->loadXML($xmlString);

        // find the programlisting element inside the doxygen XML
        $xpath = new DOMXPath($dom);
        $programListing = $xpath->query('//programlisting')->item(0);

        // if linenumber setting is present output list elements around the codelines!
        if($line_numbers) {
            $output .= '<ol>';
            
            // $this->doc = str_replace("\n", "", $this->doc);
        }

        // loop over the codeline elements
        foreach($programListing->childNodes as $codeline) {
            if($codeline->hasChildNodes()) {
                if($line_numbers) {
                    $output .= '<li class=\"li1\"><div>';
                }

                $this->_parseDoxygenXMLElement($codeline,$output,$tag_conf);

                if($line_numbers) {
                    $output .= '</div></li>';
                } else {
                    $output .= DOKU_LF;
                }
            }
        }


        if($line_numbers) {
            $output .= '</ol>';
        }


        return $output;
    }

    private function _parseDoxygenXMLElement($element,&$output,$tag_conf = null) {
        global $conf;

        // helper:
        // highlight = <span> element
        // sp = ' ' (space)
        // ref = <a href="...">

        foreach ($element->childNodes as $node) {
            if ($node->nodeType === XML_ELEMENT_NODE) {

                switch($node->nodeName) {
                    /* The `case 'highlight'` matches the syntax highlighting elements inside the XML and matches these to dokuwiki CSS classes for code blocks. */
                    case 'highlight':
                        $output .= '<span';
                        if($node->hasAttribute('class')) {
                            $output .= ' class="';
                            if($this->mapping[$node->getAttribute('class')] !== '') {
                                $output .= $this->mapping[$node->getAttribute('class')];
                            } else {
                                // if we cannot map a class from dokuwiki - just use the doxygen class for now
                                $output .= $node->getAttribute('class');
                            }
                            $output .= '"';
                        }
                        $output .= '>';
                        // check if $element has children or content
                        if($node->hasChildNodes()) {
                            // parse the elements inside the span element
                            $this->_parseDoxygenXMLElement($node,$output,$tag_conf);
                        }
                        $output .= '</span>';
                        break;
                    case 'sp':
                        // sp is just converted to space
                        $output .= ' ';
                        break;
                    case 'ref':
                        $output .= "<a";
                        if($node->hasAttribute('external') && $node->hasAttribute('refid')) {
                            $output .= ' href="';
                            $output .= $this->_convertRefToURL($node,$tag_conf);
                            $output .= '" target="' . $conf['target']['extern'];
                            $output .= '"';
                        }
                        $output .= ">";
                        $output .= $node->nodeValue;
                        $output .= "</a>";
                        break;
                    default:
                        break;
                }
            }

            // plain text inside an element is just appended to the document output
            if($node->nodeType === XML_TEXT_NODE) {
                $output .= $node->nodeValue;
            }

        }

    }

    private function _convertRefToURL(&$node,$tag_conf = null) {

        $output = '';

        $external = $node->getAttribute('external');
        $ref = $node->getAttribute('refid');


        /** @var helper_plugin_doxycode_tagmanager $tagmanager */
        $tagmanager = plugin_load('helper', 'doxycode_tagmanager');

        foreach($tag_conf as $key => $conf) {
            if($tagmanager->getTagFileDir() . $key . '.xml' === $external) {
                $output .= $conf['docu_url'];
                break;
            }
        }

        $kindref = '';

        if($node->hasAttribute('kindref')) {
            $kindref = $node->getAttribute('kindref');
        }

        if($kindref === 'member') {
            $lastUnderscorePos = strrpos($ref, '_');

            $first = substr($ref, 0, $lastUnderscorePos);
            $last = substr($ref, $lastUnderscorePos + 2);

            $output .= $first;
            $output .= ".html#";
            $output .= $last;
        } else {
            // probably 'compound'
            $output .= $ref . ".html";
        }

        return $output;
    }
}
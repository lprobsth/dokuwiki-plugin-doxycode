<?php

/**
 * DokuWiki Plugin doxycode (Taglist Syntax Component)
 *
 * @license     GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */

use dokuwiki\Extension\SyntaxPlugin;

/**
 * Class syntax_plugin_doxycode_taglist
 *
 * This syntax plugin renders a table with all available tag files.
 * It can be used to inform users which tag files can be used by which names in the snippet syntax.
 */
class syntax_plugin_doxycode_taglist extends SyntaxPlugin
{
    public function getType()
    {
        return 'substition';
    }

    public function getSort()
    {
        // TODO: which sort number?
        return 159;
    }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('<listtagfiles.*?/>', $mode, 'plugin_doxycode_taglist');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        static $args;
        switch ($state) {
            case DOKU_LEXER_SPECIAL:
                // TODO: do we expect parameters here?
                // add columns like reload period, file state?
                // TODO: implement option for only displaying enabled configurations!

                // Parse the attributes and content here
                // $args = $this->_parseAttributes($match);
                return [$state, ];
        }
        return [];
    }

    /**
     *
     */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode != 'xhtml') return;


        /** @var helper_plugin_doxycode_tagmanager $tagmanager */
        $tagmanager = plugin_load('helper', 'doxycode_tagmanager');

        $config = $tagmanager->loadTagFileConfig();

        $renderer->doc .= '<div class="table">';
        $renderer->doc .= '<table class="inline">';

        $renderer->doc .= '<thead>';
        $renderer->doc .= '<tr>';
        $renderer->doc .= '<th>' . $this->getLang('tag_conf_enabled') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('tag_conf_local_name') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('tag_conf_docu_url') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('tag_conf_remote_url') . '</th>';
        $renderer->doc .= '</tr>';
        $renderer->doc .= '</thead>';

        $renderer->doc .= '<tbody>';
        foreach ($config as $key => $conf) {
            $renderer->doc .= '<tr>';
            // TODO: display enabled state as locked checkbox
            $renderer->doc .= '<td>' . $conf['enabled'] . '</td>';
            $renderer->doc .= '<td>' . $key . '</td>';
            $renderer->doc .= '<td><a href="' . $conf['docu_url'] . '">' . $conf['docu_url'] . '</a></td>';
            $renderer->doc .= '<td><a href="' . $conf['remote_url'] . '">' . $conf['remote_url'] . '</a></td>';
            // TODO: should we enable more information? last updated, reason for disabled state, ...?
            $renderer->doc .= '</tr>';
        }
        $renderer->doc .= '</tbody>';

        $renderer->doc .= '</table>';
        $renderer->doc .= '</div>';
    }
}

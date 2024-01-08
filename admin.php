<?php
/**
 * DokuWiki Plugin doxycode (Admin Component)
 * 
 * @license     GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */
 
use dokuwiki\Extension\AdminPlugin;
use dokuwiki\Form\Form;

/**
 * Class admin_plugin_doxycode
 * 
 * This admin plugin implements the management of tag files from differen doxygen
 * documentations for building cross referenced code snippets.
 * 
 * It lists all currently configured tag files and all tag files present in the file system
 * of the plugin. The user can add new tag file configurations via upload or by defining a new configuration.
 * 
 * The admin interface uses the helper_plugin_doxycode_tagmanager helper plugin for loading the current tag file
 * list. On save it also uses the helper for storing the configuration in a json file.
 * 
 * On save and update it also checks if a configuration is valid and can stay enabled.
 * 
 * If a new remote config was defined, the action component of this plugin tries to download the tag file.
 * 
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */
class admin_plugin_doxycode extends AdminPlugin {
 
    /** @var helper_plugin_doxycode_tagmanager $helper */
    private $helper;
    private $tag_config;

    // TODO: these should be minimum widths
    private $conf_column_widths = array(
        'local_name' => 16,
        'docu_url' => 35,
        'remote_url' => 35,
        'update_period' => 5,
        'description' => 40
    );

    function __construct() {
        $this->helper = plugin_load('helper', 'doxycode_tagmanager');

        // load files
        $tag_files = $this->helper->listTagFiles();

        // load tag_config
        $tag_config = $this->helper->loadTagFileConfig();

        // merge both arrays for rendering
        // prioritize the tag_config and overwrite elements from files!
        $this->tag_config =  array_merge($tag_files, $tag_config);
    }
 
    /**
     * handle user request
     */
    public function handle() {
        global $INPUT;
        global $_FILES;
 
        if (!$INPUT->has('cmd')) return; // first time - nothing to do
 
        if (!checkSecurityToken()) return;
        if (!is_array($INPUT->param('cmd'))) return;

        $cmd = $INPUT->arr('cmd');

        $new_tag_config = $INPUT->arr('tag_config');

        // handle upload command	
        // if a new file was added, we move the file to the tagfile directory
        // and add the file to the tagfile configuration for rendering
        // on the next load of the page the tag file will be loaded to configuration
        // from the tag file list from the directory
        if($cmd['update'] && isset($_FILES['upload']) && $_FILES['upload']['error'] != UPLOAD_ERR_NO_FILE) {
            if ($_FILES['upload']['error'] == 0){
                if ('xml' != pathinfo($_FILES['upload']['name'], PATHINFO_EXTENSION)){
                    msg(sprintf($this->getLang('admin_err_no_xml_file'),$_FILES['upload']['name']), 2);
                }else {
                    move_uploaded_file($_FILES['upload']['tmp_name'],
                        DOKU_PLUGIN.'doxycode/tagfiles/'.$_FILES['upload']['name']);
                    msg(sprintf($this->getLang('admin_info_upload_success'),$_FILES['upload']['name']), 1);
                    $this->tag_config[pathinfo($_FILES['upload']['name'], PATHINFO_FILENAME)] = [];
                }
            } else {
                msg($this->getLang('admin_err_upload'), 2);
            }
        }

        // add new element from form
        if(isset($new_tag_config['new'])) {
            // do we have a valid new entry && is this entry name not already set?
            if(strlen($new_tag_config['new']['new_name']) > 0 && !isset($this->tag_config[$new_tag_config['new']['new_name']])) {
                $newKey = $new_tag_config['new']['new_name'];

                // unset the temporary new name that otherwise would mean a rename/move
                unset($new_tag_config['new']['new_name']);

                // add new tag_config element to global config
                $this->tag_config[$newKey] = $new_tag_config['new'];
                msg(sprintf($this->getLang('admin_info_new_tag_config'),$newKey), 1);
            }
            unset($new_tag_config['new']); // Remove the 'new' placeholder
        }

        // update our configuration from the input data
        if($cmd['save'] || $cmd['update']) {
            foreach($new_tag_config as $key => $tag_conf) {
                $this->tag_config[$key] = $tag_conf;
            }
        }

        // check if settings are valid for the enabled state
        if($cmd['save'] || $cmd['update']) {
            foreach($this->tag_config as $key => &$tag_conf) {
                // if element is disable continue
                if(!isset($tag_conf['enabled']) || !$tag_conf['enabled']) continue;

                // if docu_url is missing
                if(strlen($tag_conf['docu_url']) <= 0) {
                    $tag_conf['enabled'] = false;
                    continue;
                }

                if(strlen($tag_conf['remote_url']) > 0 && strlen($tag_conf['update_period']) <= 0) {
                    $tag_conf['enabled'] = false;
                    continue;
                }
            }

            // TODO: really necessary here?
            unset($tag_conf);
        }

        if($cmd['save']) {
            // delete entries that are marked for deletion
            foreach($this->tag_config as $key => $tag_conf) {
                if(isset($tag_conf['delete']) && $tag_conf['delete']) {
                    unset($this->tag_config[$key]);

                    // delete the tag file if it exists!
                    $filename = $this->helper->getTagFileDir() . $key . '.xml';
                    if(file_exists($filename)) {
                        unlink($filename);
                        msg(sprintf($this->getLang('admin_info_tag_deleted'),pathinfo($filename, PATHINFO_BASENAME)), 1);
                    }
                }
            }

            // handle renames
            foreach($this->tag_config as $key => $tag_conf) {
                if(isset($tag_conf['new_name']) && $key !== $tag_conf['new_name']) {
                    // TODO: check if an entry with this newName already exists!
                    $newName = $tag_conf['new_name'];
                    unset($this->tag_config[$key]);
                    $this->tag_config[$newName] = $tag_conf;
                    unset($this->tag_config[$newName]['new_name']);

                    rename( $this->helper->getTagFileDir() . $key . 'xml', $this->helper->getTagFileDir() . $newName . '.xml');

                    // TODO: rename tag in page!

                    // TODO: notify user through msg that the tag file was renamed!
                }
            }

            $this->helper->saveTagFileConfig($this->tag_config);
        }
    }
 
    /**
     * output appropriate html
     */
    public function html() {
        global $ID;#
        global $conf;
        global $lang;

        // form header
        echo '<div id="doxycode__tagmanager">';

        $form = new Form(['enctype' => 'multipart/form-data']);

        // new file
        $form->addElement(new dokuwiki\Form\InputElement('file','upload',$this->getLang('admin_upload')));

        $form->addHTML('<br>');
        $form->addHTML('<br>');

        // start table for existing configurations
        $form->addTagOpen('div')->addClass('table');
        $form->addTagOpen('table')->addClass('inline');

        // add header
        $form->addTagOpen('thead');

        $form->addTagOpen('tr');

        $form->addHTML('<th>' . $this->getLang('admin_conf_delete') . '</th>');
        $form->addHTML('<th>' . $this->getLang('admin_conf_enabled') . '</th>');
        $form->addHTML('<th>' . $this->getLang('admin_conf_force_runner') . '</th>');
        $form->addHTML('<th>' . $this->getLang('admin_conf_local_name') . '</th>');
        $form->addHTML('<th>' . $this->getLang('admin_conf_mtime') . '</th>');
        $form->addHTML('<th>' . $this->getLang('admin_conf_docu_url') . '</th>');
        $form->addHTML('<th>' . $this->getLang('admin_conf_remote_url') . '</th>');
        $form->addHTML('<th>' . $this->getLang('admin_conf_update_period') . '</th>');
        $form->addHTML('<th>' . $this->getLang('admin_conf_description') . '</th>');

        $form->addTagClose('tr');

        $form->addTagClose('thead');

        // add body
        $form->addTagOpen('tbody');

        foreach($this->tag_config as $key => $tag_conf) {
            $form->addTagOpen('tr');

            $form->addTagOpen('td');
            $checkbox = $form->addCheckbox('tag_config[' . $key . '][delete]')
                ->useInput(false);
                if($tag_conf['delete']) $checkbox->attrs(['checked' => 'checked']);
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $checkbox = $form->addCheckbox('tag_config[' . $key . '][enabled]')
                ->useInput(false);
            if($tag_conf['enabled']) $checkbox->attrs(['checked' => 'checked']);
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $checkbox = $form->addCheckbox('tag_config[' . $key . '][force_runner]')
                ->useInput(false);
            if($tag_conf['force_runner']) $checkbox->attrs(['checked' => 'checked']);
            $form->addTagClose('td');

            // TODO: add red highlight if this file does not exist
            $form->addTagOpen('td');
            $new_name = $form->addTextInput('tag_config[' . $key . '][new_name]')
                ->attrs(['size' => $this->conf_column_widths['local_name']])
                ->useInput(false);

            if(file_exists($this->helper->getFileName($key))) {
                $new_name->attrs(['style' => 'background-color: LightGreen']);
            } else {
                $new_name->attrs(['style' => 'background-color: LightCoral']);
            }

            if(isset($tag_conf['new_name'])) {
                $new_name->val($tag_conf['new_name']);
            } else {
                $new_name->val($key);
            }
            $form->addTagClose('td');

            // print file mtime for better understanding of update mechanism by admin
            $form->addTagOpen('td');
            if(file_exists($this->helper->getFileName($key))) {
                $form->addLabel(dformat(@filemtime($this->helper->getFileName($key))));
            }
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $form->addTextInput('tag_config[' . $key . '][docu_url]')
                ->attrs(['size' => $this->conf_column_widths['docu_url']])
                ->useInput(false)
                ->val($tag_conf['docu_url']);
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $form->addTextInput('tag_config[' . $key . '][remote_url]')
                ->attrs(['size' => $this->conf_column_widths['remote_url']])
                ->useInput(false)
                ->val($tag_conf['remote_url']);
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $period = $form->addTextInput('tag_config[' . $key . '][update_period]')
                ->attrs(['size' => $this->conf_column_widths['update_period']])
                ->useInput(false)
                ->val($tag_conf['update_period']);

            if($tag_conf['update_period'] > 0) {
                $timestamp = $conf['last_update'] ? $conf['last_update'] : 0;
                $now = time();

                if($now - $tag_conf['update_period'] >= $timestamp) {
                    $period->attrs(['style' => 'background-color: LightGreen']);
                } else {
                    $period->attrs(['style' => 'background-color: LightCoral']);
                }
            }
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $form->addTextInput('tag_config[' . $key . '][description]')
                ->attrs(['size' => $this->conf_column_widths['description']])
                ->useInput(false)
                ->val($tag_conf['description']);
            $form->addTagClose('td');

            $form->addTagClose('tr');
        }

        // add 'create new' entry

        // TODO: break table so 'create new' stands out more clearly

        $form->addTagOpen('tr');
            $form->addTagOpen('td')
                ->attrs(['colspan' => 6]);
            $form->addHTML($this->getLang('admin_new_entry'));
            $form->addTagClose('td');
        $form->addTagClose('tr');

        $form->addTagOpen('tr');
            $form->addHTML('<td></td>');

            $form->addTagOpen('td');
            $form->addCheckbox('tag_config[new][enabled]')
                ->useInput(false);
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $form->addCheckbox('tag_config[new][force_runner]')
                ->useInput(false);
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $form->addTextInput('tag_config[new][new_name]')
                ->attrs(['size' => $this->conf_column_widths['local_name']])
                ->useInput(false);
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $form->addTextInput('tag_config[new][docu_url]')
                ->attrs(['size' => $this->conf_column_widths['docu_url']])
                ->useInput(false);
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $form->addTextInput('tag_config[new][remote_url]')
                ->attrs(['size' => $this->conf_column_widths['remote_url']])
                ->useInput(false);
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $form->addTextInput('tag_config[new][update_period]')
                ->attrs(['size' => $this->conf_column_widths['update_period']])
                ->useInput(false);
            $form->addTagClose('td');

            $form->addTagOpen('td');
            $form->addTextInput('tag_config[' . $key . '][description]')
                ->attrs(['size' => $this->conf_column_widths['description']])
                ->useInput(false);
            $form->addTagClose('td');
        $form->addTagClose('tr');
        

        $form->addTagClose('tbody');

        // end table
        $form->addTagClose('table');
        $form->addTagClose('div');

        $form->addButton('cmd[save]',$lang['btn_save'])->attrs(['accesskey' => 's']);
        $form->addButton('cmd[update]',$lang['btn_update']);

        echo $form->toHTML();


        echo '</div>';  // id=doxycode__tagmanager
    }

    /**
     * Return true for access only by admins (config:superuser) or false if managers are allowed as well
     *
     * @return bool
     */
    public function forAdminOnly() {
        return false;
    }
 
}
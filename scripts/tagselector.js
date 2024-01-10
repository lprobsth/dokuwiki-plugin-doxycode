
/**
 * The Tag File Selector Wizard
 * 
 * This code is used in the toolbar entry of the doxycode plugin for quickly selecting available
 * tag files from the tag file configuration for a code snippet.
 * 
 * It opens a floating jQuery dialog that is inspired from the linkwiz floating dialog.
 * The user can filter all available tag file entries with a search string and select all
 * tag files to be used via checkboxes for each entry.
 * 
 * On load the tagselector tries to find a doxygen syntax near the cursor in the editor and loads
 * the already used tag file names into the dialog. Tag files can quickly be added by clicking on
 * a tag file entry.
 *
 * @author      Lukas Probsthain <lukas.probsthain@gmail.com>
 */
var doxycode_tagselector = {

    /**
     * The main div that holds the tagselector for rendering
     * @type {jQuery}
     */
    $container: null,

    /**
     * The search text input for filtering the available tag files
     * @type {jQuery}
     */
    $search: null,

    /**
     * DIV for rendering the tag name list as a table
     * @type {DOM/Element}
     */
    result: null,
    timer: null,
    textArea: null,
    selected: -1,             // the element in the result that is currently marked with hotkeys
    /** Selection of the doxycode syntax in the edit textArea @type {selection_class} */
    doxycodeSelected: null,     // <doxycode ...> syntax that was near the cursor in the edit textArea
    selection: null,            // the old text selection in the edit textArea
    tagNames: [],

    /**
     * Initialize the tag file selector by creating the needed HTML
     * and attaching the eventhandlers
     */
    init: function($editor){
        // position relative to the text area
        var pos = $editor.position();

        if(doxycode_tagselector.$container) {
            // if we already have a container ready do nothing here
            return;
        }

        // create HTML Structure
        doxycode_tagselector.$container = jQuery(document.createElement('div'))
            .dialog({
                autoOpen: false,
                draggable: true,
                title: LANG.plugins.doxycode.tag_selector_title,
                resizable: false,
                buttons: [
                    {
                        text: LANG.plugins.doxycode.tag_selector_btn_insert,
                        click: function() {
                            doxycode_tagselector.insertTagNames();
                            doxycode_tagselector.hide();
                        }
                    },{
                        text: LANG.plugins.doxycode.tag_selector_btn_update,
                        click: function() {
                            doxycode_tagselector.updateTagNames();
                        }
                    }
                ]
            })
            .html(
                '<div>'+LANG.plugins.doxycode.tag_selector_search+' <input type="text" class="edit" id="doxycode__tagselector_search" autocomplete="off" /></div>'+
                '<div id="doxycode__tagselector_result"></div>'
                )
            .parent()
            .attr('id','doxycode__tagselector')
            .css({
                'position':    'absolute',
                'top':         (pos.top+20)+'px',
                'left':        (pos.left+80)+'px'
                })
            .hide()
            .appendTo('.dokuwiki:first');

        doxycode_tagselector.textArea = $editor[0];
        doxycode_tagselector.result = jQuery('#doxycode__tagselector_result')[0];

        // scrollview correction on arrow up/down gets easier
        jQuery(doxycode_tagselector.result).css('position', 'relative');

        doxycode_tagselector.$search = jQuery('#doxycode__tagselector_search');

        // attach event handlers
        jQuery('#doxycode__tagselector .ui-dialog-titlebar-close').on('click', doxycode_tagselector.hide);
        doxycode_tagselector.$search.keydown(doxycode_tagselector.onEntry);
        jQuery(doxycode_tagselector.result).on('click', 'a', doxycode_tagselector.onResultClick);
    },

    /**
     * handle all keyup events in the search field
     */
    onEntry: function(e){
        if(e.keyCode == 37 || e.keyCode == 39){ //left/right
            return true; //ignore
        }
        if(e.keyCode == 27){ //Escape
            doxycode_tagselector.hide();
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
        if(e.keyCode == 38){ //Up
            doxycode_tagselector.select(doxycode_tagselector.selected -1);
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
        if(e.keyCode == 40){ //Down
            doxycode_tagselector.select(doxycode_tagselector.selected +1);
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
        if(e.keyCode == 32){ //Space
            // Find the currently selected row based on your selection logic
            var $selectedRow = doxycode_tagselector.$getResult(doxycode_tagselector.selected);
        
            // Find the checkbox within that row
            var $checkbox = $selectedRow.find('td:first-child input[type="checkbox"]');
        
            // Toggle the checkbox state
            $checkbox.prop('checked', !$checkbox.prop('checked'));
        
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
        if(e.keyCode == 13){ //Enter

            // trigger the insertion of the tagfilelist
            doxycode_tagselector.insertTagNames();
    
            // close the tag selector
            doxycode_tagselector.hide();

            e.preventDefault();
            e.stopPropagation();
            return false;
        }
        doxycode_tagselector.filterRows();
    },

    /**
     * Get the selected tag name list from the result table.
     * 
     * @returns {Array} List of selected tag names
     */
    getTagList: function() {
        // get the table body
        var $tbody = jQuery('#doxycode__tagselector_table tbody');

        /**
         * Array to hold the tag names
         * @type {Array}
         */
        var tagNames = [];
    
        // Iterate over each row
        $tbody.find('tr').each(function() {
            var $row = jQuery(this);
            var $checkbox = $row.find('td:first-child input[type="checkbox"]');
    
            // Check if the checkbox is checked
            if ($checkbox.is(':checked')) {
                // Get the text from the second column and add it to the tagNames array
                var name = $row.find('td:nth-child(2)').text();
                tagNames.push(name);
            }
        });
    
        return tagNames;
    },

    /**
     * Get jQuery object of the entry in the result table by index
     *
     * @param   {num} int index of the entry in the tbody
     * @returns {jQuery} Row matching the index
     */
    $getResult: function(num) {
        return jQuery(doxycode_tagselector.$container).find('#doxycode__tagselector_table tbody tr:visible').eq(num);
    },

    /**
     * Select the given entry
     * 
     * @param {num} int index of the entry in the tbody
     */
    select: function(num){
        if(num < 0){
            doxycode_tagselector.deselect();
            return;
        }

        // get the current item
        var $obj = doxycode_tagselector.$getResult(num);
        if ($obj.length === 0) {
            return;
        }

        // remove class from item
        doxycode_tagselector.deselect();

        $obj.addClass('selected');

        // make sure the item is viewable in the scroll view

        //getting child position within the parent
        var childPos = $obj.position().top;
        //getting difference between the childs top and parents viewable area
        var yDiff = childPos + $obj.outerHeight() - jQuery(doxycode_tagselector.result).innerHeight();

        if (childPos < 0) {
            //if childPos is above viewable area (that's why it goes negative)
            jQuery(doxycode_tagselector.result)[0].scrollTop += childPos;
        } else if(yDiff > 0) {
            // if difference between childs top and parents viewable area is
            // greater than the height of a childDiv
            jQuery(doxycode_tagselector.result)[0].scrollTop += yDiff;
        }

        doxycode_tagselector.selected = num;
    },

    /**
     * Deselect the entry in the result table
     */
    deselect: function(){
        if(doxycode_tagselector.selected > -1){
            doxycode_tagselector.$getResult(doxycode_tagselector.selected).removeClass('selected');
        }
        doxycode_tagselector.selected = -1;
    },

    /**
     * Handle clicks in the result set an dispatch them to resultClick()
     * 
     * @param {Event} e
     */
    onResultClick: function(e){
        if(!jQuery(this).is('a')) {
            return;
        }

        e.stopPropagation();
        e.preventDefault();

        doxycode_tagselector.resultClick(this);
        return false;
    },

    /**
     * Handles the "click" on a given result anchor
     * 
     * Enable the clicked tag name and insert new syntax into edit textArea
     * 
     * @param {DOM/Element} a The link element this event was triggered for.
     */
    resultClick: function(a){
        // enable the checkbox of this item
        var $row = jQuery(a).closest('tr');
        var $checkbox = $row.find('td:first-child input[type="checkbox"]');
        $checkbox.prop('checked', true);

        // trigger the insertion of the tagfilelist
        doxycode_tagselector.insertTagNames();

        // close the tag selector
        doxycode_tagselector.hide();
    },

    /**
     * Start the timer for filtering the tag file list by the search string.
     * 
     * If a timer was already running we restart the timer.
     */
    filterRows: function() {
        if(doxycode_tagselector.timer !== null){
            window.clearTimeout(doxycode_tagselector.timer);
            doxycode_tagselector.timer = null;
        }

        doxycode_tagselector.timer = window.setTimeout(doxycode_tagselector.filterRowsExec,350);
    },

    /**
     * Filter the tag file list by the search string.
     */
    filterRowsExec: function(){
        // Convert search text to lower case for case-insensitive comparison
        var searchText = jQuery(doxycode_tagselector.$search).val().toLowerCase();

        var $tbody = jQuery(doxycode_tagselector.result).find('#doxycode__tagselector_table tbody');

        var $selectedRow = null;

        // get currently selected row so we can update the selected index
        if(doxycode_tagselector.selected >= 0) {
            $selectedRow = doxycode_tagselector.$getResult(doxycode_tagselector.selected);
        }

        // get all rows
        var $rows = $tbody.find('tr');
        
        // TODO: maybe also match the description?

        // show all matching rows
        $rows.filter(function() {
            var name = jQuery(this).find('td').eq(1).text().toLowerCase();
            return searchText === '' || name.includes(searchText);
        }).show();

        // hide all not matching rows
        $rows.not(function() {
            var name = jQuery(this).find('td').eq(1).text().toLowerCase();
            return searchText === '' || name.includes(searchText);
        }).hide();

        if($selectedRow != null) {
            // update index of the currently selected item inside the list of visible files
            doxycode_tagselector.selected = $tbody.find('tr:visible').index($selectedRow);

            if(doxycode_tagselector.selected < 0) {
                // if row can't be selected anymore remove selected class
                $selectedRow.removeClass('selected');
            }
        }

    },

    /**
     * Executes the AJAX call for loading the tag configuration from the server
     */
    updateTagNames: function(){
        var $res = jQuery(doxycode_tagselector.result);
        
        // show the loading animation
        $loading_animation = jQuery('<img src="'+DOKU_BASE+'lib/images/throbber.gif" alt="" width="16" height="16" />');

        $res.prepend($loading_animation);

        // request the tag file configuration from the server
        jQuery.post(
            DOKU_BASE + 'lib/exe/ajax.php',
            {
                call: 'plugin_doxycode_get_tag_files'
            },
            function(response) {
                // update the local tag name list
                doxycode_tagselector.renderTagNameList(response);
            },
            'json'
        ).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("AJAX error:", textStatus, errorThrown);
        });
    },

    /**
     * Insert or update a table with the current tag file configuration.
     * 
     * @param {array} response Tag file configuration from the server.
     */
    renderTagNameList: function(response) {
        var $res = jQuery(doxycode_tagselector.result);

        // remove the loading animation
        $res.find('img').remove();

        var $table = jQuery('#doxycode__tagselector_table'); // Reference to the table

        if ($table.length === 0) {
            // If the table doesn't exist, create it
            $table = jQuery('<table id="doxycode__tagselector_table"></table>');

            var $thead = jQuery('<thead></thead>');
            $table.append($thead);

            var $row = jQuery('<tr></tr>');
            $thead.append($row);
            
            $row.append(jQuery('<th></th>'));
            $row.append(jQuery('<th></th>').text(LANG.plugins.doxycode.tag_selector_name));
            $row.append(jQuery('<th></th>').text(LANG.plugins.doxycode.tag_selector_description));

            $res.append($table); // Append the table to a container
        }

        var $tbody;
        if ($table.find('tbody').length === 0) {
            // If tbody doesn't exist, create it
            $tbody = jQuery('<tbody></tbody>');
            $table.append($tbody);
        } else {
            $tbody = $table.find('tbody');
        }

        var existingRows = $tbody.find('tr').get(); // Get existing rows as an array

        for (const [key, value] of Object.entries(response)) {
            var $matchingRow = jQuery(existingRows).filter(function() {
                return jQuery(this).find('td:nth-child(2)').text() === key;
            });

            if ($matchingRow.length > 0) {
                // Row exists, update it
                $matchingRow.find('td:nth-child(3)').text(value['description']);

                // Remove the row from existingRows array as it's already processed
                existingRows = existingRows.filter(row => row !== $matchingRow[0]);
            } else {
                // Row doesn't exist, create and insert it at the correct position
                var $row = doxycode_tagselector.createRow(key, value);
                doxycode_tagselector.insertRowInOrder($tbody, $row, Object.keys(response), key);
            }
        }

        // Remove any remaining rows that weren't in the response
        jQuery(existingRows).remove();

        doxycode_tagselector.filterRowsExec();

        // move focus back to search input
        doxycode_tagselector.$search.focus();
    },

    /**
     * Create a row for the table with the tag file names.
     * 
     * The row consists of:
     * - a checkbox for selecting the tag name for insertion
     * - the tag name itself
     * - a short description from the tag file configuration
     * 
     * @param {String} key Tag File name
     * @param {Array.<{description: String}>} value Configuration of the tag file
     * @returns 
     */
    createRow: function(key, value) {
        var $row = jQuery('<tr></tr>').data('name', key);
        var $checkbox = jQuery('<input>', { type: 'checkbox', value: 0 });
        if (doxycode_tagselector.tagNames.includes(key)) {
            $checkbox.prop("checked", true);
        }
        $row.append(jQuery('<td></td>').append($checkbox));
        var $link = jQuery('<a>').text(key);
        $row.append(jQuery('<td>').append($link));
        $row.append(jQuery('<td></td>').text(value['description']));
        return $row;
    },
    
    /**
     * Insert a new row in the result table with the correct position based on the order of a tag name array
     * 
     * @param {jQuery} $tbody Table body that displays the tag names
     * @param {jQuery} $newRow Entry row generated with createRow()
     * @param {Array<String>} keys Tag names that should be displayed in the given order
     * @param {String} currentKey Tag name of the entry
     */
    insertRowInOrder: function($tbody, $newRow, keys, currentKey) {
        var inserted = false;
        $tbody.find('tr').each(function() {
            var rowKey = jQuery(this).find('td:nth-child(2)').text();
            var index = keys.indexOf(rowKey);
            if (index > keys.indexOf(currentKey)) {
                jQuery(this).before($newRow);
                inserted = true;
                return false; // break the .each loop
            }
        });
        if (!inserted) {
            // Append to the end if not inserted in the middle
            $tbody.append($newRow);
        }
    },

    /**
     * Insert the tagfiles attribute with the list of checked tag names from the tag file selector.
     * 
     * If a doxycode syntax was detected when the tag file selector was shown, it tries to update
     * the existing doxycode syntax. If the doxycode syntax didn't include the tagfiles
     * attribute, it inserts it before the filename (if there was one).
     * 
     * Otherwise it creates a new doxycode syntax at the position
     * 
     * @property {selection_class} doxycode_tagselector.doxycodeSelected used for replacing the existing doxycode syntax
     * @property {selection_class} doxycode_tagselector.selection used for inserting a new doxycode syntax
     */
    insertTagNames: function() {
        var tagFileNames = doxycode_tagselector.getTagList();

        var tagFilesString = 'tagfiles="' + tagFileNames.join(' ') + '"';
        var tagfilesRegex = /tagfiles=".*?"/;

        if(doxycode_tagselector.doxycodeSelected == null) {
            // we have to insert a new doxycode syntax into the editor textArea
            // use the start position from selector
            var doxycode_string = '<doxycode>\n</doxycode>';

            // insert the string into the editor textArea
            pasteText(doxycode_tagselector.selection,doxycode_string,{});

            // update doxycode selection, so that it matches the selection of the new text
            doxycode_tagselector.doxycodeSelected = doxycode_tagselector.selection;
        }

        // update or insert tagfiles attribute!

        var doxycodeText = doxycode_tagselector.doxycodeSelected.getText();
        var updatedDoxycode;

        if (tagfilesRegex.test(doxycode_tagselector.doxycodeSelected.getText())) {
            // Update the tagfiles attribute
            updatedDoxycode = doxycodeText.replace(tagfilesRegex, tagFilesString);
        } else {
            // Add the tagfiles attribute, considering self-closing tags
            updatedDoxycode = doxycodeText.replace(/<doxycode(.*?)(\/?>)/, function(match, attributes, closingTag) {
                // Place tagFilesString before the filename and the closing tag
                var filenameMatch = attributes.match(/ ([^ ]+)(\/?>)$/);
                var filename = filenameMatch ? filenameMatch[1] : '';
                var updatedAttributes = filename ? attributes.replace(filename, '').trim() : attributes.trim();
                return '<doxycode ' + updatedAttributes + ' ' + tagFilesString + (filename ? ' ' + filename : '') + closingTag;
            });
        }

        // insert the string into the editor textArea
        pasteText(doxycode_tagselector.doxycodeSelected,updatedDoxycode,{});

        return;
    
    },
    /**
     * Find the nearest doxycode syntax near the cursor in the edit textArea
     * 
     * The current selection in the edit textArea might be:
     * 
     * - at the start or inside of a '<doxycode ...>...<\doxycode>' or '<doxycode ...\>' block:
     * 
     *   -> just mark from '<' to '>' or '\>'
     * 
     * - inside the code content
     * 
     *   -> check if there is a '<doxycode ...>' block that is not closed before the start
     * 
     *   -> then check if there is a '<\doxycode>' block after the end
     * 
     * @property {selection_class} doxycode_tagselector.selection used for searching doxycode syntax near the original cursor position
     * @returns {void}
     */
    findNearestDoxycode: function() {

        doxycode_tagselector.doxycodeSelected = new selection_class();

        // extract the text from the edit textArea
        var text = doxycode_tagselector.textArea.value;

        // Extract the line where the selection starts
        var selectionStartLine = text.substring(0, doxycode_tagselector.selection.start).lastIndexOf('\n') + 1;
        var selectionEndLine = text.indexOf('\n', doxycode_tagselector.selection.start);
        if (selectionEndLine === -1) selectionEndLine = text.length;
    
        var line = text.substring(selectionStartLine, selectionEndLine);
    
        // Regex to match <doxycode ...> or <doxycode ...\>
        var regex = /<doxycode(.*?)>/;
    
        // detect if cursors starts at doxycode syntax
        var match = regex.exec(line);
        if (match) {
            // copy over the object from the original selection
            doxycode_tagselector.doxycodeSelected.obj = doxycode_tagselector.selection.obj;

            // Update the selection to cover the entire <doxycode> tag
            doxycode_tagselector.doxycodeSelected.start = selectionStartLine + match.index;
            doxycode_tagselector.doxycodeSelected.end = selectionStartLine + match[0].length;
            return;
        }

        // search for doxycode block before the current selection
        
        // Extract the entire text before and after the selection
        var textBeforeCursor = text.substring(0, doxycode_tagselector.selection.start);
        var textAfterCursor = text.substring(doxycode_tagselector.selection.start);

        // Regex to match the opening and closing of doxycode blocks, and self-closing tag
        var openingTagRegex = /<doxycode(.*?)>/g;
        var closingTagRegex = /<\/doxycode>/g;
        var selfClosingTagRegex = /<doxycode.*?\/>/g;

        // Find the last opening tag and first closing tag before the cursor
        var lastOpeningTagIndex = -1, firstClosingTagIndex = -1;
        var match;

        // Check for self-closing tags
        while ((match = selfClosingTagRegex.exec(textBeforeCursor)) !== null) {
            if (match.index > lastOpeningTagIndex) {
                // Found a self-closing tag, ignore it
                lastOpeningTagIndex = -1;
            }
        }

        // Find the nearest opening tag before the cursor
        while ((match = openingTagRegex.exec(textBeforeCursor)) !== null) {
            // Ignore if it is a self-closing tag
            if (!selfClosingTagRegex.test(match[0])) {
                lastOpeningTagIndex = match.index;
            }
        }

        // Find the nearest closing tag after the last opening tag
        if (lastOpeningTagIndex !== -1) {
            while ((match = closingTagRegex.exec(textAfterCursor)) !== null) {
                firstClosingTagIndex = match.index + doxycode_tagselector.selection.start;
                if (firstClosingTagIndex > lastOpeningTagIndex) {
                    break;
                }
            }
        }

        // Determine if the cursor is inside an open doxycode block
        if (lastOpeningTagIndex !== -1 && (firstClosingTagIndex === -1 || firstClosingTagIndex > doxycode_tagselector.selection.start)) {
            // Cursor is inside an open doxycode block

            var selectionStartLine = text.substring(0, lastOpeningTagIndex).lastIndexOf('\n') + 1;
            var selectionEndLine = text.indexOf('\n', lastOpeningTagIndex);
            
            // copy over the object from the original selection
            doxycode_tagselector.doxycodeSelected.obj = doxycode_tagselector.selection.obj;

            // Update the selection to cover the entire <doxycode> tag
            doxycode_tagselector.doxycodeSelected.start = selectionStartLine;
            doxycode_tagselector.doxycodeSelected.end = selectionEndLine;
            return;
        }

        doxycode_tagselector.doxycodeSelected = null;

        return;
    },

    /**
     * Extract the tag file list from the selected '<doxycode tagfiles="">' syntax.
     * 
     * @returns {void}
     */
    getTagNamesFromSyntax: function() {
        // clear the current list of tagNames
        doxycode_tagselector.tagNames = [];

        if(doxycode_tagselector.doxycodeSelected == null) {
            return;
        }

        // extract the doxycode text from the selection
        var doxycode_syntax = doxycode_tagselector.doxycodeSelected.getText();

        // get the tagfiles from it
        var regex = /tagfiles="(.*?)"/;
    
        var match = regex.exec(doxycode_syntax);
        if (match) {
            doxycode_tagselector.tagNames = match[1].split(" ");
            return;
        }
    },

    clearResults: function() {
        // get the table body
        var $tbody = jQuery('#doxycode__tagselector_table tbody');

        // clear contents
        $tbody.empty();
    },

    /**
     * Show the tag selector
     */
    show: function(){
        // prepare the update from the current selection
        doxycode_tagselector.selection  = DWgetSelection(doxycode_tagselector.textArea);
        // we'll scan for the current <doxycode> block containing a 'tagfiles' argument
        doxycode_tagselector.findNearestDoxycode();
        doxycode_tagselector.getTagNamesFromSyntax();

        // show the tag selector
        doxycode_tagselector.$container.show();
        doxycode_tagselector.$search.focus();

        // get the current list of tagnames from the server
        doxycode_tagselector.updateTagNames();

        // Move the cursor to the end of the input
        var temp = doxycode_tagselector.$search.val();
        doxycode_tagselector.$search.val('');
        doxycode_tagselector.$search.val(temp);
    },

    /**
     * Hide the tag selector
     */
    hide: function(){
        doxycode_tagselector.deselect();

        // clear any results from the last time
        doxycode_tagselector.clearResults();

        doxycode_tagselector.$container.hide();
        // put the focus back to the editor
        doxycode_tagselector.textArea.focus();
    },

    /**
     * Toggle the tag selector
     */
    toggle: function(){
        if(doxycode_tagselector.$container.css('display') == 'none'){
            doxycode_tagselector.show();
        }else{
            doxycode_tagselector.hide();
        }
    }
};

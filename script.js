/* DOKUWIKI:include scripts/tagselector.js */

jQuery(function(){
    // TODO: make this configurable by the admin
    var currentInterval = 1000; // Starting interval in milliseconds
    var maxInterval = 10000; // Maximum interval is 10 seconds

    const BuildmanagerStates = {
        STATE_NON_EXISTENT: 1,
        STATE_RUNNING: 2,
        STATE_SCHEDULED: 3,
        STATE_FINISHED: 4,
    };

    function scanAndPrepareData() {
        var dataToSend = [];

        jQuery('.doxycode_marker').each(function() {
            var xmlHash = jQuery(this).data('doxycode-xml-hash');
            var htmlHash = jQuery(this).data('doxycode-html-hash');
            if (xmlHash || htmlHash) {
                dataToSend.push({xmlHash: xmlHash, htmlHash: htmlHash});
            }
        });
        return dataToSend;
    }

    // Function to handle AJAX response
    function handleStatusResponse(response) {
        response.forEach(function(hashInfo) {
            $markers = jQuery('.doxycode_marker').filter(function() {
                return jQuery(this).data('doxycode-xml-hash') === hashInfo.xmlHash &&
                       jQuery(this).data('doxycode-html-hash') === hashInfo.htmlHash;
            });

            var $loadingAnimation;
            if(parseInt(hashInfo.state) != BuildmanagerStates.STATE_FINISHED) {
                $loadingAnimation = jQuery('<img src="'+DOKU_BASE+'lib/images/throbber.gif" alt="" width="16" height="16" />');
            }

            $markers.each(function() {
                var $currentMarker = jQuery(this);
                $currentMarker.empty();

                var message;
                switch(parseInt(hashInfo.state)) {
                    case BuildmanagerStates.STATE_NON_EXISTENT:
                        message = LANG.plugins.doxycode.msg_not_existent;
                        break;
                    case BuildmanagerStates.STATE_SCHEDULED:
                        message = LANG.plugins.doxycode.msg_scheduled;
                        break;
                    case BuildmanagerStates.STATE_RUNNING:
                        message = LANG.plugins.doxycode.msg_running;
                        break;
                    case BuildmanagerStates.STATE_FINISHED:
                        loadSnippet({
                            xmlHash: hashInfo.xmlHash,
                            htmlHash: hashInfo.htmlHash
                        });
                        return; // Skip appending for finished state
                }
        
                if ($loadingAnimation) {
                    $currentMarker.append($loadingAnimation.clone());
                }
        
                if (message) {
                    $currentMarker.append(message);
                }
            });
        });
    }

    function loadSnippetHtml(response) {

        $markers = jQuery('.doxycode_marker').filter(function() {
            return jQuery(this).data('doxycode-xml-hash') === response.hashes.xmlHash &&
                   jQuery(this).data('doxycode-html-hash') === response.hashes.htmlHash;
        });

        $markers.each(function() {
            // TODO: dynamically load the HTML for this snippet!
            var parent_element = jQuery(this).parent();
            jQuery(this).remove();
            jQuery(parent_element).append(response.html);
        });
    }

    // Function to send AJAX request
    function requestJobStatus() {
        var data = scanAndPrepareData();
        if (data.length === 0) {
            return;
        }

        jQuery.post(
            DOKU_BASE + 'lib/exe/ajax.php',
            {
                call: 'plugin_doxycode_check_status',
                hashes: data
            },
            function(response) {
                handleStatusResponse(response);
                currentInterval = Math.min(currentInterval + 100, maxInterval);
                setTimeout(requestJobStatus, currentInterval);
            },
            'json'
        ).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("AJAX error:", textStatus, errorThrown);
            currentInterval = Math.min(currentInterval + 1000, maxInterval);
            setTimeout(requestJobStatus, currentInterval);
        });
    }

    // Function to send AJAX request
    function loadSnippet(data) {

        jQuery.post(
            DOKU_BASE + 'lib/exe/ajax.php',
            {
                call: 'plugin_doxycode_get_snippet_html',
                hashes: data
            },
            function(response) {
                loadSnippetHtml(response);
            },
            'json'
        ).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("AJAX error:", textStatus, errorThrown);
        });
    }

    requestJobStatus();
});

/**
 * Add button action for the doxycode tag selector button
 * 
 * Adapted from the linkwiz dialog
 *
 * @param  DOMElement btn   Button element to add the action to
 * @param  array      props Associative array of button properties
 * @param  string     edid  ID of the editor textarea
 * @return boolean    If button should be appended
 * @author Andreas Gohr <gohr@cosmocode.de>
 * @author Lukas Probsthain <lukas.probsthain@gmail.com>
 */
function addBtnActionDoxycodeTagSelector($btn, props, edid) {
    doxycode_tagselector.init(jQuery('#'+edid));
    jQuery($btn).click(function(e){
        doxycode_tagselector.val = props;
        doxycode_tagselector.toggle();
        e.preventDefault();
        return '';
    });
    return 'doxycode__tagselector';






}
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
        STATE_ERROR: 5,
    };

    var isLoadingSnippet = false;

    /**
     * Scan the document for doxycode markers that represent dynamically loaded code snippets.
     * 
     * The doxycode_markers contain custom data fields that represent the cache files.
     * We extract all markers so the state of the associated doxygen builds can be obtained in a single request.
     * 
     * When the code snippets are successfully loaded the markers are removed later. If no more markers
     * are present we return an empty array which indicates that dynamic loading is finished for this page.
     * 
     * @returns {Array} contains the xml and html hash names that represent the doxycode cache files
     * @author Lukas Probsthain <lukas.probsthain@gmail.com>
     */
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

    /**
     * Handles the state response for the dynamically loaded code snippets.
     * 
     * This is called from requestJobStatus.
     * The function will place an appropriate state message obtained from the
     * global LANG variable inside the doxycode_marker container.
     * 
     * If a successful build was reported for a dynamically loaded code snippet
     * the function will request the parsed code snippet HTML from the server via AJAX.
     * 
     * @param {Array} response Each entry contains the xml and html hash from the request with the reported state
     * @author Lukas Probsthain <lukas.probsthain@gmail.com>
     */
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
                    case BuildmanagerStates.STATE_ERROR:
                        message = LANG.plugins.doxycode.msg_error;
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

    /**
     * Handles the insertion of dynamically loaded code snippet HTML into the code container.
     * 
     * @param {Array} response Contains the xml and html hashes along with the parsed code snippet HTML
     * @author Lukas Probsthain <lukas.probsthain@gmail.com>
     */
    function loadSnippetHtml(response) {

        $markers = jQuery('.doxycode_marker').filter(function() {
            return jQuery(this).data('doxycode-xml-hash') === response.hashes.xmlHash &&
                   jQuery(this).data('doxycode-html-hash') === response.hashes.htmlHash;
        });

        $markers.each(function() {
            // dynamically load the HTML for this snippet!
            var parent_element = jQuery(this).parent();
            jQuery(this).remove();
            jQuery(parent_element).append(response.html);
        });
    }

    /**
     * Function to request the build status of dynamically loaded code snippets via AJAX.
     * 
     * It obtains the xml and html hashes (which represent the cache files) of the code snippets with scanAndPrepareData and sends a request
     * for all dynamically loaded code snippets to plugin_doxycode_check_status.
     * 
     * If dynamically loaded code snippets are present in the page it will call itself again.
     * The time period after which the request is repeated increases with each request to reduce load on the server.
     * 
     * @author Lukas Probsthain <lukas.probsthain@gmail.com>
     */
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

    /**
     * Request the parsed code snippet HTML from the server via AJAX.
     * 
     * @param {Array} data Contains the xml and html hashes for the code snippet cache files
     * @author Lukas Probsthain <lukas.probsthain@gmail.com>
     */
    function loadSnippet(data) {
        if (isLoadingSnippet) {
            return;
        }

        isLoadingSnippet = true;

        jQuery.post(
            DOKU_BASE + 'lib/exe/ajax.php',
            {
                call: 'plugin_doxycode_get_snippet_html',
                hashes: data
            },
            function(response) {
                isLoadingSnippet = false;
                loadSnippetHtml(response);
            },
            'json'
        ).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("AJAX error:", textStatus, errorThrown);
            isLoadingSnippet = false;
        });
    }

    requestJobStatus();
});

/**
 * Add button action for the doxycode tag selector button
 * 
 * Adapted from the linkwiz dialog by Andreas Gohr (see {@link https://github.com/dokuwiki/dokuwiki/blob/8985cadc85d51290e4908456c2afc923fd0f0332/lib/scripts/toolbar.js#L221-L230}).
 *
 * @param  DOMElement btn   Button element to add the action to
 * @param  array      props Associative array of button properties
 * @param  string     edid  ID of the editor textarea
 * @return boolean    If button should be appended
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
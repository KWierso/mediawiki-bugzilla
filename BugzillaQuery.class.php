<?php

require_once dirname(__FILE__) . '/BugzillaJob.class.php';
require_once 'HTTP/Request2.php';

// Factory class
class BugzillaQuery {
    public function create($type, $options, $title) {
        global $wgBugzillaMethod;

        if( strtolower($wgBugzillaMethod) == 'xml-rpc' ) {
            return new BugzillaXMLRPCQuery($type, $options, $title);
        }elseif( strtolower($wgBugzillaMethod) == 'json-rpc' ) {
            return new BugzillaJSONRPCQuery($type, $options, $title);
        }else {
            return new BugzillaRESTQuery($type, $options, $title);
        }
    }
}

// Base class
abstract class BugzillaBaseQuery {

    public function __construct($type, $options, $title) {
        $this->type        = $type;
        $this->title       = $title;
        $this->url         = FALSE;
        $this->id          = FALSE;
        $this->fetched_at  = FALSE;
        $this->error       = FALSE;
        $this->data        = array();

        $this->_set_options($options);
    }

    public function id() {

        // If we have already generated an id, return it
        if( $this->id ) { return $this->id; }

        return $this->_generate_id();
    }

    protected function _generate_id() {

        // No need to generate if there are errors
        if( !empty($this->error) ) { return; }

        // FIXME: Should we strtolower() the keys?

        // Sort it so the keys are always in the same order
        ksort($this->options);
        
        // Get a string representation of the array
        $id_string = serialize($this->options);

        // Hash it
        $this->id = sha1($id_string);

        return $this->id;
    }
        
    // Connect and fetch the data
    public function fetch() {

        global $wgBugzillaCacheMins;

        // We need *some* options to do anything
        if( !isset($this->options) || empty($this->options) ) { return; }

        // Don't do anything if we already had an error
        if( $this->error ) { return; }

        // Connect to the database. Because we are reading and we can deal with
        // a bit of stale data we can just look at the slave
        $dbr = wfGetDB( DB_SLAVE );

        // See if we have a cache entry
        // TODO: Make this configuratble between count(*) and what is here now
        $res = $dbr->select(
                    'bugzilla_cache',
                    array('id', 'fetched_at','data'),
                    'id = "' . $this->id() . '"',
                    __METHOD__,
                    array( 'ORDER BY' => 'fetched_at DESC',
                           'LIMIT' => 1)
        );

        // If the cache entry is older than this we need to invalidate it
        $expiry = strtotime("-$wgBugzillaCacheMins minutes");

        // Check if there was an entry in the cache
        if( $res->numRows() ) {
            $row = $res->fetchRow();
        }else{
            $row = FALSE;
        }

        if( !$row ) { 
            // No cache entry

            $this->cached = FALSE;
            $params = array( 'query_obj' => serialize($this) );

            // Does the Bugzilla query in the background and updates the cache
            $job = new BugzillaInsertJob( $this->title, $params );
            $job->insert();

        }elseif( $expiry > wfTimestamp(TS_UNIX, $row['fetched_at']) ) {
            // Cache entry is too old

            $this->cached = FALSE;
            $params = array( 'query_obj' => serialize($this) );

            // Does the Bugzilla query in the background and updates the cache
            $job = new BugzillaUpdateJob( $this->title, $params );
            $job->insert();

        }else {
            // Cache is good, use it

            $this->id = $row['id'];
            $this->fetched_at = wfTimestamp(TS_DB, $row['fetched_at']);
            $this->data = unserialize($row['data']);
            $this->cached = TRUE;
        }
    }

    protected function _set_options($query_options_raw) {
        // Make sure query options are valid JSON
        $this->options = json_decode($query_options_raw);
        if( !$query_options_raw || !$this->options ) {
            $this->error = 'Query options must be valid json';
        }
        // Object is kinda userless, make it an array
        $this->options = get_object_vars($this->options);
    }

}

class BugzillaRESTQuery extends BugzillaBaseQuery {

    function __construct($type, $options, $title='') {
        global $wgBugzillaRESTURL;

        parent::__construct($type, $options, $title);

        // See what sort of REST query we are going to 
        switch( $type ) {

            // Whitelist
            case 'bug':
            case 'count':
                $this->url = $wgBugzillaRESTURL . '/' . urlencode($type);
                break;

            // Default to a bug query
            default:
                $this->url = $wgBugzillaRESTURL . '/bug';
        }

        $this->fetch();
    }

    // Load data from the Bugzilla REST API
    public function _fetch_by_options() {

        // Set up our HTTP request
        $request = new HTTP_Request2($this->url,
                                     HTTP_Request2::METHOD_GET,
                                     array('follow_redirects' => TRUE,
                                           // TODO: Not sure if I should do this
                                           'ssl_verify_peer' => FALSE));

        // The REST API requires these
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('Content-Type', 'application/json');

        // Add in the requested query options
        $url = $request->getUrl();
        $url->setQueryVariables($this->options);

        // This is basically straight from the HTTP/Request2 docs
        try {
            $response = $request->send();
            if (200 == $response->getStatus()) {
                $this->data = json_decode($response->getBody());
            } else {
                $this->error = 'Server returned unexpected HTTP status: ' .
                               $response->getStatus() . ' ' .
                               $response->getReasonPhrase();
                return;
            }
        } catch (HTTP_Request2_Exception $e) {
            $this->error = $e->getMessage();
            return;
        }

        // Now that we have the data, process it
        // $this->_process_data();
    }
}


?>

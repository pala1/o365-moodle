<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Convenient wrappers and helper for using the msaccount API
 *
 * @package    local_msaccount
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/oauthlib.php');

/*
 * Subclass the oauth2_client class because we want to override some of its methods
 */
class msaccount_client extends oauth2_client {
    /** @var string OAuth 2.0 scope */
    const SCOPE = 'office.onenote_update wl.skydrive wl.offline_access';
    private $token_as_param = TRUE;
    
    /**-
     * Construct a msaccount_client object
     * @param string $clientid client id for OAuth 2.0 provided by microsoft
     * @param string $clientsecret secret for OAuth 2.0 provided by microsoft
     * @param moodle_url $returnurl url to return to after succseful auth
     */
    public function __construct() {
        $returnurl = new moodle_url('/local/msaccount/msaccount_redirect.php');
        $returnurl->param('sesskey', sesskey());

        parent::__construct(get_config('local_msaccount', 'clientid'), get_config('local_msaccount', 'clientsecret'), 
                $returnurl, self::SCOPE);
    }

    /**
     * Returns the auth url for OAuth 2.0 request
     * @return string the auth url
     */
    protected function auth_url() {
        return 'https://login.live.com/oauth20_authorize.srf';
    }
    
    /**
     * Returns the token url for OAuth 12.0 request
     * @return string the auth url
     */
    protected function token_url() {
        return 'https://login.live.com/oauth20_token.srf';
    }
    
    public function is_logged_in() {
        $accesstoken = $this->get_accesstoken();
        
        // Has the token expired?
        if (isset($accesstoken->expires) && time() >= $accesstoken->expires) {
            if (!$this->refresh_token())
            {
                $this->log_out();
                return false;
            }
        }

        // We have a token so we are logged in.
        if (isset($accesstoken->token)) {
            return true;
        }

        // If we've been passed then authorization code generated by the
        // authorization server try and upgrade the token to an access token.
        $code = optional_param('oauth2code', null, PARAM_RAW);
        if ($code && $this->upgrade_token($code)) {
            return true;
        }

        return false;
    }

    public function upgrade_token($code) {
        $callbackurl = self::callback_url();
        $params = array('client_id' => $this->get_clientid(),
            'client_secret' => $this->get_clientsecret(),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $callbackurl->out(false),
        );

        // Requests can either use http GET or POST.
        if ($this->use_http_get()) {
            $response = $this->get($this->token_url(), $params);
        } else {
            $response = $this->post($this->token_url(), $params);
        }

        if (!$this->info['http_code'] === 200) {
            throw new moodle_exception('Could not upgrade oauth token');
        }

        $r = json_decode($response);

        if (!isset($r->access_token)) {
            return false;
        }

        // Store the token an expiry time.
        $accesstoken = new stdClass;
        $accesstoken->token = $r->access_token;
        $accesstoken->expires = (time() + ($r->expires_in - 10)); // Expires 10 seconds before actual expiry.
        $this->store_token($accesstoken);
        
        $this->store_refresh_token($r->refresh_token);
        
        return true;
    }

    public function refresh_token() {
        global $DB, $USER;
        
        $this->log_out(); // remove previous token
        
        $record = $DB->get_record('msa_refresh_tokens', array("user_id" => $USER->id));
        
        if (!$record || !$record->refresh_token)
            return false;
        
        $callbackurl = self::callback_url();
        $params = array('client_id' => $this->get_clientid(),
            'client_secret' => $this->get_clientsecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $record->refresh_token,
            'redirect_uri' => $callbackurl->out(false),
        );
        
        $this->setHeader('Content-Type: application/x-www-form-urlencoded');
        $response = $this->post($this->token_url(), $params);

        if (!$this->info['http_code'] === 200) {
            throw new moodle_exception('Could not refresh oauth token');
        }

        $r = json_decode($response);

        if (!isset($r->access_token)) {
            return false;
        }

        // Store the token an expiry time.
        $accesstoken = new stdClass;
        $accesstoken->token = $r->access_token;
        $accesstoken->expires = (time() + ($r->expires_in - 10)); // Expires 10 seconds before actual expiry.
        $this->store_token($accesstoken);
        
        $this->store_refresh_token($r->refresh_token);
                
        return true;
    }

    public function store_refresh_token($refresh_token) {
        global $DB, $USER;
        
        $record = $DB->get_record('msa_refresh_tokens', array("user_id" => $USER->id));
        if ($record) {
            $record->refresh_token = $refresh_token;
            $DB->update_record('msa_refresh_tokens', $record);
        } else {
            $record = new stdClass();
            $record->user_id = $USER->id;
            $record->refresh_token = $refresh_token;
            $DB->insert_record('msa_refresh_tokens', $record);
        }
    }
    
    /**
     * Should HTTP GET be used instead of POST?
     *
     * msaccount REST API needs auth token in the header for get as well as post requests. 
     * Oauth2_client sets the token in the header only if it thinks that it is making making a post request. 
     * So we control that behavior by overriding this method.
     *
     * @return bool true if GET should be used
     */
    protected function use_http_get() {
        return $this->token_as_param;
    }
    
    public function myget($url, $params=array(), $token='', $secret='') {
        $this->token_as_param = false;
        $response = $this->get($url, $params, $token, $secret);
        $this->token_as_param = true;
        return $response;
    }

    public function mypost($url, $params=array(), $token='', $secret='') {
        $this->token_as_param = false;
        $response = $this->post($url, $params, $token, $secret);
        $this->token_as_param = true;
        return $response;
    }
}

/**
 * A helper class to access Microsoft Account using the REST api. 
 * This is a singleton class. 
 * All access to Microsoft Account should be through this class instead of directly accessing the msaccount_client class.
 *
 * @package    local_msaccount
 */
class msaccount_api {

    private static $instance = null;
    private $msaccount_client = null;

    protected function __construct() {
        $this->msaccount_client = new msaccount_client();
    }
    
    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new static();
        }
    
        self::$instance->msaccount_client->is_logged_in();
    
        return self::$instance;
    }
    
    public function get_msaccount_client() {
        return $this->msaccount_client;
    }
    public function is_logged_in() {
        return $this->get_msaccount_client()->is_logged_in();
    }
    
    public function get_login_url() {
        return $this->get_msaccount_client()->get_login_url();
    }
    
    public function log_out() {
        return $this->get_msaccount_client()->log_out();
    }

    public function myget($url, $params=array(), $token='', $secret='') {
        return $this->get_msaccount_client()->myget($url, $params, $token, $secret);
    }
    
    public function mypost($url, $params=array(), $token='', $secret='') {
        return $this->get_msaccount_client()->mypost($url, $params, $token, $secret);
    }
    
    public function get_accesstoken() {
        return $this->get_msaccount_client()->get_accesstoken();
    }
    
    public function render_signin_widget() {
        $url = $this->get_login_url();
    
        return '<a onclick="window.open(this.href,\'mywin\',\'left=20,top=20,width=500,height=500,toolbar=1,resizable=0\'); return false;"
           href="'.$url->out(false).'" class="msaccount_linkbutton">' . get_string('signin', 'local_msaccount') . '</a>';
    }
}
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
 * Functions for operating with the OneNote API
 *
 * @package    repository_onenote
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/oauthlib.php');


/**
 * A helper class to access microsoft live resources using the api.
 *
 * This uses the microsoft API defined in
 * http://msdn.microsoft.com/en-us/library/hh243648.aspx
 *
 * @package    repository_onenote
 */
class microsoft_onenote extends oauth2_client {
    /** @var string OAuth 2.0 scope */
    const SCOPE = 'office.onenote_update';
    /** @var string Base url to access API */
    const API = 'https://www.onenote.com/api/beta'; //'https://www.onenote.com/api/v1.0';
    /** @var cache_session cache of notebooknames */
    var $itemnamecache = null;
    private $isget = TRUE;
    /**-
     * Construct a onenote request object
     *
     * @param string $clientid client id for OAuth 2.0 provided by microsoft
     * @param string $clientsecret secret for OAuth 2.0 provided by microsoft
     * @param moodle_url $returnurl url to return to after succseful auth
     */
    public function __construct($clientid, $clientsecret, $returnurl) {
        parent::__construct($clientid, $clientsecret, $returnurl, self::SCOPE);

        // error_log('microsoft_onenote constructor');
        // error_log(print_r($clientid, true));
        // error_log(print_r($clientsecret, true));
        // error_log(print_r($returnurl, true));

        // Make a session cache
        $this->itemnamecache = cache::make('repository_onenote', 'foldername');
    }

    /**
     * Should HTTP GET be used instead of POST?
     *
     * The Microsoft API does not support POST, so we should use
     * GET instead (with the auth_token passed as a GET param).
     *
     * @return bool true if GET should be used
     */
    protected function use_http_get() {
            return $this->isget;
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

    /**
     * Downloads a OneNote page to a  file from onenote using authenticated request
     *
     * @param string $id id of page
     * @param string $path path to save page to
     * @return array stucture for repository download_file
     */
     public function download_page($page_id, $path) {
        error_log('download_page called: ' . print_r($page_id, true));

        $url = self::API."/pages/".$page_id."/content";
        error_log(print_r($url,true));

        $this->isget = FALSE;
        $response = $this->get($url);
        $this->isget = TRUE;

        error_log("response: " . print_r($response, true));

        if (!$response || isset($response->error)) {
            $this->log_out();
            return null;
        }

        // see if the file contains any references to images or other files and if so, create a folder and download those, too
        $doc = new DOMDocument();
        $doc->loadHTML($response);
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query("//img/@src");
        
        if ($nodes) {
            // create temp folder
            $temp_folder = join(DIRECTORY_SEPARATOR, array(trim(sys_get_temp_dir(), DIRECTORY_SEPARATOR), uniqid('asg_')));
            if (file_exists($temp_folder)) { 
                fulldelete($temp_folder);
            }
            
            if (!mkdir($temp_folder))
                return null;
            
            $files_folder = join(DIRECTORY_SEPARATOR, array(trim($temp_folder, DIRECTORY_SEPARATOR), 'page_files'));
            if (!mkdir($files_folder))
                return null;
            
            $this->isget = FALSE;
        
            $i = 1;
            foreach ($nodes as $node) {
                $response = $this->get($node->value);
                file_put_contents($files_folder . DIRECTORY_SEPARATOR . $i, $response);
                
                $node->value = '.' . DIRECTORY_SEPARATOR . 'page_files' . DIRECTORY_SEPARATOR . $i;
                $i++; 
            }
            
            $this->isget = TRUE;

            // update img src paths in the html accordingly
            file_put_contents(join(DIRECTORY_SEPARATOR, array(trim($temp_folder, DIRECTORY_SEPARATOR), 'page.html')), $doc->saveHTML());
            
            // zip up the folder so it can be attached as a single file
            $fp = get_file_packer('application/zip');
            $filelist = array();
            $filelist[] = $temp_folder;
            
            $fp->archive_to_pathname($filelist, $path); // TODO: How to add .zip extension?
            
            fulldelete($temp_folder);
        } else {
            file_put_contents($path, $response);
        }
        
        return array('path'=>$path, 'url'=>$url);
    }

    /**
     * Returns the name of the OneNote item (notebook or section) given its id.
     *
     * @param string $item_id the id which is passed
     * @return mixed item name or false in case of error
     */
    public function get_item_name($item_id) {
        error_log('get_item_name called: ' . print_r($item_id, true));

        if (empty($item_id)) {
            throw new coding_exception('Empty item_id passed to get_item_name');
        }

        // Cache based on oauthtoken and item_id.
        $cachekey = $this->item_cache_key($item_id);

        if ($item_name = $this->itemnamecache->get($cachekey)) {
            return $item_name;
        }

        $url = self::API."/notebooks/{$item_id}";
        $this->isget = FALSE;
        $this->request($url);
        $response = json_decode($this->get($url));
        $this->isget = TRUE;
        error_log('response: ' . print_r($response, true));

        if (!$response || isset($response->error)) {
            // TODO: Hack: See if it is a section id
            $url = self::API."/sections/{$item_id}";
            $this->isget = FALSE;
            $this->request($url);
            $response = json_decode($this->get($url));
            $this->isget = TRUE;
            error_log('response: ' . print_r($response, true));

            if (!$response || isset($response->error)) {
                $this->log_out();
                return false;
            }
        }

        $this->itemnamecache->set($cachekey, $response->value[0]->name);
        return $response->value[0]->name;
    }

    /**
     * Returns a list of items (notebooks and sections)
     *
     * @param string $path the path which we are in
     * @return mixed Array of items formatted for fileapi
     */
    public function get_items_list($path = '') {
        global $OUTPUT;

        error_log('get_items_list called: ' . $path);
        $precedingpath = '';

        if (empty($path)) {
            $item_type = 'notebook';
            $url = self::API."/notebooks";
        } else {
            $parts = explode('/', $path);
            $part1 = array_pop($parts);
            $part2 = array_pop($parts);
            error_log('part1: ' . print_r($part1, true));
            error_log('part2: ' . print_r($part2, true));

            if ($part2) {
                $item_type = 'page';
                $url = self::API."/sections/{$part1}/pages";
            } else {
                $item_type = 'section';
                $url = self::API."/notebooks/{$part1}/sections";
            }
        }

        error_log('request: ' . print_r($url, true));
        $this->isget = FALSE;
        $this->request($url);
        $response = json_decode($this->get($url));
        $this->isget = TRUE;

        error_log('response: ' . print_r($response, true));

        $items = array();

        if (isset($response->error)) {
            $this->log_out();
            return $items;
        }

        if ($response && $response->value) {
            foreach ($response->value as $item) {
                switch ($item_type) {
                case 'notebook':
                    $items[] = array(
                        'title' => $item->name,
                        'path' => $path.'/'.urlencode($item->id),
                        //'size' => $item->size,
                        'date' => strtotime($item->lastModifiedTime),
                        'thumbnail' => $OUTPUT->pix_url(file_extension_icon($item->name, 90))->out(false),
                        'source' => $item->id,
                        'url' => $item->links->oneNoteWebUrl->href,
                        'author' => $item->createdBy,
                        'id' => $item->id,
                        'children' => array()
                    );
                    break;

                case 'section':
                    $items[] = array(
                        'title' => $item->name,
                        'path' => $path.'/'.urlencode($item->id),
                        //'size' => $item->size,
                        'date' => strtotime($item->lastModifiedTime),
                        'thumbnail' => $OUTPUT->pix_url(file_extension_icon($item->name, 90))->out(false),
                        'source' => $item->id,
                        'url' => $item->self,
                        'author' => $item->createdBy,
                        'id' => $item->id,
                        'children' => array()
                    );
                    break;

                case 'page':
                    $items[] = array(
                        'title' => $item->title,
                        'path' => $path.'/'.urlencode($item->id),
                        //'size' => $item->size,
                        'date' => strtotime($item->createdTime),
                        'thumbnail' => $OUTPUT->pix_url(file_extension_icon($item->title, 90))->out(false),
                        'source' => $item->id,
                        'url' => $item->links->oneNoteWebUrl->href,
                        'author' => $item->createdByAppId,
                        'id' => $item->id
                    );
                    break;
                }
            }
        }

        if (empty($path)) {
            $this->insert_notes($items);
       }

        return $items;
    }

    /**
     * Returns a key for itemname cache
     *
     * @param string $item_id the id which is to be cached
     * @return string the cache key to use
     */
    private function item_cache_key($item_id) {
        // Cache based on oauthtoken and item_id.
        return $this->get_tokenname().'_'.$item_id;
    }

    private function insert_notes($notes) {
        global $DB;
        $notebook_name =  get_string('notebookname','block_onenote');
        $noteurl = self::API."/notebooks/";
        $courses = enrol_get_my_courses(); //get the current user enrolled courses
        $notes_array = array();
        if($notes) {
            foreach ($notes as $note) {
                if($note['id']) {
                    $notes_array[$note['id']] = $note['title'];
                }
            }
        }

        if(count($notes_array) > 0){
                if(!(in_array($notebook_name, $notes_array))){
                    $param = array(
                        "name" => $notebook_name
                    );

                    $note_name = json_encode($param);
                    $this->isget = FALSE;
                    $this->setHeader('Content-Type: application/json');
                    $this->request($noteurl);
                    $created_notes = json_decode($this->post($noteurl,$note_name));
                    $this->isget = TRUE;
                    $sections = array();
                    if($created_notes) {
                        $note_id = $created_notes->id;
                    }
                    if($courses) {
                        $this->create_sections_onenote($courses,$note_id,$sections);
                    }
                } else {
                    $note_id = array_search($notebook_name, $notes_array);
                    $sectionurl = self::API."/notebooks/".$note_id."/sections/";
                    $this->setHeader('Content-Type: application/json');
                    $this->isget = FALSE;
                    $this->request($sectionurl);
                    $getsection = json_decode($this->get($sectionurl));
                    $this->isget = TRUE;
                    $sections = array();
                    if(isset($getsection->value)) {
                        foreach($getsection->value as $section) {
                            $sections[$section->id] = $section->name;
                        }
                    }

                    if($courses) {
                        $this->create_sections_onenote($courses, $note_id, $sections);

                    }
                }
            //}
        }
    }
    
    private function insert_sectionid_table($course_id,$section_id) {
        global $DB,$USER;
        $course_onenote = new stdClass();
        $course_onenote->user_id = $USER->id;
        $course_onenote->course_id = $course_id;
        $course_onenote->section_id = $section_id;
        $course_ext = $DB->get_record('course_user_ext', array("course_id" => $course_id,"user_id" => $USER->id));
        if($course_ext) {
            $course_onenote->id = $course_ext->id;
            $update = $DB->update_record("course_user_ext", $course_onenote);
        }else {
            $insert = $DB->insert_record("course_user_ext", $course_onenote);
        }

    }
    
    private function create_sections_onenote($courses,$note_id, array $sections){
        $sectionurl = self::API."/notebooks/".$note_id."/sections/";

        foreach($courses as $course) {
            if(!in_array($course->fullname, $sections)) {
                $param_section = array(
                        "name" => $course->fullname
                );
                $section = json_encode($param_section);
                $this->setHeader('Content-Type: application/json');
                $this->isget = FALSE;
                $this->request($sectionurl);
                $eventresponse = $this->post($sectionurl,$section);
                $this->isget = TRUE;
                $eventresponse = json_decode($eventresponse);
                //mapping course id and section id
                if($eventresponse)
                $this->insert_sectionid_table($course->id, $eventresponse->id);
            } else {
                $section_id = array_search($course->fullname, $sections);
                $this->insert_sectionid_table($course->id, $section_id);

            }

        }
    }
}

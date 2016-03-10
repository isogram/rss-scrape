<?php

require __DIR__ . '/vendor/autoload.php';

try {
    $dotenv = new Dotenv\Dotenv(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    echo $e->getMessage() . "\n";die();
}

/**
* Fetch My RSS
*/
class FetchMyRss
{

    private $db;
    private $slack = null;
    private $feedList;
    private $dataToInsert;

    function __construct() {

        $this->db = new medoo([
            'database_type' => 'mysql',
            'database_name' => getenv('DB_NAME'),
            'server' => getenv('DB_SERVER'),
            'username' => getenv('DB_USER'),
            'password' => getenv('DB_PASS'),
            'charset' => 'utf8',
        ]);

        $this->setFeedList();
        if ((bool)getenv('SLACK')) {
            $this->slack = new Maknz\Slack\Client(getenv('SLACK_URL'));
        }
    }

    function __destruct() {
        $this->setLastSyncUpdate();
    }

    public function fetch($data = [])
    {

        $feed = new SimplePie();
        if (count($data)) {
            $feed->set_feed_url($data);
        } else {
            $feed->set_feed_url($this->feedList['dataJustUrl']);
        }
        $feed->remove_div(true);
        $feed->enable_cache(true);
        $feed->set_cache_location( __DIR__ . '/cache');
        $feed->set_cache_duration(600);
        $feed->init();
        $feed->handle_content_type();

        $dataToInsert = [];
        foreach ($feed->get_items() as $key => $item){

            $link = $item->get_permalink();
            $guid = $item->get_id();
            $published_at = date('Y-m-d H:i:s', strtotime($item->get_date()));
            
            $permalink = $link;

            //MATCHING URL GOOGLE FEED PROXY
            if (strpos($link,'feedproxy') != false) {
                $orig = $item->get_item_tags('http://rssnamespace.org/feedburner/ext/1.0','origLink');
                $permalink = $orig[0]['data'];
            }

            //MATCHING URL DETIK
            $isMatchDetik = (bool)preg_match('/inet.detik.com/', $guid);
            if($isMatchDetik){
                $permalink = $guid;
            }
            //MATCHING URL CHIP
            $isMatchChip = (bool)preg_match('/chip.co.id/', $guid);
            if($isMatchChip){
                $published_at = date('Y-m-d H:i:s');
            }
            //MATCHING URL INDOTELKO
            $isMatchIndo = (bool)preg_match('/indotelko.com/', $guid);
            if($isMatchIndo){
                $published_at = date('Y-m-d H:i:s');
            }

            $dataToInsert[$key] = array(
                'rss_id' => md5($item->get_title()), // unique rss_id
                'title' => $item->get_title(), // title
                'description' => $item->get_content(), // description
                'content' => '',//$parsed->content, //content
                'source_link' => $permalink, // source link
                'tag_general' => '', // tag_general
                'tag_os' => '', // tag_os
                'tag_brands' => '', // tag_brands
                'tag_devices' => '', // tag_devices
                'tag_devices_id' => '', // tag_devices_id
                'tag_operators' => '', // tag_operators
                'status' => 'pending', // status
                'had_pushed' => 0, //had_pushed
                'hide_images' => 1, //show hide images
                'portal_id' => $this->getPortalId($item->get_feed()->subscribe_url()), // portal_id
                'user_id' => '100', // user_id
                'published_at' => $published_at, // published at
                'created_at' => date('Y-m-d H:i:s'), // created at
                'updated_at' => date('Y-m-d H:i:s') // updated at
            );

        }

        $this->dataToInsert = $dataToInsert;

        $this->insertIntoDatabase();

        return true;
    }

    private function setFeedList() {

        $feeds = $this->db->query("SELECT * FROM inpanel_rss_mst_portals")->fetchAll(PDO::FETCH_ASSOC);
        $list = array();
        foreach ($feeds as $feed) {
            $list['dataByKeyUrl'][$feed['url_feed']] = $feed;
            $list['dataJustUrl'][] = $feed['url_feed'];
        }

        $this->feedList = $list;

    }

    private function setLastSyncUpdate() {

        $timeSync = date('Y-m-d H:i:s');

        $this->db->update("inpanel_various_options"
            , [
                'value_one' => $timeSync
            ]
            , [
                'name' => 'rss-fetch'
            ]);

    }

    private function getPortalId($url_feed) {
        $portal_id = '';
        foreach($this->feedList['dataByKeyUrl'] as $kPortalUrl => $vPortalUrl){
            if($kPortalUrl==$url_feed){
                $portal_id = $vPortalUrl['id'];
            }
        }
        
        return $portal_id;
    
    }

    private function insertIntoDatabase(){
        $count = 0;
        $arrCounter = [];
        foreach ($this->dataToInsert as $data) {
            $insert = $this->db->insert("inpanel_rss_tbl", $data);
            if ($insert > 0 && !in_array($insert, $arrCounter)) {
                $count++;
                array_push($arrCounter, $insert);
            }

        }

        if ($count > 0 && $this->slack) {
            $this->slack->send("Halo, ada *" . $count . "* berita baru di database RSS kita, cek ya!");
        }
    }

}

$fetch = new FetchMyRss;
$fetch->fetch();
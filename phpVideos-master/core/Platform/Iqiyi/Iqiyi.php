<?php
/**
 * Created by PhpStorm.
 * User: mickey
 * Date: 2018/7/5
 * Time: 15:40
 */
namespace core\Platform\Iqiyi;

use core\Cache\FileCache;
use core\Common\ArrayHelper;
use core\Common\Downloader;
use core\Common\FFmpeg;
use core\Common\M3u8;
use core\Http\Curl;
use \DOMDocument;
use \DOMElement;
use \ErrorException;

class Iqiyi extends Downloader
{
    public function __construct(string $url)
    {
        $this->requestUrl = $url;
    }

    /**
     * @param int $key
     * @return string
     */
    public function getQu(int $key):string
    {
        $vd =  [
            4 => '720P',
            96 => '极速',
            1 => '流畅',
            2 => '高清',
            5 => '1080P',
            10 => '4K',
            6 => '2K',
            3 => '超清',
            19 => '4K',
            17 => '720P',
            14 => '720P',
            21 => '504P'
        ];

        return isset($vd[$key]) ? $vd[$key] : 'Unknown';
    }

    public function getClientTs():float
    {
        $microTime = microtime(true) * 1000;
        $time = round($microTime);
        return $time;
    }

    /**
     * @param string $tvid
     * @param string $vid
     * @return array|mixed|null|string
     * @throws ErrorException
     */
    public function getTmts(string $tvid, string $vid):array
    {
        $tmtsCache = (new FileCache())->get($tvid);
        if(!$tmtsCache){
            $tmtsUrl = 'http://cache.m.iqiyi.com/jp/tmts/'.$tvid.'/'.$vid.'/?';
            $t = $this->getClientTs();
            $src = '76f90cbd92f94a2e925d83e8ccd22cb7';
            $key = 'd5fb4bd9d50c4be6948c97edd7254b0e';
            $sc = md5($t.$key.$vid);
            $tmtsUrl .= 't='.$t.'&sc='. $sc .'&src='.$src;

            $tmtsInfo = Curl::get($tmtsUrl, 'https://wwww.iqiyi.com');
            if(!$tmtsInfo){
                $this->error('Errors：get tmts');
            }
            if(count($tmtsInfo[0]) < 1){
                $this->error('Errors：tmts info');
            }

            $videoInfo = ltrim($tmtsInfo[0], 'var tvInfoJs=');
            $tmtsCache = json_decode($videoInfo, true);
            (new FileCache())->set($tvid, $tmtsCache, 60);
        }

        return $tmtsCache;
    }

    /**
     * @return array
     * @throws ErrorException
     */
    public function getVideosInfo():array
    {
        $videoInfo = (new FileCache())->get($this->requestUrl.'video-info');
        if(!$videoInfo){
            $html = Curl::get($this->requestUrl, $this->requestUrl);
            if(!$html){
                $this->error('Errors：request error');
            }
            if(empty($html[0])){
                $this->error('Errors：html empty');
            }

            //取消使用 DOMDocument 匹配
            //$videoInfo = $this->documentPageInfo($html[0]);

            $pageInfo = $this->matchPageInfo($html[0]);
            if(empty($pageInfo)){
                $this->error('Errors：tvid empty');
            }
            $tvid = $pageInfo['tvId'];
            $vid = $pageInfo['vid'];
            $title = $pageInfo['tvName'];

            if(empty($vid)){
                $this->error('Errors：vid empty');
            }

            $videoInfo = [
                'title' => $title,
                'tvid' => $tvid,
                'vid' => $vid,
            ];
            (new FileCache())->set($this->requestUrl.'video-info', $videoInfo, 120);
        }

        return $videoInfo;
    }

    /**
     * @param DOMElement $dom
     * @return array
     */
    public function getPageInfo(DOMElement $dom):array
    {
        $iqiyiMainDiv = $dom->getElementsByTagName('div');
        $pageInfo = $iqiyiMainDiv->item(0)->getAttribute(':page-info');

        if(!$pageInfo){
            $this->error('Error：div page info is empty');
        }
        $pageInfo = json_decode($pageInfo, true);

        return $pageInfo;
    }

    /**
     * 使用 DOMDocument 查找 tvid\vid 等
     * @param string $html
     * @return array
     */
    public function documentPageInfo(string $html):array
    {
        $libErrors = libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $element = $dom->documentElement;
        $titleItem = $element->getElementsByTagName('title');
        $div = $dom->getElementById('flashbox');
        if($titleItem->length < 1){
            $this->error('Errors：not found title');
        }
        $title = $titleItem->item(0)->nodeValue;

        if(empty($title)){
            $this->error('Errors：title is empty');
        }

        libxml_use_internal_errors($libErrors);

        $tvid = $div->getAttribute('data-player-tvid');
        $vid = $div->getAttribute('data-player-videoid');

        if(empty($tvid)){
            $iqiyiMain = $dom->getElementById('iqiyi-main');
            $pageInfo = $this->getPageInfo($iqiyiMain);

            if(empty($pageInfo)){
                $this->error('Errors：tvid empty');
            }

            $tvid = $pageInfo['tvId'];
            $vid = $pageInfo['vid'];
        }

        $videoInfo = [
            'title' => $title,
            'tvid' => $tvid,
            'vid' => $vid,
        ];

        return $videoInfo;
    }

    /**
     * @date 2019-04-10
     * @param string $str
     * @param string $pattern
     * @return array
     */
    public function matchPageInfo(string $str, string $pattern="/:page-info='(.+?)'/i"):array
    {
        preg_match_all($pattern, $str, $matches);

        if(!$matches){
            $this->error('Error：div page info is empty');
        }
        $pageInfo = json_decode($matches[1][0], true);

        return $pageInfo;
    }

    /**
     * @param null $argvOpt
     * @throws ErrorException
     */
    public function download($argvOpt=null): void
    {
        $videoInfo = $this->getVideosInfo();
        $tmtsInfo = $this->getTmts($videoInfo['tvid'], $videoInfo['vid']);
        if($tmtsInfo['code'] != 'A00000'){
            $this->error('Errors：get mus error -》'.$tmtsInfo['code']);
        }

        $vidl = ArrayHelper::multisort($tmtsInfo['data']['vidl'], 'screenSize', SORT_ASC);

        $m3utxCache = (new FileCache())->get($videoInfo['tvid'].'m3utx');
        if(!$m3utxCache){
            $m3utx = Curl::get($vidl[0]['m3u'], $this->requestUrl);
            if(!$m3utx){
                $this->error('Errors：get m3utx error');
            }

            $m3utxCache = $m3utx[0];
            (new FileCache())->set($videoInfo['tvid'].'m3utx', $m3utxCache);
        }

        $m3u8Urls = M3u8::getUrls($m3utxCache);
        if(!$m3u8Urls){
            $this->error('Errors：m3u8Urls error');
        }

        $tsUrl = [];
        foreach($m3u8Urls as $row){
            $tempUrl = ltrim($row);
            array_push($tsUrl, $tempUrl);
        }

        $this->playlist = $vidl;
        $this->downloadUrls = $tsUrl;
        $this->setVideosTitle($videoInfo['title']);

        //show playlist
        if(isset($argvOpt['i'])){
            $this->outPlaylist();
        }

        $this->videoQuality = $this->getQu($vidl[0]['vd']);

        $this->fileExt = '.ts';
        $downloadFileInfo = $this->downloadFile();

        if($downloadFileInfo < 1024){
            printf("\n\e[41m%s\033[0m\n", 'Errors：download file 0');
        } else {
            FFmpeg::concatToMp4($this->videosTitle, $this->ffmpFileListTxt, './Videos/');
        }

        $this->deleteTempSaveFiles();
        $this->success($this->ffmpFileListTxt);
    }

}
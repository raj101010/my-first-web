<?php
/**
 * Created by PhpStorm.
 * User: mickey
 * Date: 2018/7/5
 * Time: 15:37
 */
namespace core\Http;

use core\Cache\FileCache;
use core\Config\Config;
use \ErrorException;

class Curl
{
    /**
     * @param string $url
     * @param string $httpReferer
     * @param array $options
     * @param bool $resCache
     * @param bool $ip
     * @return array|null
     * @throws ErrorException
     */
    public static function get(string $url,string $httpReferer,array $options=[],bool $resCache=false, $ip=false):?array
    {
        $cache = (new FileCache())->get($url);

        if($cache){
            $contents = $cache;
            $curlInfo = [
                'http_code' => 200,
            ];
        } else {
            $ch = curl_init();
            $defaultOptions = self::defaultOptions($url, $httpReferer, $ip);
            if($options){
                $defaultOptions = $options + $defaultOptions;
            }

            curl_setopt_array($ch, $defaultOptions);
            $chContents = curl_exec($ch);
            $curlInfo = curl_getinfo($ch);

            curl_close($ch);

            if($curlInfo['http_code'] != 200){
                $contents = null;
                $curlInfo['message'] = $chContents;
            } else {
                $contents = $chContents;
            }

            if($resCache){
                (new FileCache())->set($url, $contents);
            }
        }

        return [
            $contents,
            $curlInfo,
        ];
    }

    public static function post()
    {

    }

    public static function randIp()
    {
        return mt_rand(20,250).".".mt_rand(20,250).".".mt_rand(20,250).".".mt_rand(20,250);
    }

    /**
     * @param $url
     * @param $httpReferer
     * @param bool|string $ip
     * @return array
     */
    public static function defaultOptions($url, $httpReferer, $ip=false)
    {
        if(!$ip){
            $ip = self::randIp();
        }

        return [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_REFERER => $httpReferer,
            CURLOPT_USERAGENT => Config::instance()->get('user_agent'),
            CURLOPT_HTTPHEADER => [
                "Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
//                "Accept-Encoding:gzip, deflate, br",
                "Accept-Language:zh-CN,en-US;q=0.7,en;q=0.3",
                "HTTP_X_FORWARDED_FOR:{$ip}",
                "CLIENT-IP:{$ip}"
            ]
        ];
    }

}
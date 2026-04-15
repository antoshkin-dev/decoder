<?php
/*
 * Автоматические функции шифровальщика
 */

include ('./functions/functions.php');
include ('./functions/redisinit.php');
/* @var $redis Redis */

//Шедулеры разрешено запускать только с локального ip
if (getClientIP()!='127.0.0.1') { die ('hello world');}

$action=null;
CheckInput("action", $action, false, false);

if ($action=="resetsesscounters") //Обнуляем все счётчики всех ip
{
    $ips=$redis->keys('ClientIP:*');
    if (!is_array($ips)) { die('no ips'); }
    foreach ($ips as $ip)
    {
        echo $redis->del($ip) ; // . " / {$ip} \r\n";
        
    }
    die(" Session counters reseted\r\n");
}
elseif ($action=="tick") //Отсчитываем таймауты истечения срока жизни сессии
{
    $sessions=$redis->keys('*:timeout');
    if (!is_array($sessions)) { die('no sessions'); }
    foreach ($sessions as $sessiontimeout)
    {
        $sessionid=explode(":",$sessiontimeout);
        //Получаем таймаут этой сессии
        $timeout=$redis->get($sessiontimeout);
        $timeout--;
        if ($timeout<=0)
        {
            //Сессия окончилась, удаляем ее
            $redis->del($sessionid[0], "{$sessionid[0]}:ip","{$sessionid[0]}:timeout");
        }
        else
        {
            //Записываем новый таймаут сессии
            $redis->set($sessiontimeout,$timeout);
        }
    }
    die("Sessions timeout updated\r\n");
}

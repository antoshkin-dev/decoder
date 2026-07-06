<?php
/*
 * Автоматические функции шифровальщика
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
include ('./classes/PublicFunctions.php');
include ('./functions/redisinit.php');
/* @var $redis Redis */
use classes\PublicFunctions;
//Шедулеры разрешено запускать только с локального ip
//if (getClientIP()!='127.0.0.1') { die ('hello world');}

$action=null;
PublicFunctions::CheckInput("action", $action, false, false);

if ($action=="resetsesscounters") //Обнуляем все счётчики всех ip
{
    $ips=$redis->keys('ClientIP:*');
    if (!is_array($ips)) { TellOut('no ips'); }
    $reseted=0;
    foreach ($ips as $ip)
    {
        $reseted+=$redis->del($ip) ; 
        
    }
    TellOut("Session counters reseted. {$reseted} records removed");
}
elseif ($action=="tick") //Отсчитываем таймауты истечения срока жизни сессии
{
    $sessions=$redis->keys('*:timeout');
    if (!is_array($sessions)) { TellOut('no sessions'); }
    $totalsess=0;
    $timedoutsessions=0;
    foreach ($sessions as $sessiontimeout)
    {
        $totalsess++;
        $sessionid=explode(":",$sessiontimeout);
        //Получаем таймаут этой сессии
        $timeout=$redis->get($sessiontimeout);
        $timeout--;
        if ($timeout<=0)
        {
            //Сессия окончилась, удаляем ее
            $redis->del($sessionid[0], "{$sessionid[0]}:ip","{$sessionid[0]}:timeout");
            $timeout++;
        }
        else
        {
            //Записываем новый таймаут сессии
            $redis->set($sessiontimeout,$timeout);
        }
    }
    TellOut("Sessions timeout updated. Total: {$totalsess} / Timed out: {$timedoutsessions}");
}
function TellOut($msg)
{
    $date=date('d.m.Y H:i:s');
    die("({$date}) {$msg} \r\n");    
}

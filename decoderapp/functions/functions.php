<?php
/*
 * Модуль общих функций
 */

/**
 * Функция проверки и назначения переменных из массивов GET и POST
 * @param string $varname имя переменной в массиве
 * @param var $returnto переменная, в которую будет помезен результат
 * @param boolean $methodpost определяет, что переменная должна быть в массиве POST
 * @param boolean $isnum проеверяет, что искомая переменная - цифра
 * @return boolean
 */
function CheckInput($varname,&$returnto,$methodpost=true,$isnum=true)
{
    if ($methodpost===true)
    {
        $const=INPUT_POST;
    }
    else
    {
        $const=INPUT_GET;
    }
    $tempresult= filter_input($const, $varname);
    if ($tempresult===false || is_null($tempresult))
    {
        return false;
    }
    
    if ($isnum===true AND !is_numeric($tempresult)) 
    {
        return false; 
    }
    
    $returnto=$tempresult;
    return true;
}

/**
 * Функция проверки массива в глобальном массиве _POST или _GET
 * @param string $varname имя массива в массиве POST или GET
 * @param var $returnto возвращаемое значение при успешной проверке
 * @return boolean
 */
function CheckInputArray($varname,&$returnto,$ispost=true)
{
    $globalarr=($ispost) ? INPUT_POST : INPUT_GET;
    $tempresult= filter_input($globalarr, $varname,FILTER_DEFAULT,FILTER_REQUIRE_ARRAY);
    if (!$tempresult || is_null($tempresult))
    {
        return false;
    }
    $returnto=$tempresult;
    return true;
}

/**
 * Функция конвертации штампа времени в текствую дату с игнорирование часового пояса сервера PHP
 * @param integer $timestamp
 * @return string или false
 */
function TimeStampToStringDate($timestamp,$format="Y-m-d H:i:s", $timezone="UTC")
{
    if (!is_numeric($timestamp))
    {
        return false;
    }
    $dateobj=new DateTime("now", new DateTimeZone($timezone));
    $dateobj->setTimestamp($timestamp);
    return $dateobj->format($format);
}

/**
 * Функция получения корректной даты по заданной временной зоне
 * @param string $format формат возвращаемой даты
 * @return string
 */
function GetNow($format="Y-m-d H:i:s",$timezone="Asia/Almaty")
{
    $dateobj=new DateTime("now", new DateTimeZone($timezone));
    $dateobj->setTimestamp(time());
    return $dateobj->format($format);
}

/**
 * Возвращает IP-адрес клиента
 * @return string | false IP-адрес клиента
 */
function getClientIP()
{
    return (is_string($_SERVER['REMOTE_ADDR'])) ? $_SERVER['REMOTE_ADDR'] : false;
}





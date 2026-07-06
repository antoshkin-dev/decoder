<?php
/*
 * Класс общих функций 
 */
namespace classes;
class PublicFunctions
{
    /**
     * Функция проверки и назначения переменных из массивов GET и POST
     * @param string $varname имя переменной в массиве
     * @param var $returnto переменная, в которую будет помечен результат
     * @param boolean $methodpost определяет, что переменная должна быть в массиве POST
     * @param boolean $isnum проверяет, что искомая переменная - цифра
     * @return boolean
     */
    public static function CheckInput(string $varname,&$returnto,bool $methodpost=true,bool $isnum=true) : bool
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
     * Возвращает IP-адрес клиента
     * @return string|bool IP-адрес клиента
     */
    public static function getClientIP() : string|bool
    {
        return (is_string($_SERVER['REMOTE_ADDR'])) ? filter_var($_SERVER['REMOTE_ADDR'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) : false;
    }
    
    /**
     * Функция возвращает значение HTTP-заголовка по его имени
     * @param string $headername Искомый заголовок
     * @return string|bool  
     */
    public static function getHeaderValue(string $headername) : string|bool
    {
        $hnlower=strtolower($headername);
        $headers = getallheaders();
        foreach ($headers as $key => $value)
        {
            if (strtolower($key)===$hnlower)
            {
                return $value;
            }
        }
        return false;
    }

}
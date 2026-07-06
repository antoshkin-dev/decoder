<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include ('./functions/redisinit.php');
include ('./classes/PublicFunctions.php');
include('./classes/DecoderProcessor.php');
include('./classes/AVCryptor.php');

use classes\DecoderProcessor;
use classes\PublicFunctions;

/* @var $redis Redis */
$action=null;
$answer='';
$result=null;

$procaction=new DecoderProcessor($redis);

//Action в формате RESTAPI
$path = trim(filter_input(INPUT_SERVER,'REQUEST_URI'),"/");
if (empty($path) || is_null($path)) { PathError(); }

$parts = explode('/', $path);
$action = $parts[1] ?? null;

if (is_null($action)) //Legacy
{
    //Action из get или post запроса
    PublicFunctions::CheckInput("action", $action,false,false);
    PublicFunctions::CheckInput("action", $action,true,false);
}

if (is_null($action) or empty($action))
{
    $procaction->AddFileLog("no action");
    $procaction->MakeAnswer(false,"Command is missing!");
}

$procaction->AddFileLog("action: *{$action}*");


switch ($action)
{
    case "checkmasterkey": //Проверка установлен ли мастер-ключ в памяти
        $procaction->CheckMasterKeyIsSet();
        break;
    case "setmasterkey": //Установка мастер-ключа
        $procaction->SetMasterKey();
        break;
    case "newsession": //Создание новой пользовательской сессии
        $procaction->NewSession();
        break;
    case "destroysession":
        $procaction->DestroySession();
        break;
    case "ping": //Восстанавливаем таймаут сессии в 3600
        $procaction->Ping();
        break;
    case "cryptbymaster":
        $procaction->CryptByMaster();
        break;
    case "decryptbymaster": //Прямая расшифровка данных по мастер-ключу
        $procaction->DecryptByMaster(); 
        break;
    case "decryptforuser": //Расшифровка мастером и шифровка по сессии пользователя
        $procaction->DecryptForUser();
        break;
    case "decryptbysession": //Расшифровка при помощи ключа сессии пользователя
        $procaction->DecryptByUserSession();
        break;
    case "changemaster": //Перешифрование из одного мастер-ключа в другой
        $procaction->ChangeToNewMaster();
        break;
    default:
        $procaction->MakeAnswer(false,"Bad command *{$action}*"); 
        break;
}
//if ($action=="checkmasterkey") //Проверка установлен ли мастер-ключ в памяти
//{
//    $procaction->CheckMasterKeyIsSet();    
//}
//elseif ($action=='setmasterkey') //Установка мастер-ключа
//{
//    $procaction->SetMasterKey();
//}
//elseif ($action=='newsession') //Создание новой пользовательской сессии
//{
//    $procaction->NewSession();
//}
//elseif ($action=="destroysession")
//{
//    $procaction->DestroySession();
//}
//elseif ($action=="ping") //Восстанавливаем таймаут сессии в 3600
//{
//    $procaction->Ping();
//}
//elseif ($action=="cryptbymaster")
//{
//    $procaction->CryptByMaster();
//}
//elseif ($action=="decryptbymaster") //Прямая расшифровка пароля по мастер-ключу
//{
//    //!!! Для отладки. В обычном режиме функция должна быть отключена
//    $procaction->DecryptByMaster();
//}
//elseif ($action=="decryptforuser") //Расшифровка мастером и шифровка по сессии пользователя
//{
//    $procaction->DecryptForUser();
//}
//elseif ($action=="decryptbysession") //Расшифровка при помощи ключа сессии пользователя
//{
//    $procaction->DecryptByUserSession();
//}
//elseif ($action=="changemaster")
//{
//    $procaction->ChangeToNewMaster();
//}
//else
//{
//    $procaction->MakeAnswer(false,"Bad command"); 
//}
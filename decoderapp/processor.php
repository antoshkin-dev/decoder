<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include ('./functions/functions.php');
include ('./functions/redisinit.php');
include('./procactions.php');
include('./functions/crypto.php');


/* @var $redis Redis */
$action=null;
$answer='';
$result=null;

$procaction=new ProcessorActions($redis);
CheckInput("action", $action,false,false);
CheckInput("action", $action,true,false);
if (is_null($action))
{
    $procaction->AddFileLog("no cmd");
    $procaction->MakeAnswer(false,"Command missing!");
}
$procaction->AddFileLog("action: {$action}");

if ($action=="checkmasterkey") //Проверка установлен ли мастер-ключ в памяти
{
    $procaction->CheckMasterKeyIsSet();    
}
elseif ($action=='setmasterkey') //Установка мастер-ключа
{
    $procaction->SetMasterKey();
}
elseif ($action=='newsession') //Создание новой пользовательской сессии
{
    $procaction->NewSession();
}
elseif ($action=="destroysession")
{
    $procaction->DestroySession();
}
elseif ($action=="ping") //Восстанавливаем таймаут сессии в 3600
{
    $procaction->Ping();
}
elseif ($action=="cryptbymaster")
{
    $procaction->CryptByMaster();
}
elseif ($action=="decryptbymaster") //Прямая расшифровка пароля по мастер-ключу
{
    //!!! Для отладки. В обычном режиме функция должна быть отключена
    $procaction->DecryptByMaster();
}
elseif ($action=="decryptforuser") //Расшифровка мастером и шифровка по сессии пользователя
{
    $procaction->DecryptForUser();
}
elseif ($action=="decryptbysession") //Расшифровка при помощи ключа сессии пользователя
{
    $procaction->DecryptByUserSession();
}
elseif ($action=="changemaster")
{
    $procaction->ChangeToNewMaster();
}
else
{
    $procaction->MakeAnswer(false,"Bad command"); 
}
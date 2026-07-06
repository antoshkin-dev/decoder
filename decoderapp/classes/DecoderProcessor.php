<?php
namespace classes;
use Redis;
class DecoderProcessor
{
    private ?Redis $redis=null;
    
    /**
     * 
     * Списки белых IP на которые не распространяется подсчет количества запросов сессий
     * @var array 
     */
    private $whiteiplist=null;
    
    /**
     * Время действия сессии в минутах
     */
    private const SessionTTL=60; 
   
    /**
     * 
     * @param Redis $redis ссылка на инициализированный класс Redis
     */
    public function __construct(Redis $redis) 
    {
        $this->redis=$redis;
        //Белые списки IP
        $this->whiteiplist[]='127.0.0.1';
    }
    
    /**
     * Запись журнала
     * @param string $text - текст для записи в журнал
     * @return void
     */
    public function AddFileLog(string $text) : void
    {
        $logdate=date('Ymd');
        $logfilename="logs/processor_{$logdate}.log";
        $ip= PublicFunctions::getClientIP();
        $logtime=date("H:i:s",  time());

        $stream=fopen($logfilename,'a');
        $clearlog=<<<eot
    {$logtime} {$ip}: {$text}

    eot;
        fwrite($stream, $clearlog);
        fclose($stream);
    }

    /**
     * Функция формирования ответа клиенту. После отправки ответа, 
     * обработка скрипта будет прервана
     * @param bool $result - какой результат мы хотим отправить клиенту
     * @param string $resultdescription - описание результата
     * @param type $data - данные, полученные в процессе работы класса декодера
     * @return void
     */
    public function MakeAnswer(bool $result, string $resultdescription, $data=null) : void
    {
        $answer['result']['status']=$result;
        $answer['result']['description']=$resultdescription;
        if ($result===true && !empty($data))
        {
            $answer['data']=$data;
        }
        elseif ($result===false)
        {
            http_response_code(400);
        }
        $janswer=json_encode($answer);
        echo $janswer;
        $this->AddFileLog("Result: {$result} {$resultdescription}" );
        die();
    }
    
    /**
     * Внутренняя проверка наличия мастер-ключа в памяти Редис по его имени
     * @param string $masterkeyname - имя мастер-ключа
     * @return bool
     */
    private function CheckMasterKey(string $masterkeyname) : bool
    {
        if (count($this->redis->keys('MasterKey:' . $masterkeyname))!=1 or empty($masterkeyname))
        {
            return false;
        }
        else 
        {
            return true;
        }
    }
    
    /**
     * Возвращает клиенту статус мастер-ключа - инициализирован или нет
     * @return void
     */
    public function CheckMasterKeyIsSet() : void
    {
        $masterkeyname=$this->GetActionVariable("masterkeyname");
        if (!$masterkeyname)
        {
            $this->MakeAnswer(false, "Master key name is missing in query");
        }
        if (!$this->CheckMasterKey($masterkeyname))
        {
            $this->MakeAnswer(false,"Master key is not initialized");
        }
        else
        {
            if (PublicFunctions::getClientIP()===$this->GetMasterKeyOwner($masterkeyname))
            {
                $data['ownerisyou']=true;
            }
            else 
            {
                $data['ownerisyou']=false;
            }
            
            $this->MakeAnswer(true,"Master key is set",$data);
        }
    }

    /**
     * Инициализация мастер-ключа по запросу от клиента.
     * Первый IP, который зарегистрирует ключ, становится его владельцем.
     * Только владелец сможет переустановить ключ или удалить его.
     * @return void
     */
    public function SetMasterKey() : void
    {
        $masterkeyvalue=$this->GetActionVariable("key");
        $masterkeyname=$this->GetActionVariable("masterkeyname");
        if (!$masterkeyname)
        {
            $this->MakeAnswer(false, "Master key name is missing in query");
        }
        if (!$masterkeyvalue)
        {
            $this->MakeAnswer(false, "Master key value is missing in query");
        }
        
        $clientip=PublicFunctions::getClientIP();
        //Проверяем, есть ли мастер-ключ в памяти, если есть, то изменить
        //его может только владелец (первоначально зарегистрировавший)
        if ($this->CheckMasterKey($masterkeyname))
        {
            $this->CheckMasterKeyOwner($masterkeyname);
        }
        
        //Устанавливаем значение мастер-ключа
        if (!$this->redis->set("MasterKey:{$masterkeyname}", $masterkeyvalue))
        {
            $this->MakeAnswer(false, "Can't set Master key in Redis. Please view log files on Decoder");
        }
        
        //Устанавливаем владельца мастер-ключа
        $this->redis->set("MasterKeyOwner:{$masterkeyname}",$clientip);
        
        $masterkeyvalue=null;
        $data['owner']=$clientip;
        $data['masterkey']=$masterkeyname;
        $this->MakeAnswer(true, "Master key is set successfully",$data);
    }
    
    /**
     * Функция проверяет, является ли владельцем мастер-ключа текущий клиент.
     * Если владелец не соответсвует, то клиенту будет отправлено сообщение об 
     * ограничении доступа к выполняемой операции
     * @param string $masterkeyname - имя мастер-ключа
     * @return void
     */
    private function CheckMasterKeyOwner(string $masterkeyname) : void
    {
        if ($this->GetMasterKeyOwner($masterkeyname)!==PublicFunctions::getClientIP())
        {
            $this->MakeAnswer(false, "Master key has another owner");
        }
    }

    /**
    * Проверка количества операций с сессиями конкретного IP.
    * Нужно для пресечения флуд-атак
    */
    private function GetSessionOperations() : void
    {
        $ip=PublicFunctions::getClientIP();
        if (!$ip) { $this->MakeAnswer(false, "Bad client IP"); }

        //Не выполняем проверку, если IP в белом списке (сервера Vault)
        if (in_array($ip, $this->whiteiplist)) {  return; }

        //Проверяем, что данный IP не имеет предела по количеству новых сессий за период
        $askcount=$this->redis->get("ClientIP:{$ip}");
        if ($askcount!==false && $askcount>10)
        {
            $this->MakeAnswer(false, "Max client session limit reached");
        }
        if ($askcount===false) //Данный клиент подключается в первый раз и его зарубки о попытках подключения еще нет
        {
            $askcount=1;
        }
        else
        {
            $askcount++;
        }
        //Записываем в базу количество событий получения сессий
        if (!$this->redis->set("ClientIP:{$ip}",$askcount))
        {
           $this->MakeAnswer(false, "Can't write client status to Redis");
        }    
    }
    
    private function GetActionVariable(string $varname) : string | bool
    {
        $contenttype=PublicFunctions::getHeaderValue('content-type');
        if (strpos($contenttype,'application/json')!==false) //RAW json 
        {
            try 
            {
                $jsondata=json_decode(file_get_contents('php://input'),true);
            } 
            catch (Exception $exc) 
            {
                $this->MakeAnswer(false,"JSON parsing error",$exc->getMessage());
            }
            if (!is_array($jsondata)) 
            {
                $this->MakeAnswer(false,"JSON is not array");
            }
            if (!isset($jsondata[$varname])) //Если нет запрошенной переменной в JSON-данных запроса
            {
                return false;
            }
            return $jsondata[$varname];
        }
        elseif (strpos($contenttype, 'multipart/form-data')!==false OR strpos($contenttype, 'form-urlencoded')!==false) //Переменные из мультиформы post-запроса
        {
            $result=null;
            if (!PublicFunctions::CheckInput($varname,$result,true,false)) { return false; }
            return $result;
        }
        else 
        {
            $this->MakeAnswer(false, "Request variable error");
        }
    }
   
    
    /**
     * Генерация случайного ключа из /dev/random
     * @param int $bit_length
     * @return string|null
     */
    private function getRandomKey(int $bit_length = 128) : string | null
    {
        $fp = fopen('/dev/urandom','rb');
        if ($fp !== FALSE) 
        {
            $key = substr(base64_encode(fread($fp,round(($bit_length + 7) / 8))), 0, round(($bit_length + 5) / 6)  - 2);
            fclose($fp);
            return $key;
        }
        return null;
    }
   
    /**
     * Регистрация новой клиенсткой сессии декодера
     * @return void
     */
    public function NewSession() : void
    {
        $this->GetSessionOperations(); //Прибавляем каунтер операций по работе с сессиями
        $session['sessionid']=$this->getRandomKey();
        $session['sessionkey']=$this->getRandomKey(1024);
        if (!$this->redis->set($session['sessionid'],$session['sessionkey']))
        {
            $this->MakeAnswer(false, "Can't write new session data to Redis");
        }
        if (!$this->redis->set("{$session['sessionid']}:ip",PublicFunctions::getClientIP()))
        {
            $this->MakeAnswer(false, "Can't write new session ip data to Redis");
        }
        if (!$this->redis->set("{$session['sessionid']}:timeout",self::SessionTTL))
        {
            $this->MakeAnswer(false, "Can't write new session timeout data to Redis");
        }
        $this->MakeAnswer(true, "Session created", $session);
    }

    /**
     * Удаление клиентской сессии декодера
     * @return void
     */
    public function DestroySession() : void
    {
        $this->GetSessionOperations(); //Прибавляем каунтер операций по работе с сессиями
        $sessionid=$this->GetActionVariable("sessionid");
        if (!$sessionid)
        {
            $this->MakeAnswer(false, "No session id");
        }
        //Удаляем сессию 
        if (!$this->redis->del($sessionid, "{$sessionid}:ip","{$sessionid}:timeout"))
        {
            $this->MakeAnswer(false, "Can't destroy session");
        }
        $this->MakeAnswer(true, "Session destroyed");
    }

    /**
     * Получение ключа сессии по её ID
     * @param string $sessionid - код клиентской сессии декодера
     * @return string
     */
    private function GetSessionKey(string $sessionid) : string
    {
        $this->GetSessionOperations(); //Прибавляем каунтер операций по работе с сессиями
        //Ищем сессию
        $sessionkey=$this->redis->get($sessionid);
        return $sessionkey;
    }

    /**
     * Продление времени жизни сессии клиентом
     * @return void
     */
    public function Ping() : void
    {
        $sessionid=$this->GetActionVariable("sessionid");
        if (!$sessionid)
        {
            $this->MakeAnswer(false, "No session id");
        }
        if ($this->redis->get($sessionid))
        {
            $this->redis->set("{$sessionid}:timeout",self::SessionTTL);
        }
        else
        {
            $this->MakeAnswer(false, "Session not found");
        }
        $this->MakeAnswer(true, "pong");
    }

    /**
     * Получение значения мастер ключа из Redis
     * @param string $masterkeyname - имя мастер-ключа
     * @return string
     */
    private function GetMasterKeyValue(string $masterkeyname) : string
    {
        return $this->redis->get("MasterKey:{$masterkeyname}");
        //return $this->redis->get($masterkeyname);
    }
    
    /**
     * Получегие IP-адреса владельца мастер-ключа
     * @param string $masterkeyname - имя мастер-ключа
     * @return string
     */
    private function GetMasterKeyOwner(string $masterkeyname) : string
    {
        return $this->redis->get("MasterKeyOwner:{$masterkeyname}");
    }
    
    /**
     * Шифрование заданного текста заданным мастер-ключом.
     * Шифровать мастер-ключом может любой клиент.
     * @return void
     */
    public function CryptByMaster() : void
    {
        $textforcrypt=$this->GetActionVariable("text");
        
        if (!$textforcrypt)
        {
            $this->MakeAnswer(false, "Text for crypt is missing");
        }
        
        //$this->MakeAnswer(false, "cryptor: {$textforcrypt}");
        $masterkeyname=$this->GetActionVariable("masterkeyname");
        if (!$masterkeyname)
        {
            $this->MakeAnswer(false, "Master key name is missing");
        }
        
        
        $result['cryptedtext']=$this->HashByMaster($masterkeyname, $textforcrypt);
        $this->MakeAnswer(true,"data crypted", $result);
        
    }
    
    /**
     * Шифрование текста заданным мастер-ключом
     * @param string $masterkeyname - имя мастер-ключа
     * @param string $textforcrypt - текст для шифрования
     * @return string
     */
    private function HashByMaster(string $masterkeyname,string $textforcrypt) : string
    {
        if (!$this->CheckMasterKey($masterkeyname))
        {
            $this->MakeAnswer(false, "Master key is not found");
        }
        $passphrase=$this->GetMasterKeyValue($masterkeyname);
        $cryptor=new AVCryptor($passphrase);
        return $cryptor->MakeCrypted($textforcrypt);
    }
    
    /**
     * Расшифровка заданного текста заданным мастер-ключом
     * @param string $masterkeyname - имя мастер-ключа
     * @param string $cryptedtext - зашифрованный текст в формате Base64
     * @return string
     */
    private function DehashByMaster(string $masterkeyname,string $cryptedtext) : string
    {
        if (!$this->CheckMasterKey($masterkeyname))
        {
            $this->MakeAnswer(false, "Master key is not found");
        }
        $passphrase=$this->GetMasterKeyValue($masterkeyname);
        $cryptor=new AVCryptor($passphrase);
        
        $result=$cryptor->GetTextBack($cryptedtext);
        $passphrase=null;
        $cryptor=null;
        return $result;
    }
    
    /**
     * Шифрование текста по ключу из клиентской сессии декодера
     * @param string $sessionkey - ключ сессии
     * @param string $textforcrypt - текст для шифрования
     * @return string
     */
    private function HashByUserSessionKey(string $sessionkey,string $textforcrypt) : string
    {
        
        $key=hex2bin(substr(hash('sha256',$sessionkey),0,32));
        $keyb64=base64_encode($key);
        
        $cryptor=new AVCryptor($key);
        //$this->AddFileLog("SessionKey: " . $keyb64);
        $result=$cryptor->MakeCrypted($textforcrypt);
        //$this->AddFileLog($cryptor->debug);
        $passphrase=null;
        $cryptor=null;
        return $result;
    }
    
    /**
     * Процесс перешифрования текста из одного мастер-ключа в другой.
     * Клиент должен быть владельцем исходного мастер-ключа.
     * @return void
     */
    public function ChangeToNewMaster() : void
    {
        $srcmasterkey=$this->GetActionVariable("srcmasterkey");
        if (!$srcmasterkey)
        {
            $this->MakeAnswer(false, "Source master key name is missing");
        }
        
        $this->CheckMasterKeyOwner($srcmasterkey);
        
        $dstmasterkey=$this->GetActionVariable("dstmasterkey");
        if (!$dstmasterkey)
        {
            $this->MakeAnswer(false, "Destination master key name is missing");
        }
        
        $textforrecrypt=$this->GetActionVariable("cryptedtext");
        if (!$textforrecrypt)
        {
            $this->MakeAnswer(false, "Crypted text is missing");
        }
        
        //Дешифруем мастером источника
        $rowtext=$this->DehashByMaster($srcmasterkey, $textforrecrypt);
        
        //Шифруем мастером назначения
        $newcyptedtext=$this->HashByMaster($dstmasterkey, $rowtext);
        
        $result['cryptedtext']=$newcyptedtext;
        $this->MakeAnswer(true,"data recrypted", $result);
    }
    
    /**
     * Расшифровка заданного текста заданным мастер-ключом.
     * Прямая расшифровка доступна лишь владельцу мастер-ключа.
     * @return void
     */
    public function DecryptByMaster() : void
    {
        $textfordecrypt=$this->GetActionVariable("cryptedtext");
        if (!$textfordecrypt)
        {
            $this->MakeAnswer(false, "Crypted text is missing");
        }
        $masterkeyname=$this->GetActionVariable("masterkeyname");
        if (!$masterkeyname)
        {
            $this->MakeAnswer(false, "Master key name is missing");
        }
        
        $this->CheckMasterKeyOwner($masterkeyname);

        $result['rowtext']=$this->DehashByMaster($masterkeyname, $textfordecrypt);
        if (!$result['rowtext'])
        {
            $this->MakeAnswer(false, "Decoding failed");
        }
        $this->MakeAnswer(true,"decrypted", $result);
    }

    /**
     * Расшифровка текста, закрытого мастер-ключом и повторное шифрование ключом
     * из сессии клиента декодера.
     * Функция доступна только владельцам мастер-ключей
     * @return void
     */
    public function DecryptForUser() : void
    {
        $textfordecrypt=$this->GetActionVariable("cryptedtext");
        if (!$textfordecrypt)
        {
            $this->MakeAnswer(false, "Crypted text is missing");
        }
        $masterkeyname=$this->GetActionVariable("masterkeyname");
        if (!$masterkeyname)
        {
            $this->MakeAnswer(false, "Master key name is missing");
        }

        $usersessionid=$this->GetActionVariable("sessid");
        if (!$usersessionid)
        {
            $this->MakeAnswer(false, "User session ID is missing");
        }
        
        $this->CheckMasterKeyOwner($masterkeyname);

        //Получаем ключ сессии пользователя
        $usersessionkey=$this->GetSessionKey($usersessionid) OR $this->MakeAnswer(false, "Can't find user session");

        //Расшифровываем пароль мастер ключом
        $decryptedpass=$this->DehashByMaster($masterkeyname, $textfordecrypt);
        if (!$decryptedpass) 
        {
            $this->MakeAnswer(false, "Decryption error");
        }

        //Шифруем пароль ключом пользовательской сессии
        $cryptedforuser=$this->HashByUserSessionKey($usersessionkey, $decryptedpass);
        $decryptedpass=null;
        $result['requiredpassword']=$cryptedforuser;
        $this->MakeAnswer(true, "Password for user",$result);
    }
    
    /**
     * Расшифровка данных, зашифрованных ключом сессии клиента декодера.
     * 
     * @return void
     */
    public function DecryptByUserSession() : void
    {
        $cryptedtext=$this->GetActionVariable("cryptedtext");
        if (!$cryptedtext)
        {
            $this->MakeAnswer(false, "Crypted text is missing");
        }
        //$cryptedtext=htmlspecialchars_decode($cryptedtext);
        
        
        $usersessionid=$this->GetActionVariable("sessid");
        if (!$usersessionid)
        {
            $this->MakeAnswer(false, "User session ID is missing");
        }
        $this->AddFileLog($cryptedtext);
        //Получаем ключ сессии пользователя
        $usersessionkey=$this->GetSessionKey($usersessionid) OR $this->MakeAnswer(false, "Can't find user session: {$usersessionid}");
        
        $key=hex2bin(substr(hash('sha256',$usersessionkey),0,32));
        $keyb64=base64_encode($key);
        $cryptor=new AVCryptor($key);
        
        $result=$cryptor->GetTextBack($cryptedtext);
        if (!$result)
        {
            $this->AddFileLog($cryptor->debug);
            $this->MakeAnswer(false, "Decryption failed. {$cryptedtext} key: {$keyb64}");
        }
        $data['authhash']=$result;
        $this->MakeAnswer(true,"Success",$data);
    }
    
}
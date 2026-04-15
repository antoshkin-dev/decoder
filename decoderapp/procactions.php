<?php

class ProcessorActions
{
    /* @var $redis Redis */
    private $redis=null;
    
    /**
     * Списки белых IP на которые не распространяется подсчет количества запросов сессий
     * @var array 
     */
    private $whiteiplist=null;
    
    /**
     * Время действия сессии в минутах
     */
    private const SessionTTL=60; 
    
    public function __construct($redis) 
    {
        $this->redis=$redis;
        //Белые списки IP
        $this->whiteiplist[]='127.0.0.1';
    }
    
    /**
    * Возвращает IP-адрес клиента
    * @return string | false IP-адрес клиента
    */
    private function getClientIP()
    {
        return (is_string($_SERVER['REMOTE_ADDR'])) ? $_SERVER['REMOTE_ADDR'] : false;
    }
    
    public function AddFileLog($text)
    {
        $logdate=date('Ymd');
        $logfilename="logs/processor_{$logdate}.log";
        $ip= $this->getClientIP();
        $logtime=date("H:i:s",  time());

        $stream=fopen($logfilename,'a');
        $clearlog=<<<eot
    {$logtime} {$ip}: {$text}

    eot;
        fwrite($stream, $clearlog);
        fclose($stream);
    }
    
    public function MakeAnswer(bool $result, string $resultdescription, $data=null)
    {
        $answer['result']['status']=$result;
        $answer['result']['description']=$resultdescription;
        if ($result===true && !empty($data))
        {
            $answer['data']=$data;
        }
        $janswer=json_encode($answer);
        echo $janswer;
        $this->AddFileLog("Result: {$result} {$resultdescription}" );
        die();
    }
    
    /**
     * Внутренняя проверка наличия мастер-ключа в памяти Редис по его имени
     */
    private function CheckMasterKey($masterkeyname)
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
     * Возвращает статус мастер-ключа - инициализирован или нет
     */
    public function CheckMasterKeyIsSet()
    {
        $masterkeyname=null;
        if (!CheckInput("masterkeyname", $masterkeyname, true, false))
        {
            $this->MakeAnswer(false, "Master key name is missing in query");
        }
        if (!$this->CheckMasterKey($masterkeyname))
        {
            $this->MakeAnswer(false,"Master key is not initialized");
        }
        else
        {
            $this->MakeAnswer(true,"Master key is set");
        }
    }

    /**
     * Регистрация мастер-ключа в памяти Редис
     */
    public function SetMasterKey()
    {
        $masterkey=null;
        $masterkeyname=null;
        if (!CheckInput("masterkeyname", $masterkeyname, true, false))
        {
            $this->MakeAnswer(false, "Master key name is missing in query");
        }
        if (!CheckInput("key", $masterkey, true, false))
        {
            $this->MakeAnswer(false, "Master key is missing in query");
        }
        if (!$this->redis->set("MasterKey:{$masterkeyname}", $masterkey))
        {
            $this->MakeAnswer(false, "Can't set Master key in Redis. Please view log files on Decoder");
        }
        $masterkey=null;
        $this->MakeAnswer(true, "Master key is set successfully");
    }

    /**
    * Получение количества операций с сессиями конкретного IP.
    * Нужно для пресечения флуд-атакиб
    */
   private function GetSessionOperations()
   {
       $ip=$this->getClientIP();
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
    
   
    /**
     * Generate a random key from /dev/random
     */
    private function getRandomKey($bit_length = 128)
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
     * Создаём сессию пользователя - генерим ID и ключ для сессии
     */
    public function NewSession()
    {
        $this->GetSessionOperations(); //Прибавляем каунтер операций по работе с сессиями
        $session['sessionid']=$this->getRandomKey();
        $session['sessionkey']=$this->getRandomKey(1024);
        if (!$this->redis->set($session['sessionid'],$session['sessionkey']))
        {
            $this->MakeAnswer(false, "Can't write new session data to Redis");
        }
        if (!$this->redis->set("{$session['sessionid']}:ip",$this->getClientIP()))
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
     * Удаление параметров существующей сессии
     */
    public function DestroySession()
    {
        $this->GetSessionOperations(); //Прибавляем каунтер операций по работе с сессиями
        $sessionid=null;
        if (!CheckInput("sessionid",$sessionid,true,false))
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

    private function GetSessionKey($sessionid)
    {
        $this->GetSessionOperations(); //Прибавляем каунтер операций по работе с сессиями
        //Ищем сессию
        $sessionkey=$this->redis->get($sessionid);
        return $sessionkey;
    }

    /**
     * Восстановление таймаута сессии 
     */
    public function Ping()
    {
        $sessionid=null;
        if (!CheckInput("sessionid",$sessionid,true,false))
        {
            $this->MakeAnswer(false, "No session id");
        }
        if ($this->redis->get($sessionid))
        {
            $this->redis->set("{$sessionid}:timeout",self::SessionTTL);
        }
        $this->MakeAnswer(true, "pong");
    }

    private function GetMasterKeyValue($masterkeyname)
    {
        return $this->redis->get("MasterKey:{$masterkeyname}");
        //return $this->redis->get($masterkeyname);
    }
    
    public function CryptByMaster()
    {
        $textforcrypt=null;
        
        if (!CheckInput("text", $textforcrypt, true, false))
        {
            $this->MakeAnswer(false, "Text for crypt is missing");
        }
        
        //$this->MakeAnswer(false, "cryptor: {$textforcrypt}");
        $masterkeyname=null;
        if (!CheckInput("masterkeyname", $masterkeyname, true, false))
        {
            $this->MakeAnswer(false, "Master key name is missing");
        }
        
        
        $result['cryptedtext']=$this->HashByMaster($masterkeyname, $textforcrypt);
        $this->MakeAnswer(true,"data crypted", $result);
        
    }
    
    private function HashByMaster($masterkeyname,$textforcrypt)
    {
        if (!$this->CheckMasterKey($masterkeyname))
        {
            $this->MakeAnswer(false, "Master key is not found");
        }
        $passphrase=$this->GetMasterKeyValue($masterkeyname);
        //$this->MakeAnswer(false, "*{$passphrase}*");
        $cryptor=new AVCryptor($passphrase);
        return $cryptor->MakeCrypted($textforcrypt);
        //$result['cryptedtext']=$cryptor->MakeCrypted($textforcrypt);
        //$passphrase=null;
        //$cryptor=null;
        //$this->MakeAnswer(true,"data crypted", $result);
    }
    
    private function DehashByMaster($masterkeyname,$cryptedtext)
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
    
    private function HashByUserSessionKey($sessionkey,$textforcrypt)
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
    
    
    public function ChangeToNewMaster()
    {
        $srcmasterkey=null;
        if (!CheckInput("srcmasterkey", $srcmasterkey, true, false))
        {
            $this->MakeAnswer(false, "Source master key name is missing");
        }
        
        $dstmasterkey=null;
        if (!CheckInput("dstmasterkey", $dstmasterkey, true, false))
        {
            $this->MakeAnswer(false, "Destination master key name is missing");
        }
        
        $textforrecrypt=null;
        if (!CheckInput("cryptedtext", $textforrecrypt, true, false))
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
    
    public function DecryptByMaster()
    {
        $textfordecrypt=null;
        if (!CheckInput("cryptedtext", $textfordecrypt, true, false))
        {
            $this->MakeAnswer(false, "Crypted text is missing");
        }
        $masterkeyname=null;
        if (!CheckInput("masterkeyname", $masterkeyname, true, false))
        {
            $this->MakeAnswer(false, "Master key name is missing");
        }

        $result['rowtext']=$this->DehashByMaster($masterkeyname, $textfordecrypt);
        if (!$result['rowtext'])
        {
            $this->MakeAnswer(false, "Decoding failed. {$textfordecrypt}");
        }
        $this->MakeAnswer(true,"decrypted", $result);
    }
    
    public function DecryptForUser()
    {
        $textfordecrypt=null;
        if (!CheckInput("cryptedtext", $textfordecrypt, true, false))
        {
            $this->MakeAnswer(false, "Crypted text is missing");
        }
        $masterkeyname=null;
        if (!CheckInput("masterkeyname", $masterkeyname, true, false))
        {
            $this->MakeAnswer(false, "Master key name is missing");
        }

        $usersessionid=null;
        if (!CheckInput("sessid", $usersessionid,true,false))
        {
            $this->MakeAnswer(false, "User session ID is missing");
        }

        //Получаем ключ сессии пользователя
        $usersessionkey=$this->GetSessionKey($usersessionid) OR $this->MakeAnswer(false, "Can't find user session");

        //Расшифровываем пароль мастер ключом
        $decryptedpass=$this->DehashByMaster($masterkeyname, $textfordecrypt);
        if (!$decryptedpass) 
        {
            $this->MakeAnswer(false, "Ошибка мастер-ключа");
        }

        //Шифруем пароль ключом пользовательской сессии
        $cryptedforuser=$this->HashByUserSessionKey($usersessionkey, $decryptedpass);
        $decryptedpass=null;
        $result['requiredpassword']=$cryptedforuser;
        $this->MakeAnswer(true, "Password for user",$result);
    }
    
    public function DecryptByUserSession()
    {
        $cryptedtext=null;
        if (!CheckInput("cryptedtext", $cryptedtext, true, false))
        {
            $this->MakeAnswer(false, "Crypted text is missing");
        }
        //$cryptedtext=htmlspecialchars_decode($cryptedtext);
        
        
        $usersessionid=null;
        if (!CheckInput("sessid", $usersessionid,true,false))
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
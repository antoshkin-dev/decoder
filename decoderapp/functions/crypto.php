<?php
class AVCryptor
{
    private $passphrase=null;
    public $lasterror=null;
    public $debug=null;
    function __construct(string $passphrase) 
    {
        $this->passphrase=$passphrase;
    }
    public function MakeCrypted($cryptthis,$iv=null)
    {
        $this->AddDebug("Text for crypt: {$cryptthis}");
        $cipher="AES-128-CBC";
        $ivlen = openssl_cipher_iv_length($cipher);
        if (is_null($iv)) { $iv = openssl_random_pseudo_bytes($ivlen); }
        
        $this->AddDebug("iv B64: " . base64_encode($iv));
        $cryptedtext = openssl_encrypt($cryptthis, $cipher, $this->passphrase, $options=OPENSSL_RAW_DATA, $iv);
        $this->AddDebug("payload: ". base64_encode($cryptedtext));
        //$this->AddDebug("passphrase: " . base64_encode($this->passphrase));
        $checksum = hash_hmac('sha256', $cryptedtext, $this->passphrase, $as_binary=true);
        $this->AddDebug("CheckSum B64: " . base64_encode($checksum));
        
        //echo "checksum: ***" . base64_encode($checksum) . "***";
        return base64_encode($iv.$checksum.$cryptedtext);
    }
    public function GetTextBack($encodedstring)
    {
        $rowcryptedtext = base64_decode($encodedstring);
        $cipher="AES-128-CBC";
        $sha2len=32;
        
        $ivlen = openssl_cipher_iv_length($cipher);
        $this->AddDebug("iv len: {$ivlen}");
        
        $iv = substr($rowcryptedtext, 0, $ivlen);
        $this->AddDebug("iv b64:" . base64_encode($iv));
        
        
        $cryptedmsg = substr($rowcryptedtext, $ivlen+$sha2len);
        $this->AddDebug("cryptedmsg b64:" . base64_encode($cryptedmsg));
        
        $msgchecksum = substr($rowcryptedtext, $ivlen, $sha2len);
        $this->AddDebug("checksum b64: " . base64_encode($msgchecksum) . " len: " . strlen($msgchecksum));
                
        $calculatedchecksum = hash_hmac('sha256', $cryptedmsg, $this->passphrase, $as_binary=true);
        $this->AddDebug("calculated checksum b64: " . base64_encode($calculatedchecksum) . " len: " . strlen($calculatedchecksum));
        
        if (hash_equals($msgchecksum, $calculatedchecksum))// timing attack safe comparison
        {
            $this->AddDebug("Checksums ok");
            $original_plaintext = openssl_decrypt($cryptedmsg, $cipher, $this->passphrase, $options=OPENSSL_RAW_DATA, $iv);
            return $original_plaintext;
        }
        else
        {
            
            return false;
        }
    }
    private function AddDebug($text)
    {
        $this->debug.=$text . "\r\n";
    }
}
?>
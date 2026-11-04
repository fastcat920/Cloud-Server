<?php
namespace App\Services;
use App\Models\CloudAccount;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;
class CloudObjectService
{
    private $client;
    public function __construct(?Client $client=null) { $this->client=$client ?: new Client(['timeout'=>60,'connect_timeout'=>8,'http_errors'=>false,'allow_redirects'=>false]); }
    private function encoded($value) { return implode('/',array_map('rawurlencode',explode('/',$value))); }
    public function request(CloudAccount $account,$method,$region,$bucket='',$key='',array $query=[],$body='') {
        if (!$account->enabled) abort(422,'云账号已停用');
        if (!preg_match('/^[a-z0-9-]{2,50}$/',$region) || ($bucket!=='' && !preg_match('/^[a-z0-9][a-z0-9.-]{1,250}$/',$bucket))) abort(422,'地域或 Bucket 格式错误');
        $access=Crypt::decryptString($account->access_key_id_encrypted);$secret=Crypt::decryptString($account->access_key_secret_encrypted);
        $token=$account->security_token_encrypted?Crypt::decryptString($account->security_token_encrypted):'';
        $path='/'.$this->encoded($key);ksort($query);$queryString=http_build_query($query,'','&',PHP_QUERY_RFC3986);
        if ($account->provider==='aliyun') $host=($bucket?$bucket.'.':'').'oss-'.$region.'.aliyuncs.com';
        elseif ($account->provider==='tencent') $host=$bucket?$bucket.'.cos.'.$region.'.myqcloud.com':'service.cos.myqcloud.com';
        else { $host='s3-'.$region.'.ufileos.com';$path='/'.($bucket?$bucket.'/':'').$this->encoded($key); }
        $headers=['Host'=>$host];
        if ($account->provider==='aliyun') {
            // OSS V4 signs official regional endpoints; no endpoint supplied by the browser.
            $timestamp=gmdate('Ymd\THis\Z');$date=substr($timestamp,0,8);
            $headers['x-oss-date']=$timestamp;$headers['x-oss-content-sha256']='UNSIGNED-PAYLOAD';if ($token) $headers['x-oss-security-token']=$token;
            $canonical='';$signed=$headers;unset($signed['Host']);ksort($signed);foreach ($signed as $name=>$value) $canonical.=$name.':'.$value."\n";
            $resource='/'.($bucket?$bucket.'/':'').$this->encoded($key);
            $ossQuery=implode('&',array_map(fn($name)=>rawurlencode($name).((string)$query[$name]!==''?'='.rawurlencode((string)$query[$name]):''),array_keys($query)));
            $canonicalRequest=$method."\n".$resource."\n".$ossQuery."\n".$canonical."\n\nUNSIGNED-PAYLOAD";
            $scope=$date.'/'.$region.'/oss/aliyun_v4_request';
            $string="OSS4-HMAC-SHA256\n".$timestamp."\n".$scope."\n".hash('sha256',$canonicalRequest);
            $signing=hash_hmac('sha256',$date,'aliyun_v4'.$secret,true);foreach ([$region,'oss','aliyun_v4_request'] as $part) $signing=hash_hmac('sha256',$part,$signing,true);
            $headers['Authorization']='OSS4-HMAC-SHA256 Credential='.$access.'/'.$scope.',Signature='.hash_hmac('sha256',$string,$signing);
        } elseif ($account->provider==='tencent') {
            $period=(time()-60).';'.(time()+600);$signKey=hash_hmac('sha1',$period,$secret);
            $signed=['host'=>$host];if ($token) $headers['x-cos-security-token']=$token;
            $canonicalQuery=[];foreach ($query as $name=>$value) $canonicalQuery[strtolower($name)]=$value;ksort($canonicalQuery);
            $canonical=strtolower($method)."\n".rawurldecode($path)."\n".http_build_query($canonicalQuery,'','&',PHP_QUERY_RFC3986)."\nhost=".rawurlencode($host)."\n";
            $signature=hash_hmac('sha1',"sha1\n".$period."\n".sha1($canonical)."\n",$signKey);
            $headers['Authorization']='q-sign-algorithm=sha1&q-ak='.$access.'&q-sign-time='.$period.'&q-key-time='.$period.'&q-header-list=host&q-url-param-list='.implode(';',array_keys($canonicalQuery)).'&q-signature='.$signature;
        } else {
            $timestamp=gmdate('Ymd\THis\Z');$date=substr($timestamp,0,8);$hash=hash('sha256',$body);
            $headers['x-amz-date']=$timestamp;$headers['x-amz-content-sha256']=$hash;if ($token) $headers['x-amz-security-token']=$token;
            $signed=[];foreach ($headers as $name=>$value) $signed[strtolower($name)]=$value;ksort($signed);$canonical='';foreach ($signed as $name=>$value) $canonical.=$name.':'.$value."\n";
            $names=implode(';',array_keys($signed));$scope=$date.'/us-east-1/s3/aws4_request';
            $request=$method."\n".$path."\n".$queryString."\n".$canonical."\n".$names."\n".$hash;
            $signing=hash_hmac('sha256',$date,'AWS4'.$secret,true);foreach (['us-east-1','s3','aws4_request'] as $part) $signing=hash_hmac('sha256',$part,$signing,true);
            $headers['Authorization']='AWS4-HMAC-SHA256 Credential='.$access.'/'.$scope.', SignedHeaders='.$names.', Signature='.hash_hmac('sha256',"AWS4-HMAC-SHA256\n".$timestamp."\n".$scope."\n".hash('sha256',$request),$signing);
        }
        try { $response=$this->client->request($method,'https://'.$host.$path.($queryString?'?'.$queryString:''),['headers'=>$headers,'body'=>$body,'stream'=>$method==='GET' && $key!=='']); }
        catch (\Throwable $e) { throw new \RuntimeException('无法连接对象存储，请检查网络和地域'); }
        if ($response->getStatusCode()<200 || $response->getStatusCode()>=300) {
            $xml=function_exists('simplexml_load_string')?@simplexml_load_string(substr((string)$response->getBody(),0,10000),'SimpleXMLElement',LIBXML_NONET):false;
            throw new \RuntimeException('对象存储请求失败（'.$response->getStatusCode().'）：'.($xml?(string)$xml->Code.' '.(string)$xml->Message:'请检查权限及地域'));
        }
        return $response;
    }
    private function xml($response) {
        if (!function_exists('simplexml_load_string')) throw new \RuntimeException('PHP 缺少 SimpleXML 扩展，请在宝塔 PHP 设置中启用');
        $xml=@simplexml_load_string((string)$response->getBody(),'SimpleXMLElement',LIBXML_NONET);
        if (!$xml) throw new \RuntimeException('存储服务返回格式错误');
        return $xml;
    }
    public function buckets(CloudAccount $account,$region,$marker='') {
        $query=$account->provider==='aliyun'?['max-keys'=>100,'marker'=>$marker]:[];
        $xml=$this->xml($this->request($account,'GET',$region,'','',$query));$rows=[];
        foreach ($xml->Buckets->Bucket??[] as $bucket) { $location=(string)($bucket->Location??$bucket->Region??'');$location=preg_replace('/^oss-/','',$location);$rows[]=['name'=>(string)$bucket->Name,'region'=>$location?:$region,'created_at'=>(string)$bucket->CreationDate]; }
        return ['items'=>$rows,'next_marker'=>(string)($xml->NextMarker??'')];
    }
    public function objects(CloudAccount $account,$region,$bucket,$prefix,$marker) {
        $xml=$this->xml($this->request($account,'GET',$region,$bucket,'',['prefix'=>$prefix,'delimiter'=>'/','max-keys'=>100,'marker'=>$marker]));$rows=[];
        foreach ($xml->CommonPrefixes??[] as $item) $rows[]=['key'=>(string)$item->Prefix,'directory'=>true,'size'=>0,'modified_at'=>''];
        foreach ($xml->Contents??[] as $item) $rows[]=['key'=>(string)$item->Key,'directory'=>false,'size'=>(int)$item->Size,'modified_at'=>(string)$item->LastModified];
        $next=(string)($xml->NextMarker??'');if (!$next && (string)$xml->IsTruncated==='true' && $rows) $next=end($rows)['key'];
        return ['items'=>$rows,'next_marker'=>$next];
    }
}

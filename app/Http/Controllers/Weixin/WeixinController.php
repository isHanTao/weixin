<?php

namespace App\Http\Controllers\Weixin;

use App\Http\Controllers\Controller;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WeixinController extends Controller
{
    public function getLoginQrcode(){
        $token = Cache::remember('weixin_token',60*60*2,function (){
            return $this->getAccessToken();
        });
//        $token = "30_sqtPUAjBztvyvFWnuasMJrqmALwUtvWHo8EX_CUrSYCZBBP0p1W-vFmGrxk_JG9sGGufmK2mkVYLkWe4WZTcqcEN6VrdAj38ZUtYRJ2WYtJYh95lor8-htQLFjsERCeAAAOBM";
//        dd($token);

        $data['expire_seconds'] = 60*60*24;
        $data['action_name'] = 'QR_SCENE';

        $qrcode_url = 'https://api.weixin.qq.com/cgi-bin/qrcode/create?access_token=' . $token;
        $str = Str::random(16);
        log_info('message---------------------------'. $str);

        $guzzle = new Client();
        $res = $guzzle->post($qrcode_url,['json' =>[
            'expire_seconds'=>60*5,
            'action_name'=>'QR_STR_SCENE',
            "action_info"=>["scene"=>["scene_str" => $str]]
        ]])->getBody();
        $qrcode = json_decode($res, true);
        if (!key_exists('errcode',$qrcode)) {
            $ticket = $qrcode['ticket'];
            $ticket_img = 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=' . urlencode($ticket);
            return json_success('获取成功',$ticket_img);
        } else {
            return json_fail('发生错误：错误代码 ' . $qrcode['errcode'] . '，微信返回错误信息：' . $qrcode['errmsg']);
        }
    }
    public function receive(Request $request)
    {
        try{
            $postStr = file_get_contents('php://input');;
            if (!empty($postStr)){
                libxml_disable_entity_loader(true);
                $postObj = simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
                $fromUsername = $postObj->FromUserName;
                $toUsername = $postObj->ToUserName;
                log_info(json_encode($postObj,JSON_UNESCAPED_UNICODE));
                $keyword = trim($postObj->Content);
                $time = time();
                $textTpl = "<xml>
                           <ToUserName><![CDATA[%s]]></ToUserName>
                           <FromUserName><![CDATA[%s]]></FromUserName>
                           <CreateTime>%s</CreateTime>
                           <MsgType><![CDATA[%s]]></MsgType>
                           <Content><![CDATA[%s]]></Content>
                           <FuncFlag>0</FuncFlag>
                           </xml>";
                $userInfo = $this->getWeixinToken($request->openid);
                log_info(json_encode($userInfo,JSON_UNESCAPED_UNICODE));
                //订阅事件
                if($postObj->Event=="subscribe")
                {
                    $this->setUser($userInfo);
                    $msgType = "text";
                    $contentStr = "欢迎关注账号系统";
                    $resultStr = sprintf($textTpl, $fromUsername, $toUsername, $time, $msgType, $contentStr);
                    return $resultStr;
                }

                if($postObj->Event=="SCAN")
                {
                    $this->setUser($userInfo);
                    $msgType = "text";
                    $contentStr = "欢迎回来";
                    $resultStr = sprintf($textTpl, $fromUsername, $toUsername, $time, $msgType, $contentStr);
                    return $resultStr;
                }

                //语音识别
                if($postObj->MsgType=="voice"){
                    $msgType = "text";
                    $contentStr = trim($postObj->Recognition,"。");
                    $resultStr = sprintf($textTpl, $fromUsername, $toUsername, $time, $msgType, $contentStr);
                    return  $resultStr;
                }
                //自动回复
                if(!empty( $keyword ))
                {
                    $msgType = "text";
                    $contentStr = "你好！";
                    $resultStr = sprintf($textTpl, $fromUsername, $toUsername, $time, $msgType, $contentStr);
                    return $resultStr;
                }else{
                    return "Input something...";
                }
            }else {
                return "null";
            }
        }catch (\Exception $e){
            log_exception($e);
        }

    }
    //检查签名
    private function checkSignature($request)
    {
        $signature = $request["signature"];
        $timestamp = $request["timestamp"];
        $nonce = $request["nonce"];
        $token = "123456";//可以随便填，只要和后台添加的一样就行
        $tmpArr = array($token, $timestamp, $nonce);
        sort($tmpArr, SORT_STRING);
        $tmpStr = implode($tmpArr);
        $tmpStr = sha1($tmpStr);
        if($tmpStr == $signature){
            return true;

        }else{
            return false;
        }
    }

    protected function getAccessToken(){
        $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=' . env('WECHAT_KEY') . '&secret=' . env('WECHAT_SECRET');
        $guzzle = new Client();
        log_info('URL:'.$url);
        $res = $guzzle->get($url)->getBody();
        $token = json_decode($res, true);
        if (key_exists('access_token',$token)){
            return $token['access_token'];
        }else{
            return false;
        }
    }

    private function setUser($userInfo){
        $userAuth =  UserAuthRepository::getWhere(['identifier'=>$userInfo['openid']]);
        if ($userAuth->isEmpty()){
            try{
                DB::beginTransaction();
                $user['nickname'] = $userInfo['nickname'];
                $user['description'] = '';
                $user['avatar'] = $userInfo['headimgurl'];
                $user['register_ip'] = \request()->getClientIp();
                $user['openid'] = Str::random(50);
                $user = User::forceCreate($user);

                $newUserAuth['identifier']= $userInfo['openid'];
                $newUserAuth['identity_type']= 'weixin';
                $newUserAuth['credential']= password_hash($userInfo['openid'], PASSWORD_DEFAULT);
                $newUserAuth['user_id']= $user->id;
                UserAuth::forceCreate($newUserAuth);
                DB::commit();
                log_info('第一次');
                Cache::remember('loginuser',60*60*5,function () use ($userInfo){
                    return [
                        'token'=>$this->getToken($userInfo['openid'])
                    ];
                });
            }catch (\Exception $e){
                DB::rollBack();
                log_info($e->getMessage());
            }
        }else{
            $userAuth = $userAuth[0];
            log_info('登录成功');
            Cache::remember('loginuser',60*60*5,function () use ($userAuth){
                return [
                    'token'=>$this->getToken($userAuth->identifier)
                ];
            });
        }

    }

    private function getWeixinToken($openid){
//        $token = Cache::remember('weixin_token',60*60*2,function (){
//            return $this->getAccessToken();
//        });
        $token =  $this->getAccessToken();
        $url = "https://api.weixin.qq.com/cgi-bin/user/info?access_token=".$token.'&openid='.$openid;
        $guzzle = new Client();
        $res = $guzzle->get($url)->getBody();
        $user = json_decode($res, true);
        return $user;
    }

    public function isScan(){
        $user = Cache::get('loginuser');
        if ($user){
            Cache::forget('loginuser');
            return json_success('扫描成功',$user);
        }else{
            return json_fail('未扫描');
        }
    }

    private function getToken($identifier){
        $guzzle = new Client();
        try{
            $response = $guzzle->post(env('APP_URL').'/api/oauth/token', [
                'form_params' => [
                    'grant_type' => 'password',
                    'username' => $identifier,
                    'password' => $identifier,
                    'client_id' =>1,
                    'client_secret' =>'pFWBcFgR37VxrY408RSnGnCEzf2gLAu6ToysMUNU',
                    'scope' => '*',
                ],
            ]);
            return json_decode((string) $response->getBody(), true);
        }catch (\Exception $e){
            log_error($e->getMessage());
            return false;
        }
    }

    public function login(){

    }

    private function getClient($name){
        $client = OauthClientRepository::getWhere(['name'=>$name]);
        if (!$client->isEmpty()){
            log_info('获取客户端成功');
            return $client[0];

        }else{
            log_info('获取客户端失败');
            return false;
        }
    }
}

<?php
/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
//declare(ticks=1);
use \GatewayWorker\Lib\Gateway;
use Workerman\Lib\Timer;

class Events{
    const API_URL = 'http://kf.lyfz.net';

    // 返回消息码处理
    private static function msg($code, $message, $body = ''){
        return json_encode([
            'meta' => [
                'code' => $code,
                'message' => $message,
            ],
            'body' => empty($body) == true ? '' : $body
        ]);
    }

    // 校验token是否正确
    private static function checkToken($uid, $token){
        $client = new \GuzzleHttp\Client();

        $request_data = [
            'uid' => $uid,
            'token' => $token
        ];

        $response = $client->request(
            'PUT', 
            self::API_URL.'/api/v1/user/Auth/checkToken', 
            [
                'json' => $request_data,
                'timeout' => 3
            ]
        );

        return json_decode($response->getBody(),true);
    }

    /**
     * 当客户端连接时触发
     * 如果业务不需此回调可以删除onConnect
     * 
     * @param int $client_id 连接id
     */
    public static function onConnect($client_id){
        // 连接到来后，定时10秒关闭这个链接，需要10秒内发认证并删除定时器阻止关闭连接的执行
        $_SESSION['auth_timer_id'] = Timer::add(10, function($client_id){
            Gateway::closeClient($client_id);
        }, array($client_id), false);

        Gateway::sendToClient($client_id, self::msg(200,'success'));
    }
    
    /**
     * 当客户端发来消息时触发
     * @param int $client_id 连接id
     * @param mixed $message 具体消息
     */
    public static function onMessage($client_id, $message){
        echo "$client_id sid $message\r\n";

        $message = json_decode($message, true);

        $check_res = self::checkToken($message['uid'], $message['token']);

        if($check_res['meta']['code'] != 200){
            Gateway::sendToClient($client_id, self::msg(6001,'token error'));
            Gateway::closeClient($client_id);
            return;
        }else{
            Timer::del($_SESSION['auth_timer_id']);
            Gateway::bindUid($client_id, $message['uid']);
            Gateway::sendToClient($client_id, self::msg(200,'success',['client_id'=>$client_id]));
            return;
        }
    }
   
    /**
     * 当用户断开连接时触发
     * @param int $client_id 连接id
     */
    public static function onClose($client_id){
       echo "$client_id logout\r\n";
    }

    // 启动进程计时器轮询发送相应redis数据至im客户端
    public static function onWorkerStart(){
        Timer::add(2, function(){
            //echo "test timer\n";
            Gateway::sendToUid('6454', 123);
            
        });
    }
}

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

    const REDIS_HOST = '118.178.141.154';

    const REDIS_PORT = 6379;

    const REDIS_PASSWORD = '556ca120';

    const IMG_TRANSFER_URL = 'http://kf.lyfz.net/api/v1/we_chat/Business/getWxUrlImg?url=';

    const IMG_URL = 'http://kf.lyfz.net/api/v1/we_chat/Business/getImg?resources_id=';

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

    //emoji表情反转义
    private static function emojiDeCode($str){
        $strDecode = preg_replace_callback('|\[\[EMOJI:(.*?)\]\]|', function ($matches) {
            return rawurldecode($matches[1]);
        }, $str);

        return $strDecode;
    }

    // 校验token是否正确
    private static function checkToken($uid, $token, $client_type){
        $client = new \GuzzleHttp\Client();

        $request_data = [
            'uid' => $uid,
            'token' => $token,
            'client' => $client_type
        ];

        $response = $client->request(
            'PUT',
            self::API_URL . '/api/v1/user/Auth/checkToken',
            [
                'json' => $request_data,
                'timeout' => 3
            ]
        );

        return json_decode($response->getBody(), true);
    }

    //创建redis连接
    public static function createRedis(){
        $redis = new \Predis\Client([
            'host' => self::REDIS_HOST,
            'port' => self::REDIS_PORT,
            'password' => self::REDIS_PASSWORD,
        ]);

        return $redis;
    }

    /**
     * 当客户端连接时触发
     * 如果业务不需此回调可以删除onConnect
     * @param int $client_id 连接id
     */
    public static function onConnect($client_id){
        echo "$client_id------connect\r\n";

        // 连接到来后，定时10秒关闭这个链接，需要10秒内发认证并删除定时器阻止关闭连接的执行
        $_SESSION['auth_timer_id'] = Timer::add(10, function ($client_id) {
            Gateway::sendToClient($client_id, self::msg(6002, 'authentication timeout'));
            Gateway::closeClient($client_id);
        }, array($client_id), false);

        Gateway::sendToClient($client_id, self::msg(200, 'success'));
    }

    /**
     * 当客户端发来消息时触发
     * @param int $client_id 连接id
     * @param mixed $message 具体消息
     */
    public static function onMessage($client_id, $message){
        echo "$client_id sid $message\r\n";
        $message = json_decode($message, true);

        switch ($message['type']) {
            case 'auth':
                $check_res = self::checkToken($message['uid'], $message['token'], $message['client']);

                if ($check_res['meta']['code'] != 200) {
                    Gateway::sendToClient($client_id, self::msg(6001, 'token error'));
                    Gateway::closeClient($client_id);
                } else {
                    $_SESSION['company_id'] = $message['company_id'];
                    $_SESSION['uid'] = $message['uid'];
                    $_SESSION['token'] = $message['token'];
                    $_SESSION['client'] = $message['client'];

                    Timer::del($_SESSION['auth_timer_id']);

                    Gateway::joinGroup($client_id, $message['company_id']);

                    Gateway::bindUid($client_id, $message['uid']);
                    Gateway::sendToClient($client_id, self::msg(200, 'success', ['client_id' => $client_id]));
                }
                break;
            case 'ping':
                break;
            case 'get_lineup_session':
                self::getConversationSessionList($message['uid']);
                break;
            default:
                Gateway::sendToClient($client_id, self::msg(6003, 'type error'));
                Gateway::closeClient($client_id);
                break;
        }
    }

    /**
     * 当用户断开连接时触发
     * @param int $client_id 连接id
     */
    public static function onClose($client_id){
        echo "$client_id------logout\r\n";

        Gateway::closeClient($client_id);
    }

    // 获取待接入会话列表
    public static function getSessionList(){
        $redis = self::createRedis();
        $redis->select(0);
        $uid_list = $redis->keys('*');

        foreach ($uid_list as $uid) {
            $session_list = $redis->sMembers($uid);

            if (Gateway::getClientIdByUid($uid)) {
                $redis->del($uid);

                foreach ($session_list as $key => $val) {
                    $waiting_arr[$key] = json_decode($val, true);
                }

                $arr = [
                    'type' => 'session',
                    'sk_data' => [
                        'waiting' => empty($waiting_arr) == true ? [] : $waiting_arr,
                        'queue_up' => [],
                    ]
                ];

                Gateway::sendToUid($uid, self::msg(200, 'success', $arr));
            }
        }
    }

    // 获取排队中会话列表
    public static function getConversationSessionList($uid){
        //获取用户的company_id
        $company_id = $_SESSION['company_id'];

        $redis = self::createRedis();
        $redis->select(2);
        $session_list = $redis->sMembers($company_id);

        if (Gateway::getClientIdByUid($uid)) {
            foreach ($session_list as $key => $val) {
                $queue_up_arr[$key] = json_decode($val, true);
            }

            $arr = [
                'type' => 'session',
                'sk_data' => [
                    'waiting' => [],
                    'queue_up' => empty($queue_up_arr) == true ? [] : $queue_up_arr,
                ]
            ];

            Gateway::sendToUid($uid, self::msg(200, 'success', $arr));
        }
    }

    // 获取会话消息
    public static function getMessageList(){
        $redis = self::createRedis();
        $redis->select(1);
        $uid_list = $redis->keys('*');

        foreach ($uid_list as $uid) {
            $message_list = $redis->zRange($uid, 0, -1);

            if (Gateway::getClientIdByUid($uid)) {
                $redis->del($uid);

                foreach ($message_list as $key => $val) {
                    $val = json_decode($val, true);

                    if (!empty($val['text'])) {
                        $val['text'] = self::emojiDeCode($val['text']);
                    } else {
                        $val['text'] = '';
                    }

                    if ($val['opercode'] == 2 && $val['message_type'] == 2) {
                        $val['file_url'] = self::IMG_TRANSFER_URL . $val['file_url'];
                    }

                    if ($val['opercode'] == 3 && $val['message_type'] == 2) {
                        $val['file_url'] = self::IMG_URL . $val['resources_id'];
                    }

                    $message_arr[$val['customer_wx_openid']][] = $val;
                    $message_arrs[] = $val;
                }

                $arr = [
                    'type' => 'message',
                    'sk_data' => $message_arr
                ];

                $arrs = [
                    'type' => 'messages',
                    'sk_data' => $message_arrs
                ];

                Gateway::sendToUid($uid, self::msg(200, 'success', $arr));
                Gateway::sendToUid($uid, self::msg(200, 'success', $arrs));
            }
        }
    }

    // 启动进程计时器轮询发送相应redis数据至im客户端
    public static function onWorkerStart(){
        // 获取消息数据
        Timer::add(2, function () {
            self::getMessageList();
        });

        // 获取普通会话数据
        Timer::add(3, function () {
            self::getSessionList();
        });

        // 获取群聊数据
        Timer::add(3, function () {
            self::getGroupChatList();
        });
    }

    // 获得群聊消息
    public static function getGroupChatList(){
        $redis = self::createRedis();
        $redis->select(5);
        $uid_list = $redis->keys('*');

        foreach ($uid_list as $uid) {
            $session_list = $redis->sMembers($uid);

            if (Gateway::getClientIdByUid($uid)) {
                $redis->del($uid);

                foreach ($session_list as $key => $val) {
                    $waiting_arr[$key] = json_decode($val, true);
                }

                $arr = [
                    'type' => 'group',
                    'sk_data' => $waiting_arr ? $waiting_arr : []
                ];

                Gateway::sendToUid($uid, self::msg(200, 'success', $arr));
            }
        }
    }
}

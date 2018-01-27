<?php
/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */

//declare(ticks=1);
use \GatewayWorker\Lib\Gateway;
use Workerman\Lib\Timer;

class Events
{
    const API_URL = 'http://kf.lyfz.net';

    const REDIS_HOST = '118.178.141.154';

    const REDIS_PORT = 6379;

    const REDIS_PASSWORD = '556ca120';

    const IMG_TRANSFER_URL = 'http://kf.lyfz.net/api/v1/we_chat/Business/getWxUrlImg?url=';

    const IMG_URL = 'http://kf.lyfz.net/api/v1/we_chat/Business/getImg?resources_id=';

    // 返回消息码处理
    private static function msg($code, $message, $body = '')
    {
        return json_encode([
            'meta' => [
                'code' => $code,
                'message' => $message,
            ],
            'body' => empty($body) == true ? '' : $body
        ]);
    }

    //emoji表情反转义
    private static function emojiDeCode($str)
    {
        $strDecode = preg_replace_callback('|\[\[EMOJI:(.*?)\]\]|', function ($matches) {
            return rawurldecode($matches[1]);
        }, $str);

        return $strDecode;
    }

    // 校验token是否正确
    private static function checkToken($uid, $token, $client_type)
    {
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

    // 获取uid的商户company_id
    private static function getUidCompanyId($uid)
    {
        $client = new \GuzzleHttp\Client();

        $request_data = [
            'uid' => $uid
        ];

        $response = $client->request(
            'PUT',
            self::API_URL . '/api/v1/user/Auth/getUidCompanyId',
            [
                'json' => $request_data,
                'timeout' => 3
            ]
        );

        return json_decode($response->getBody(), true)['body']['company_id'];
    }

    // 标记账号在线或不在线
    private static function setUserOnlineState($uid, $state)
    {
        $client = new \GuzzleHttp\Client();

        $request_data = [
            'uid' => $uid,
            'state' => $state
        ];

        $response = $client->request(
            'PUT',
            self::API_URL . '/api/v1/user/Auth/setUserOnlineState',
            [
                'json' => $request_data,
                'timeout' => 3
            ]
        );

        return json_decode($response->getBody(), true);
    }

    //创建redis连接
    public static function createRedis()
    {
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
    public static function onConnect($client_id)
    {
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
    public static function onMessage($client_id, $message)
    {
        /*if (is_array($message)) {
            $message = $message['post'];
            var_dump($message);
        } else {
            echo "$client_id sid $message\r\n";
            $message = json_decode($message, true);
        }*/
        echo "$client_id sid $message\r\n";
        $message = json_decode($message, true);

        switch ($message['type']) {
            case 'auth':
                $check_res = self::checkToken($message['uid'], $message['token'], $message['client']);

                if ($check_res['meta']['code'] != 200) {
                    Gateway::sendToClient($client_id, self::msg(6001, 'token error'));
                    Gateway::closeClient($client_id);
                } else {
                    if (!empty($message['company_id'])) {
                        $_SESSION['company_id'] = $message['company_id'];
                    }

                    $_SESSION['uid'] = $message['uid'];
                    $_SESSION['token'] = $message['token'];
                    $_SESSION['client'] = $message['client'];

                    Timer::del($_SESSION['auth_timer_id']);
                    Gateway::bindUid($client_id, $message['uid']);
                    Gateway::sendToClient($client_id, self::msg(200, 'success', ['client_id' => $client_id]));

                    self::setUserOnlineState($message['uid'], 1);
                }
                break;
            case 'ping':
                break;
            case 'get_lineup_session':
                self::getConversationSessionList($message['uid']);
                break;
//            case 'create_group':
//                $join = empty($message['join']) ? [] : $message['join'];
//                $leave = empty($message['leave']) ? [] : $message['leave'];
//                $group_id = $message['group_id'];
//                if (count($join) > 0) {
//                    foreach ($join as $uid) {
//                        $client_ids = Gateway::getClientIdByUid($uid);
//                        if (count($client_ids)) {
//                            foreach ($client_ids as $client_id) {
//                                echo '==================================================='."\r\n";
//                                Gateway::joinGroup($client_id, $group_id);
//                                echo $uid . "加入了群组$group_id\r\n";
//                                echo "client_id:" . $client_id . "加入了群组\r\n";
//                                echo '==================================================='."\r\n";
//                            }
//                        }
//                    }
//                }
//
//                if (count($leave) > 0) {
//                    foreach ($join as $uid) {
//                        $client_ids = Gateway::getClientIdByUid($uid);
//                        if (count($client_ids)) {
//                            foreach ($client_ids as $client_id) {
//                                echo $uid . "移除了群组$group_id\r\n";
//                                echo "client_id:" . $client_id . "\r\n";
//                                Gateway::leaveGroup($client_id, $group_id);
//                            }
//                        }
//                    }
//                }
//
//                break;
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
    public static function onClose($client_id)
    {
        echo "$client_id------logout\r\n";

        Gateway::closeClient($client_id);

        if (!empty($_SESSION['uid'])) {
            self::setUserOnlineState($_SESSION['uid'], -1);
        }
    }

    // 获取待接入会话列表
    public static function getSessionList()
    {
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
    public static function getConversationSessionList($uid)
    {
        //获取用户的company_id
        if (!empty($_SESSION['company_id'])) {
            $company_id = $_SESSION['company_id'];
        } else {
            $company_id = self::getUidCompanyId($uid);
        }

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
    public static function getMessageList()
    {
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

                    /*if ($val['opercode'] == 4) {
                        $session_id = $val['session_id'];
                        $group_message[$val['customer_wx_openid']][] = $val;
                        $group_id = self::getGroupId($session_id);
                        if ($group_id) {
                            $arr = [
                                'type' => 'message',
                                'sk_data' => $group_message
                            ];

                            $arr = [
                                'type' => 'session',
                                'sk_data' => [
                                    'queue_up' => [],
                                ]
                            ];

                            Gateway::sendToUid($uid, self::msg(200, 'success', $arr));

                            Gateway::sendToGroup($group_id, self::msg(200, 'success',$arr ));
                            echo '----------------------------------------------------------'."\r\n";
                            var_dump(Gateway::getClientSessionsByGroup($group_id));
                            echo '----------------------------------------------------------'."\r\n";
                            echo "给group_id是：{$group_id}的群组发送群聊消息\r\n";
                        }
                    }*/

                    $message_arr[$val['customer_wx_openid']][] = $val;
                }
                $arr = [
                    'type' => 'message',
                    'sk_data' => $message_arr
                ];
                Gateway::sendToUid($uid, self::msg(200, 'success', $arr));
            }
        }
    }

    // 启动进程计时器轮询发送相应redis数据至im客户端
    public static function onWorkerStart()
    {
        Timer::add(2, function () {
            self::getMessageList();
        });

        Timer::add(3, function () {
            self::getSessionList();
        });

        //设置群聊消息
        Timer::add(3, function () {
            self::getGroupChatList();
        });
    }

    //获得群聊组员信息
    public static function getGroupChatUser($session_id)
    {
        $client = new \GuzzleHttp\Client();

        $request_data = [
            'session_id' => $session_id,
        ];

        $response = $client->request(
            'PUT',
            self::API_URL . '/api/v1/we_chat/WxOperationLogic/getGroupChatMemberList',
            [
                'headers' => [
                    'token' => $_SESSION['token'],
                    'uid' => $_SESSION['uid'],
                    'client' => $_SESSION['client']
                ],
                'json' => $request_data,
                'timeout' => 3
            ]);
        return json_decode($response->getBody(), true);
    }

    //获得groupid
    public static function getGroupId($session_id)
    {
        $guzzle_client = new \GuzzleHttp\Client();

        $request_data = [
            'session_id' => $session_id,
        ];
        $token = $_SESSION['token'];
        $uid = $_SESSION['uid'];
        $client = $_SESSION['client'];

        $response = $guzzle_client->request(
            'PUT',
            self::API_URL . '/api/v1/message/Common/getGroupIdBySessionId',
            [
                'headers' => [
                    'token' => $token,
                    'uid' => $uid,
                    'client' => $client
                ],
                'json' => $request_data,
                'timeout' => 3
            ]);
        $res = json_decode($response->getBody(), true);
        if ($res['meta']['code'] == 200) {
            return $res['body']['group_id'];
        } else {
            return false;
        }
    }

    //获得群聊消息
    public static function getGroupChatList()
    {
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

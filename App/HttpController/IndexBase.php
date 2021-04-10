<?php


namespace App\HttpController;


use App\Log\LogHandel;
use App\Model\LogModel;
use EasySwoole\Http\AbstractInterface\Controller;
use EasySwoole\ORM\DbManager;
use EasySwoole\Redis\Exception\RedisException;
use EasySwoole\RedisPool\RedisPool;
use http\Env\Request;


# 所有接口的 父类
class IndexBase extends Controller
{


    protected $who;
    protected $white_router = array('/login');


    protected $jurisdiction_check = [
        'superAdmin' => 0,#超级管理员的权限
        'shopAdmin' => 1,
        'realizationAdmin' => 2,
        'receptionAdmin' => 3,
        'realizationUser' => 4,
        'receptionUser' => 5,
        'baiBoAdmin'=>6

    ];


    protected function onRequest(?string $action): ?bool
    {

        $router_url = $this->request()->getServerParams()['request_uri'];
        $mid = $this->request()->getQueryParam('mid');
        if (!$this->IfCheckToken($this->white_router, $router_url)) {
            return false;
        }


        #最高 權限 不需要檢驗權限



        if (isset($mid) && $mid == "superAdmin") {
        } else {
            if (!$this->PermissionToCheck($router_url, $this->jurisdiction_check)) {
                return false;
            }
        }


        return true; // TODO: Change the autogenerated stub
    }


    #是否检查token
    private function IfCheckToken(?array $white_router, $router)
    {
        $log = new LogHandel();
        #白名单 不需要检查token
        if (in_array($router, $white_router)) {
            return true;
        }


        #获取 token
        $Method_type = $this->request()->getServerParams()['request_method'];
        if ($Method_type == "GET") {
            $token = $this->request()->getQueryParam('token');
        } else {
            $token = $this->request()->getParsedBody('token');
        }


        #判断token 的长度合法性
        if (strlen($token) != 36) {
            $log->log('路由:' . $router . ' token ' . $token . '长度非法');
            $this->writeJson(-101, 'NO', '非法请求!');
            return false;
        }


        #判断token 是否存在 并且检查token的 权限 /client/login
        $redis = RedisPool::defer('redis');
//        if (strstr($router, '/client')) {
//            var_dump("客户端");
//            if (!$redis->hExists("USER_TOKEN", $token)) {
//                $log->log('路由:' . $router . ' 用户 token' . $token . '不存在');
//                $this->writeJson(-101, 'NO', '非法请求!');
//                return false;
//            }
//        } else {
//            if (strstr($router, '/administrator')) {
//                if (!$redis->hExists("ADMIN_TOKEN", $token)) {
//                    $log->log('路由:' . $router . '管理员 token ' . $token . '不存在');
//                    $this->writeJson(-101, 'NO', '非法请求!');
//                    return false;
//                }
//            } else {
//                $log->log('非法 路由' . $router);
//                $this->writeJson(-101, 'NO', '非法路由!');
//                return false;
//            }
//        }

        #token 是否过期
        if (!$redis->get($token)) {
            $this->writeJson(-101, 'OK', '登录已经失效了,请重新登录');
            return false;
        }


        #token 校验成功 获取 用户信息
        $this->who = $redis->hGetAll("USER_" . $redis->get($token));

        try {
            $redis->set($token, $this->who['user'], 1800);
        } catch (RedisException $e) {
            var_dump("检查token 设值异常");
            return false;
        }
        return true;
    }


    private function PermissionToCheck($router_url, $jurisdiction_check)
    {


        $url_while = array("/login", "/realizationAdmin/get_devices", "/realizationAdmin/get_devices_detail","/receptionAdmin/get_project",'/receptionAdmin/get_task_orders');


        if (in_array($router_url, $url_while)) {
            return true;
        }


        $router_url_array = explode('/', $router_url);
        #最新的地址
        $new_router = $router_url_array[1];


        if ($jurisdiction_check[$new_router] != $this->who['grade']) {
            $this->writeJson(-101, [], '权限不符');
            return false;
        }

        return true;

    }


    /**
     * @param $msg
     * @param $user
     * @param string $logLevel
     * 日志  写进数据库
     */
    protected function mysql_log($msg, $user, $logLevel = 'debug')
    {
        try {
            DbManager::getInstance()->invoke(function ($client) use ($msg, $user, $logLevel) {
                $insert_into = [
                    'user' => $user,
                    'msg' => $msg,
                    'logLevel' => $logLevel,
                    'created_at' => time()
                ];
                LogModel::invoke($client)->data($insert_into)->save();
            });

        } catch (\Throwable $e) {

            var_dump($e->getMessage());
        }
    }


    #用户生成一个 Token
    function Set_Token($length)
    {

        //字符组合
        $str = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $len = strlen($str) - 1;
        $token = '';
        $redis = RedisPool::defer('redis');
        for ($j = 0; $j < 5; $j++) {
            for ($i = 0; $i < $length; $i++) {
                $num = mt_rand(0, $len);
                $token .= $str[$num];
            }
            if (!$redis->hExists('USER_TOKEN', $token)) {
                break;
            } else {
                $token = "";
            }
        }
        return $token;
    }

    /**
     * @param $res
     * @param $msg
     * @return bool
     */
    function isOk($res, $msg)
    {
        if (!$res) {
            $this->writeJson(-101, [], $msg . "失败");
            return false;
        }
        $this->writeJson(200, [], $msg . "成功");
        return true;
    }

}
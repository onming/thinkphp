<?php

namespace app\common\controller;

use app\common\library\Auth;
use think\Config;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Hook;
use think\Lang;
use think\Loader;
use think\Request;
use think\Response;
use think\Db;

/**
 * Internal API控制器基类
 */
class Internal
{

    /**
     * @var Request Request 实例
     */
    protected $request;

    /**
     * @var bool 验证失败是否抛出异常
     */
    protected $failException = false;

    /**
     * @var bool 是否批量验证
     */
    protected $batchValidate = false;

    /**
     * @var array 前置操作方法列表
     */
    protected $beforeActionList = [];

    /**
     * 权限Auth
     * @var Auth 
     */
    protected $auth = null;

    /**
     * 默认响应输出类型,支持json/xml
     * @var string 
     */
    protected $responseType = 'json';

    /**
     * 验证场景
     * @var string
     */
    protected $scene = 'default';

    /**
     * 验证后的数据
     * @var array
     */
    protected $input = [];

    /**
     * auth游戏数据
     * @var array
     */
    protected $gameInfo = [];

    /**
     * 构造方法
     * @access public
     * @param Request $request Request 对象
     */
    public function __construct(Request $request = null)
    {
        $this->request = is_null($request) ? Request::instance() : $request;

        // 控制器初始化
        $this->_initialize();

        // 前置操作方法
        if ($this->beforeActionList)
        {
            foreach ($this->beforeActionList as $method => $options)
            {
                is_numeric($method) ?
                                $this->beforeAction($options) :
                                $this->beforeAction($method, $options);
            }
        }
    }

    /**
     * 初始化操作
     * @access protected
     */
    protected function _initialize()
    {
        //移除HTML标签
        $this->request->filter('strip_tags');

        $controllername = strtolower($this->request->controller());

        $upload = \app\common\model\Config::upload();

        // 上传信息配置后
        Hook::listen("upload_config_init", $upload);

        Config::set('upload', array_merge(Config::get('upload'), $upload));

        // 加载当前控制器语言包
        $this->loadlang($controllername);

        // 验证请求数据
        $this->validateInit();

        // 验证auth
        $this->authInit();
    }

    /**
     * 加载语言文件
     * @param string $name
     */
    protected function loadlang($name)
    {
        Lang::load(APP_PATH . $this->request->module() . '/lang/' . $this->request->langset() . '/' . str_replace('.', '/', $name) . '.php');
    }

    /**
     * 操作成功返回的数据
     * @param string $msg   提示信息
     * @param mixed $data   要返回的数据
     * @param int   $code   错误码，默认为1
     * @param string $type  输出类型
     * @param array $header 发送的 Header 信息
     */
    protected function success($msg = '', $data = null, $code = 1, $type = null, array $header = [])
    {
        $this->result($msg, $data, $code, $type, $header);
    }

    /**
     * 操作失败返回的数据
     * @param string $msg   提示信息
     * @param mixed $data   要返回的数据
     * @param int   $code   错误码，默认为0
     * @param string $type  输出类型
     * @param array $header 发送的 Header 信息
     */
    protected function error($msg = '', $data = null, $code = 0, $type = null, array $header = [])
    {
        $this->result($msg, $data, $code, $type, $header);
    }

    /**
     * 返回封装后的 API 数据到客户端
     * @access protected
     * @param mixed  $msg    提示信息
     * @param mixed  $data   要返回的数据
     * @param int    $code   错误码，默认为0
     * @param string $type   输出类型，支持json/xml/jsonp
     * @param array  $header 发送的 Header 信息
     * @return void
     * @throws HttpResponseException
     */
    protected function result($msg, $data = null, $code = 0, $type = null, array $header = [])
    {
        $data = $data?$data:[];
        $result = [
            'code' => $code,
            'msg'  => $msg,
            'time' => Request::instance()->server('REQUEST_TIME'),
            'data' => $data,
            'debug' => isset($data['debug'])?$data['debug']:'',
        ];
        unset($result['data']['debug']);

        // 保存日志
        $this->saveLog($result);

        // 线上环境debug调试信息不显示
        if(is_online_env()){
            unset($result['debug']);
        }

        // 如果未设置类型则自动判断
        $type = $type ? $type : ($this->request->param(config('var_jsonp_handler')) ? 'jsonp' : $this->responseType);

        if (isset($header['statuscode']))
        {
            $code = $header['statuscode'];
            unset($header['statuscode']);
        }
        else
        {
            //未设置状态码,根据code值判断
            $code = $code >= 1000 || $code < 200 ? 200 : $code;
        }
        $response = Response::create($result, $type, $code)->header($header);
        throw new HttpResponseException($response);
    }

    /**
     * 前置操作
     * @access protected
     * @param  string $method  前置操作方法名
     * @param  array  $options 调用参数 ['only'=>[...]] 或者 ['except'=>[...]]
     * @return void
     */
    protected function beforeAction($method, $options = [])
    {
        if (isset($options['only']))
        {
            if (is_string($options['only']))
            {
                $options['only'] = explode(',', $options['only']);
            }

            if (!in_array($this->request->action(), $options['only']))
            {
                return;
            }
        }
        elseif (isset($options['except']))
        {
            if (is_string($options['except']))
            {
                $options['except'] = explode(',', $options['except']);
            }

            if (in_array($this->request->action(), $options['except']))
            {
                return;
            }
        }

        call_user_func([$this, $method]);
    }

    /**
     * 设置验证失败后是否抛出异常
     * @access protected
     * @param bool $fail 是否抛出异常
     * @return $this
     */
    protected function validateFailException($fail = true)
    {
        $this->failException = $fail;

        return $this;
    }

    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @param  mixed        $callback 回调方法（闭包）
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate($data, $validate, $message = [], $batch = false, $callback = null)
    {
        if (is_array($validate))
        {
            $v = Loader::validate();
            $v->rule($validate);
        }
        else
        {
            // 支持场景
            if (strpos($validate, '.'))
            {
                list($validate, $scene) = explode('.', $validate);
            }

            $v = Loader::validate($validate);

            !empty($scene) && $v->scene($scene);
        }

        // 批量验证
        if ($batch || $this->batchValidate)
            $v->batch(true);
        // 设置错误信息
        if (is_array($message))
            $v->message($message);
        // 使用回调验证
        if ($callback && is_callable($callback))
        {
            call_user_func_array($callback, [$v, &$data]);
        }

        if (!$v->check($data))
        {
            if ($this->failException)
            {
                throw new ValidateException($v->getError());
            }

            return $v->getError();
        }

        return true;
    }

    /**
     * 监听SQL
     */
    function listenSql()
    {
        Db::listen(function ($sql, $time, $explain, $master) {
            $data = [
                'date' => date('Y-m-d H:i:s'),
                'sql' => $sql,
                'exec_time' => $time . 's ',
                'ms' => $master ? 'master' : 'slave',
                'explain' => $explain,
            ];
            Db::connect("mongo_db")->table("listen_sql")->insert($data);
        });
    }

    /**
     * action初始化
     */
    protected function actionInit($input)
    {
        $this->scene = 'old';
        $this->request->controller($input['module']);
        $this->request->action($input['action']);
        $this->$input['action']();
    }

    /**
     * 验证请求数据
     */
    protected function validateInit()
    {
        // 解析input数据
        $input = file_get_contents('php://input');
        if($input){
            $input = json_decode(urldecode($input), true);
        }else{
            $input = $this->request->param();
        }
        $validate = $this->validate($input, "internal.".$this->scene);
        $this->input = $input;
        if($validate !== true){
            return $this->error("请求参数缺省", ['debug' => $validate], -7);
        }
    }

    /**
     * auth验证
     */
    protected function authInit()
    {
        $gameInfo = Db::name('game')->where('id', '=', $this->input['auth_id'])->find();
        if(!$gameInfo || $gameInfo['status'] != 1){
            return $this->error("游戏的auth_id无效或没有授权", ['debug' => "游戏状态:".$gameInfo['status']], -7);
        }
        $this->gameInfo = $gameInfo;
    }


    /**
     * 保存日志
     *
     * @param $output
     * @throws \think\Exception
     */
    protected function saveLog($output)
    {
        $input = $this->input;

        $data = [
            'auth_id' => !empty($input['auth_id'])?$input['auth_id']:'',
            'module' => !empty($input['module'])?$input['module']:$this->request->controller(),
            'action' => !empty($input['action'])?$input['action']:$this->request->action(),
            'user_id' => !empty($input['data']['user_id'])?$input['data']['user_id']:'',
            'code' => $output['code'],
            'debug' => !empty($output['debug'])?$output['debug']:'',
            'request' => $input,
            'response' => $output,
            'server' => $_SERVER,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'date' => date('Y-m-d H:i:s'),
        ];
        Db::connect("mongo_db")->table("api_internal_log")->insert($data);
    }

}

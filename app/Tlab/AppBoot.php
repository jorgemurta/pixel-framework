<?php

namespace Tlab;

use Tlab\Libraries\Session;
use Tlab\Libraries\Database;
use Symfony\Component\HttpFoundation\Request;

class AppBoot
{
    protected $_controller, $_action, $_params, $_body;
    protected $_database = null;
    protected $_twig = null;

    protected $_template = null;
    protected $_langISO = null; //ISO code da lingua actual
    protected $_settings = null;

    protected $_httpRequest = null;

    public static $_instance;

    public static function create($settings = null)
    {
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self($settings);
        }

        return self::$_instance;
    }

    public static function getInstance()
    {
        if ((self::$_instance instanceof self)) {
            return self::$_instance;
        }

        throw new \Exception('Application Boot dont exists!');
    }

    private function __construct($settings)
    {
        $this->_httpRequest = Request::createFromGlobals();

        if (!is_null($settings)) {
            $this->_settings = $settings;
        }

        $this->init();

        $splits = explode('/', trim($_SERVER['REQUEST_URI'], '/'));

        $validLang = false;
        if (array_key_exists(0, $splits)) {
            $validLang = $this->processLanguage($splits);
        }

        $this->_controller = $this->processController($splits);
        $this->_action = $this->processAction($splits);
        $this->processParams($splits);

        if (!$validLang) {
            $this->_controller = 'errorController';
            $this->_action = 'indexAction';
        }

        $this->_connectDB();

        $this->_blocksList = array();
        $this->_headTags = array();
        $this->_template = $this->getConfig('settings.page_template');

        $this->templateLoader();
    }

    private function templateLoader()
    {
        $loader = new \Twig_Loader_Filesystem(_CONFIG_TEMPLATE_PATH);
        $this->_twig = new \Twig_Environment($loader, array(
            'cache' => _CONFIG_TEMPLATE_CACHE_PATH,
    ));

        $controllerFunction = new \Twig_SimpleFunction('controller', function ($controller, $action) {
        $response = $this->renderController($controller, $action);

        return $response;
    });

        $renderFunction = new \Twig_SimpleFunction('render', function ($response) {
        return $response->getContent();
    });

        $this->_twig->addFunction($controllerFunction);
        $this->_twig->addFunction($renderFunction);
    }

    public function render($file, $params)
    {
        return $this->_twig->render($file, $params);
    }

    private function processLanguage($splits)
    {
        $splits[0] = preg_replace("/[^A-Za-z0-9\-]/", '', urldecode($splits[0]));
        $validLang = true;
        switch ($splits[0]) {
            case 'en':
                $this->_langISO = 'en';
                break;
            case '':
                $this->_langISO = $this->getConfig('settings.default_lang_iso');
                break;
            default:
                $this->_langISO = $this->getConfig('settings.default_lang_iso');
                $validLang = false;

        }

        return $validLang;
    }

    private function processController($splits)
    {
        return  !empty($splits[1]) ? $splits[1].'Controller' : 'indexController';
    }

    private function processAction($splits)
    {
        $action = 'indexAction';
        if (!empty($splits[2])) {
            $temp = explode('?', $splits[2]);
            if (count($temp) == 2) {
                $splits[2] = $temp[0];
            }

            if (!empty($splits[2])) {
                $splits[2] = preg_replace("/[^A-Za-z0-9_\-\.]/", '', urldecode($splits[2]));
            }

            $action = str_replace('.'.$this->getConfig('settings.page_suffix'), '', $splits[2]);
            $action = !empty($action) ? $action.'Action' : 'indexAction';
        }

        return $action;
    }

    private function processParams($splits)
    {
        if (!empty($splits[3])) {
            $keys = $values = array();
            for ($idx = 3, $cnt = count($splits); $idx < $cnt; ++$idx) {
                if (($idx - 1) % 2 == 0) {
                    //Is even, is key
                    $keys[] = $splits[$idx];
                } else {
                    //Is odd, is value;
                    $values[] = $splits[$idx];
                }
            }
            if (count($keys) == count($values)) {
                $this->_params = array_combine($keys, $values);
            } else {
                $this->_controller = 'errorController';
                $this->_action = 'indexAction';
            }
        }
    }

    private function init()
    {
        $this->sessionStart();
        date_default_timezone_set($this->getConfig('settings.timezone'));
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        ini_set('log_errors', 1);
        ini_set('error_log', $this->getConfig('settings.logs_path')._DS.'error_log.txt');
    }

    private function _connectDB()
    {
        $params = array();
        $params['host'] = $this->getConfig('database.host');
        $params['username'] = $this->getConfig('database.username');
        $params['password'] = $this->getConfig('database.password');
        $params['dbname'] = $this->getConfig('database.name');
        $params['dbprefix'] = $this->getConfig('database.prefix');

        $this->_database = Database::getInstance($params);
    }

    public function getRequest()
    {
        return $this->_httpRequest;
    }

    protected function sessionStart()
    {
        $session_name = 'PIXELSESSION';   // Set a custom session name
    $secure = false;

    // This stops JavaScript being able to access the session id.
    $httponly = true;

    // Forces sessions to only use cookies.
    if (ini_set('session.use_only_cookies', 1) === false) {
        exit('Could not initiate a safe session (ini_set)');
    }

    // Gets current cookies params.
    $cookieParams = session_get_cookie_params();
        session_set_cookie_params($cookieParams['lifetime'],
    $cookieParams['path'],
    $cookieParams['domain'],
    $secure,
    $httponly);

    // Sets the session name to the one set above.
    session_name($session_name);
        session_start();            // Start the PHP session
    session_regenerate_id();    // regenerated the session, delete the old one.
    }

    public function getConfig($key)
    {
        $keys = explode('.', $key);
        if (!is_null($this->_settings)) {
            $settings = $this->_settings;
            for ($i = 0;$i < count($keys);++$i) {
                if (isset($settings[$keys[$i]])) {
                    $settings = $settings[$keys[$i]];
                } else {
                    throw new \Exception('Invalid setting');
                }
            }

            return $settings;
        }

        throw new \Exception('Invalid setting');
    }

    public function closeDB()
    {
        if (!is_null($this->_database)) {
            $this->_database->dbh = null;
            $this->_database = null;
        }
    }

    public function getLangISO()
    {
        return $this->_langISO;
    }

    public function setMessage($msg, $status)
    {
        $message = new Session('statusMessage');
        $message->setData('message', $msg);
        $message->setData('status', $status);
    }

    public function route()
    {
        if (class_exists('Tlab\\Controllers\\'.$this->getController())) {
            $rc = new \ReflectionClass('Tlab\\Controllers\\'.$this->getController());
            if ($rc->isSubclassOf('Tlab\\Libraries\\Controller') && $rc->hasMethod($this->getAction())) {
                return $this->invokeAction($rc);
            }
        }

        return $this->invokeNotFoundAction();
    }

    private function invokeNotFoundAction()
    {
        $this->_controller = 'errorController';
        $this->_action = 'indexAction';
        $this->_httpCode = 'HTTP/1.0 404 Not found';
        $rc = new \ReflectionClass('Tlab\\Controllers\\'.$this->getController());

        return $this->invokeAction($rc);
    }

    private function invokeAction($rc, $params = null)
    {
        $controller = $rc->newInstance($this);
        $method = $rc->getMethod($this->getAction());

        return $method->invoke($controller, $this->_httpRequest, $params);
    }

    public function renderController($controller, $action, $params = null)
    {
        $controller = $controller.'Controller';
        $action = $action.'Action';

        if (class_exists('Tlab\\Controllers\\'.$controller)) {
            $rc = new \ReflectionClass('Tlab\\Controllers\\'.$controller);
            if ($rc->isSubclassOf('Tlab\\Libraries\\Controller') && $rc->hasMethod($action)) {
                $controller = $rc->newInstance($this);
                $method = $rc->getMethod($action);

                return $method->invoke($controller, $this->_httpRequest, $params);
            }
        }
    }

    public function getController()
    {
        return $this->_controller;
    }

    public function getAction()
    {
        return $this->_action;
    }

    public function getDatabaseInstace()
    {
        return $this->_database;
    }

    public function setToken()
    {
        $token = uniqid(rand(1000, 9999));
        $tokenSession = new Session('securityToken');
        $tokenSession->setData('token', $token);

        return $token;
    }

    public function getToken()
    {
        $tokenSession = new Session('securityToken');
        $token = $tokenSession->getData('token');

        return $token;
    }

    public function jumpTo($controller, $action = null, $params = null)
    {
        $this->_controller = $controller;
        if (is_null($action)) {
            $this->_action = 'index';
        } else {
            $this->_action = $action;
        }

        if (!is_null($params) && is_array($params) && count($params) > 0 && (count($params) % 2) == 0) {
            $params = '/'.implode('/', $params);
        } else {
            $params = '';
        }

        $this->closeDB();
        header('LOCATION: '._CONFIG_LIVE_SITE.'/'.$this->_langISO.'/'.$this->_controller.'/'.$this->_action.$params);
        exit;
    }

    public function linkTo($controller, $action = null, $params = null)
    {
        if (is_null($action)) {
            $action = 'index';
        }

        if (!is_null($params) && is_array($params) && count($params) > 0 && (count($params) % 2) == 0) {
            $params = '/'.implode('/', $params);
        } else {
            $params = '';
        }

        return _CONFIG_LIVE_SITE.'/'.$this->_langISO.'/'.$controller.'/'.$action.$params;
    }

    public function setTemplate($template)
    {
        $this->_template = $template;
    }

    public function getTemplate()
    {
        return $this->_template;
    }

    public function run()
    {
        $response = $this->route();
        $response->send();
        $this->closeDB();
    }
}

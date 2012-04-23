<?php

/**
 * REST API Server. It is the server part of the Restler framework.
 * Based on the RestServer code from 
 * <http://jacwright.com/blog/resources/RestServer.txt>
 *
 * @category   Framework
 * @package    restler
 * @author     Jac Wright <jacwright@gmail.com>
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://luracast.com/products/restler/
 * @version    3.0.0
 */
class Restler
{

    const VERSION = '3.0.0';

    /**
     * Base URL currently being used
     * @var string
     */
    public $baseUrl;

    /**
     * URL of the currently mapped service
     * @var string
     */
    public $url;

    /**
     * Http request method of the current request.
     * Any value between [GET, PUT, POST, DELETE]
     * @var string
     */
    public $requestMethod;

    /**
     * Requested data format. Instance of the current format class
     * which implements the iFormat interface
     * @var iFormat
     * @example jsonFormat, xmlFormat, yamlFormat etc
     */
    public $requestFormat;

    /**
     * Data sent to the service
     * @var array
     */
    public $requestData = array();

    /**
     * Used in production mode to store the URL Map to disk
     * @var string
     */
    public $cacheDir;

    /**
     * base directory to locate format and auth files
     * @var string
     */
    public $baseDir;

    /**
     * Name of an iRespond implementation class
     * @var string
     */
    public $responder = 'DefaultResponder';

    /**
     * Response data format. Instance of the current format class
     * which implements the iFormat interface
     * @var iFormat
     * @example jsonFormat, xmlFormat, yamlFormat etc
     */
    public $responseFormat;

    ///////////////////////////////////////
    /**
     * When set to FALSE, it will run in debug mode and parse the
     * class files every time to map it to the URL
     * @var boolean
     */
    protected $_productionMode;

    /**
     * Associated array that maps urls to their respective class and method
     * @var array
     */
    protected $_routes = array();

    /**
     * Associated array that maps formats to their respective format class name
     * @var array
     */
    protected $_formatMap = array();

    /**
     * Instance of the current api service class
     * @var object
     */
    protected $_serviceClassInstance;

    /**
     * Name of the api method being called
     * @var string
     */
    protected $_serviceMethod;

    /**
     * method information including metadata
     * @var stdClass
     */
    protected $_serviceMethodInfo;

    /**
     * list of authentication classes
     * @var array
     */
    protected $_authClasses = array();

    /**
     * list of error handling classes
     * @var array
     */
    protected $_errorClasses = array();

    /**
     * HTTP status codes
     * @var array
     */
    private $_codes = array(
    100 => 'Continue', 
    101 => 'Switching Protocols', 
    200 => 'OK', 
    201 => 'Created', 
    202 => 'Accepted', 
    203 => 'Non-Authoritative Information', 
    204 => 'No Content', 
    205 => 'Reset Content', 
    206 => 'Partial Content', 
    300 => 'Multiple Choices', 
    301 => 'Moved Permanently', 
    302 => 'Found', 
    303 => 'See Other', 
    304 => 'Not Modified', 
    305 => 'Use Proxy', 
    306 => '(Unused)', 
    307 => 'Temporary Redirect', 
    400 => 'Bad Request', 
    401 => 'Unauthorized', 
    402 => 'Payment Required', 
    403 => 'Forbidden', 
    404 => 'Not Found', 
    405 => 'Method Not Allowed', 
    406 => 'Not Acceptable', 
    407 => 'Proxy Authentication Required', 
    408 => 'Request Timeout', 
    409 => 'Conflict', 
    410 => 'Gone', 
    411 => 'Length Required', 
    412 => 'Precondition Failed', 
    413 => 'Request Entity Too Large', 
    414 => 'Request-URI Too Long', 
    415 => 'Unsupported Media Type', 
    416 => 'Requested Range Not Satisfiable', 
    417 => 'Expectation Failed', 
    500 => 'Internal Server Error', 
    501 => 'Not Implemented', 
    502 => 'Bad Gateway', 
    503 => 'Service Unavailable', 
    504 => 'Gateway Timeout', 
    505 => 'HTTP Version Not Supported');

    /**
     * Caching of url map is enabled or not
     * @var boolean
     */
    protected $_cached;

    /**
     * Constructor
     * @param boolean $productionMode When set to FALSE, it will run in
     * debug mode and parse the class files every time to map it to the URL
     */
    public function __construct ($productionMode = FALSE, $refreshCache = FALSE)
    {
        ob_start();
        $this->_productionMode = $productionMode;
        $this->cacheDir = getcwd();
        $this->baseDir = RESTLER_PATH;
        //use this to rebuid cache everytime in production mode
        if ($productionMode && $refreshCache)
            $this->_cached = FALSE;
    }

    /**
     * Store the url map cache if needed
     */
    public function __destruct ()
    {
        if ($this->_productionMode && ! $this->_cached) {
            $this->saveCache();
        }
    }
    protected $_apiVersion = 0;
    protected $_requestedApiVersion = 0;
    protected $_apiMinimumVersion = 0;
    protected $_apiClassPath = '';
    protected $_log = '';
    
    public function setApiClassPath($path){
        $this->_apiClassPath = ! empty($path) && 
        $path{0} == '/' ? $path : $_SERVER['DOCUMENT_ROOT'] .
        dirname($_SERVER['SCRIPT_NAME']) . '/' . $path;
        $this->_apiClassPath = rtrim($this->_apiClassPath, '/');
    }
    
    public function setAPIVersion ($version, $minimum=0, $apiClassPath='')
    {
        if(!is_int($version))throw new InvalidArgumentException
        (
            'version should be an integer'
        );
        $this->_apiVersion = $version;
        if(is_int($minimum))$this->_apiMinimumVersion=$minimum;
        if(!empty($apiClassPath))$this->setAPIClassPath($apiClassPath);
        spl_autoload_register(array($this, 'versionedAPIAutoLoader'));
    }

    public function versionedAPIAutoLoader ($className)
    {
        $path = $this->_apiClassPath;
        $className = strtolower($className);
        $apiVersion = $this->_apiVersion;
        while ($apiVersion) {
            $file = "{$path}/v{$apiVersion}/{$className}.php";
            if (file_exists($file)) {
                require_once ($file);
                return TRUE;
            }
            $file = "{$path}/{$className}__v{$apiVersion}.php";
            if (file_exists($file)) {
                require_once ($file);
                return TRUE;
            }
            $apiVersion --;
        }        
        $file = "{$path}/{$className}.php";
        if (file_exists($file)) { 
            require_once ($file);
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Call this method and pass all the formats that should be
     * supported by the API. Accepts multiple parameters
     * @param string class name of the format class that implements iFormat
     * @example $restler->setSupportedFormats('JsonFormat', 'XmlFormat'...);
     */
    public function setSupportedFormats ()
    {
        $args = func_get_args();
        $extensions = array();
        foreach ($args as $className) {
            if (! is_string($className) || ! class_exists($className)) {
                throw new Exception("$className is not a vaild Format Class.");
            }
            $obj = new $className();
            if (! $obj instanceof iFormat) {
                throw new Exception(
                'Invalid format class; must implement ' . 'iFormat interface');
            }
            foreach ($obj->getMIMEMap() as $extension => $mime) {
                if (! isset($this->_formatMap[$extension]))
                    $this->_formatMap[$extension] = $className;
                if (! isset($this->_formatMap[$mime]))
                    $this->_formatMap[$mime] = $className;
                $extensions[".$extension"] = TRUE;
            }
        }
        $this->_formatMap['default'] = $args[0];
        $this->_formatMap['extensions'] = array_keys($extensions);
    }

    /**
     * Add api classes throgh this method. All the public methods
     * that do not start with _ (underscore) will be  will be exposed
     * as the public api by default.
     *
     * All the protected methods that do not start with _ (underscore)
     * will exposed as protected api which will require authentication
     * @param string $class name of the service class
     * @param string $basePath optional url prefix for mapping, uses
     * lowercase version of the class name when not specified
     * @throws Exception when supplied with invalid class name
     */
    public function addAPIClass ($className, $basePath = NULL)
    {
        if (! class_exists($className, TRUE)) {
            throw new Exception("API class $className is missing.");
        }
        $this->loadCache();
        if (! $this->_cached) {
            if (is_null($basePath))
                $basePath = str_replace('__v', '/v', strtolower($className));
            $basePath = trim($basePath, '/');
            if (strlen($basePath) > 0)
                $basePath .= '/';
            $this->generateMap($className, $basePath);
        }
    }

    /**
     * protected methods will need atleast one authentication class to be set
     * in order to allow that method to be executed
     * @param string $className of the authentication class
     * @param string $basePath optional url prefix for mapping
     */
    public function addAuthenticationClass ($className, $basePath = NULL)
    {
        $this->_authClasses[] = $className;
        $this->addAPIClass($className, $basePath);
    }

    /**
     * Add class for custom error handling
     * @param string $className of the error handling class
     */
    public function addErrorClass ($className)
    {
        $this->_errorClasses[] = $className;
    }

    /**
     * Convenience method to respond with an error message
     * @param int $statusCode http error code
     * @param string $errorMessage optional custom error message
     */
    public function handleError ($statusCode, $errorMessage = NULL)
    {
        $method = "handle$statusCode";
        $handled = FALSE;
        foreach ($this->_errorClasses as $className) {
            if (method_exists($className, $method)) {
                $obj = new $className();
                $obj->restler = $this;
                $obj->$method();
                $handled = TRUE;
            }
        }
        if ($handled)
            return;
        $this->sendData(NULL, $statusCode, $errorMessage);
    }

    /**
     * Main function for processing the api request
     * and return the response
     * @throws Exception when the api service class is missing
     * @throws RestException to send error response
     */
    public function handle ()
    {
        if (empty($this->_formatMap))
            $this->setSupportedFormats('JsonFormat');
        $this->url = $this->getPath();
        $this->requestMethod = $this->getRequestMethod();
        $this->responseFormat = $this->getResponseFormat();
        $this->requestFormat = $this->getRequestFormat();
        $this->responseFormat->restler = $this;
        if (is_null($this->requestFormat)) {
            $this->requestFormat = $this->responseFormat;
        }else{
            $this->requestFormat->restler = $this;
        }
        if (
                $this->requestMethod == 'PUT' || 
                $this->requestMethod == 'PATCH'|| 
                $this->requestMethod == 'POST'
           ) {
            $this->requestData = $this->getRequestData();
        }
        $this->_serviceMethodInfo = $o = $this->mapUrlToMethod();
        if (! isset($o->className)) {
            $this->handleError(404);
        } else {
            try {
                if ($o->methodFlag) {
                    $authMethod = '__isAuthenticated';
                    if (! count($this->_authClasses))
                        throw new RestException(401);
                    foreach ($this->_authClasses as $authClass) {
                        $authObj = new $authClass();
                        $authObj->restler = $this;
                        $this->applyClassMetadata($authClass, $authObj, $o);
                        if (! method_exists($authObj, $authMethod)) {
                            throw new RestException(401, 
                            'Authentication Class ' .
                             'should implement iAuthenticate');
                        } elseif (! $authObj->$authMethod()) {
                            throw new RestException(401);
                        }
                    }
                }
                $this->applyClassMetadata(get_class($this->requestFormat), 
                $this->requestFormat, $o);
                $preProcess = '_' . $this->requestFormat->getExtension() . '_' .
                 $o->methodName;
                $this->_serviceMethod = $o->methodName;
                if ($o->methodFlag == 2)
                    $o = unprotect($o);
                $object = $this->_serviceClassInstance = new $o->className();
                $object->restler = $this;
                //TODO:check if the api version requested is allowed by class
                trace($o);
                //TODO: validate params using iValidate
                if (method_exists($o->className, $preProcess)) {
                    call_user_func_array(array(
                    $object, 
                    $preProcess), $o->arguments);
                }
                switch ($o->methodFlag) {
                    case 3:
                        $reflectionMethod = new ReflectionMethod($object, 
                        $o->methodName);
                        $reflectionMethod->setAccessible(TRUE);
                        $result = $reflectionMethod->invokeArgs($object, 
                        $o->arguments);
                        break;
                    case 2:
                    case 1:
                    default:
                        $result = call_user_func_array(
                        array(
                        $object, 
                        $o->methodName), $o->arguments);
                }
            } catch (RestException $e) {
                $this->handleError($e->getCode(), $e->getMessage());
            }
        }
        if (isset($result) && $result !== NULL) {
            $this->sendData($result);
        }
    }

    /**
     * Encodes the response in the prefered format
     * and sends back
     * @param $data array php data
     */
    public function sendData ($data, $statusCode = 0, $statusMessage = NULL)
    {
        $this->_log = ob_get_clean();
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');
        header('Content-Type: ' . $this->responseFormat->getMIME());
        header('X-Powered-By: Luracast Restler v' . Restler::VERSION);
        if(isset($this->_serviceMethodInfo->metadata['status'])){
            call_user_func_array(
                array($this,'setStatus'),
                $this->_serviceMethodInfo->metadata['status']
            );
        }
        if(isset($this->_serviceMethodInfo->metadata['header'])){
             foreach ($this->_serviceMethodInfo->metadata['header'] as $header) {
                 header($header, TRUE);
             }
        }
        /**
         * @var iRespond DefaultResponder
         */
        $responder = new $this->responder();
        $responder->restler = $this;
        $this->applyClassMetadata($this->responder, $responder, 
        $this->_serviceMethodInfo);
        if ($statusCode == 0) {
            $data = $this->responseFormat->encode($data, 
            ! $this->_productionMode);
            $data = $responder->__formatResponse($data);
            $postProcess = '_' . $this->_serviceMethod . '_' .
             $this->responseFormat->getExtension();
            if (isset($this->_serviceClassInstance) &&
             method_exists($this->_serviceClassInstance, $postProcess)) {
                $data = call_user_func(
                array(
                $this->_serviceClassInstance, 
                $postProcess), $data);
            }
        } else {
            $message = $this->_codes[$statusCode] .
             (empty($statusMessage) ? '' : ': ' . $statusMessage);
            $this->setStatus($statusCode);
            $data = $this->responseFormat->encode(
            $responder->__formatError($statusCode, $message), 
            ! $this->_productionMode);
        }
        die($data);
    }

    /**
     * Sets the HTTP response status
     * @param int $code response code
     */
    public function setStatus ($code)
    {
        if (isset($_GET['suppress_response_codes']) &&
         $_GET['suppress_response_codes'] == 'true')
            $code = 200;
        header(
        "{$_SERVER['SERVER_PROTOCOL']} $code " . $this->_codes[strval($code)]);
    }

    /**
     * Compare two strings and remove the common
     * sub string from the first string and return it
     * @param string $first
     * @param string $second
     * @param string $char optional, set it as
     * blank string for char by char comparison
     * @return string
     */
    public function removeCommonPath ($first, $second, $char = '/')
    {
        $first = explode($char, $first);
        $second = explode($char, $second);
        while (count($second)) {
            if ($first[0] == $second[0]) {
                array_shift($first);
            } else
                break;
            array_shift($second);
        }
        return implode($char, $first);
    }

    public function saveCache ()
    {
        $file = $this->cacheDir . '/routes.php';
        $s = '$o=array();' . PHP_EOL;
        foreach ($this->_routes as $key => $value) {
            $s .= PHP_EOL . PHP_EOL . PHP_EOL .
             "//############### $key ###############" . PHP_EOL . PHP_EOL;
            $s .= '$o[\'' . $key . '\']=array();';
            foreach ($value as $ke => $va) {
                $s .= PHP_EOL . PHP_EOL . "//==== $key $ke" . PHP_EOL . PHP_EOL;
                $s .= '$o[\'' . $key . '\'][\'' . $ke . '\']=' . str_replace(
                PHP_EOL, PHP_EOL . "\t", var_export($va, TRUE)) . ';';
            }
        }
        $s .= PHP_EOL . 'return $o;';
        $r = @file_put_contents($file, "<?php $s");
        @chmod($file, 0777);
        if ($r === FALSE)
            throw new Exception(
            "The cache directory located at '$this->cacheDir' needs to have " .
             'the permissions set to read/write/execute for everyone ' .
             'in order to save cache and improve performance.');
    }

    /**
     * Magic method to expose some protected variables
     * @param String $name
     */
    public function __get ($name)
    {
        $privateProperty = "_$name";
        if (isset($this->$privateProperty))
            return $this->$privateProperty;
    }

    
    ///////////////////////////////////////////////////////////////
    /**
     * Parses the requst url and get the api path
     * @return string api path
     */
    protected function getPath ()
    {
        $fullPath = $_SERVER['REQUEST_URI'];
        $path = urldecode(
        $this->removeCommonPath($fullPath, $_SERVER['SCRIPT_NAME']));
        $baseUrl = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 'https://' : 'http://';
        if ($_SERVER['SERVER_PORT'] != '80') {
            $baseUrl .= $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT'];
        } else {
            $baseUrl .= $_SERVER['SERVER_NAME'];
        }
        $this->baseUrl = $baseUrl . rtrim(
        substr($fullPath, 0, strlen($fullPath) - strlen($path)), '/');
        
        $path = preg_replace('/(\/*\?.*$)|(\/$)/', '', $path);
        $path = str_replace($this->_formatMap['extensions'], '', $path);
        if ($this->_apiVersion && $path{0} == 'v') {
            $version = intval(substr($path, 1));
            if ($version && $version <= $this->_apiVersion) {
                $this->_requestedApiVersion = $version;
                $path = explode('/', $path, 2);
                $path = $path[1];
            }
        } elseif ($this->_apiVersion) {
            $this->_requestedApiVersion = $this->_apiVersion;
        }
        /*
        print_r(
        array(
        'baseUrl' => $this->baseUrl, 
        'path' => $path, 
        'version' => $this->_requestedApiVersion));
        */
        return $path;
    }

    /**
     * Parses the request to figure out the http request type
     * @return string which will be one of the following
     * [GET, POST, PUT, PATCH, DELETE]
     * @example GET
     */
    protected function getRequestMethod ()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            $method = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'];
        } elseif (isset($_GET['method'])) {
            //support for exceptional clients who can't set the header  
            $m = strtoupper($_GET['method']);
            if ($m == 'PUT' || $m == 'DELETE' || $m == 'POST' || $m == 'PATCH')
                $method = $m;
        }
        //support for HEAD request
        if ($method == 'HEAD')
            $method = 'GET';
        return $method;
    }

    /**
     * Parses the request to figure out format of the request data
     * @return iFormat any class that implements iFormat
     * @example JsonFormat
     */
    protected function getRequestFormat ()
    {
        $format = NULL;
        //check if client has sent any information on request format
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $mime = explode(';', $_SERVER['CONTENT_TYPE']);
            $mime = $mime[0];
            if ($mime == UrlEncodedFormat::MIME) {
                $format = new UrlEncodedFormat();
            } else {
                if (isset($this->_formatMap[$mime])) {
                    $format = $this->_formatMap[$mime];
                    $format = is_string($format) ? new $format() : $format;
                    $format->setMIME($mime);
                } else {
                    $this->handleError(403, 
                    "Content type $mime is not supported.");
                    return;
                }
            }
        }
        return $format;
    }

    /**
     * Parses the request to figure out the best format for response.
     * Extension, if present, overrides the Accept header
     * @return iFormat any class that implements iFormat
     * @example JsonFormat
     */
    protected function getResponseFormat ()
    {
        //check if client has specified an extension
        /**
         * @var iFormat
         */
        $format = NULL;
        $extensions = explode('.', 
        parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        while ($extensions) {
            $extension = array_pop($extensions);
            $extension = explode('/', $extension);
            $extension = array_shift($extension);
            if ($extension && isset($this->_formatMap[$extension])) {
                $format = $this->_formatMap[$extension];
                $format = is_string($format) ? new $format() : $format;
                $format->setExtension($extension);
                //echo "Extension $extension";
                return $format;
            }
        }
        //check if client has sent list of accepted data formats
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $accepts = explode(',', $_SERVER['HTTP_ACCEPT']);
            foreach ($accepts as $accept) {
                if ($extension && isset($this->_formatMap[$accept])) {
                    $format = $this->_formatMap[$accept];
                    $format = is_string($format) ? new $format() : $format;
                    $format->setMIME($accept);
                    //echo "MIME $accept";
                    return $format;
                }
            }
        }
        $format = new $this->_formatMap['default']();
        //echo "DEFAULT ".$this->_formatMap['default'];
        return $format;
    }

    /**
     * Parses the request data and returns it
     * @return array php data
     */
    protected function getRequestData ()
    {
        try {
            $r = file_get_contents('php://input');
            if (is_null($r))
                return $_GET;
            $r = $this->requestFormat->decode($r);
            return is_null($r) ? array() : $r;
        } catch (RestException $e) {
            $this->handleError($e->getCode(), $e->getMessage());
        }
    }

    protected function mapUrlToMethod ()
    {
        if (! isset($this->_routes[$this->requestMethod])) {
            return array();
        }
        $urls = $this->_routes[$this->requestMethod];
        if (! $urls)
            return array();
        $found = FALSE;
        $this->requestData += $_GET;
        $params = array(
        'request_data' => $this->requestData);
        $params += $this->requestData;
        foreach ($urls as $url => $call) {
            //echo PHP_EOL.$url.' = '.$this->url.PHP_EOL;
            $call = (object) $call;
            if (strstr($url, '{')) {
                $regex = str_replace(array(
                '{', 
                '}'), array(
                '(?P<', 
                '>[^/]+)'), $url);
                if (preg_match(":^$regex$:", $this->url, $matches)) {
                    foreach ($matches as $arg => $match) {
                        if (isset($call->arguments[$arg])) {
                            //flog("$arg => $match $args[$arg]");
                            $params[$arg] = $match;
                        }
                    }
                    $found = TRUE;
                    break;
                }
            } elseif (strstr($url, ':')) {
                $regex = preg_replace('/\\\:([^\/]+)/', '(?P<$1>[^/]+)', 
                preg_quote($url));
                if (preg_match(":^$regex$:", $this->url, $matches)) {
                    foreach ($matches as $arg => $match) {
                        if (isset($call->arguments[$arg])) {
                            //flog("$arg => $match $args[$arg]");
                            $params[$arg] = $match;
                        }
                    }
                    $found = TRUE;
                    break;
                }
            } elseif ($url == $this->url) {
                $found = TRUE;
                break;
            }
        }
        if ($found) {
            //echo PHP_EOL."Found $url ";
            //print_r($call);
            $p = $call->defaults;
            foreach ($call->arguments as $key => $value) {
                //echo "$key => $value \n";
                if (isset($params[$key]))
                    $p[$value] = $params[$key];
            }
            $call->arguments = $p;
            return $call;
        }
    }

    /**
     * Apply static and non-static properties defined in
     * the method information anotation
     * @param String $className
     * @param Object $instance instance of that class
     * @param Object $methodInfo method information and metadata
     */
    protected function applyClassMetadata ($className, $instance, $methodInfo)
    {
        if (isset($methodInfo->metadata[$className]) &&
         is_array($methodInfo->metadata[$className])) {
            foreach ($methodInfo->metadata[$className] as $property => $value) {
                if (property_exists($className, $property)) {
                    $reflectionProperty = new ReflectionProperty($className, 
                    $property);
                    $reflectionProperty->setValue($instance, $value);
                }
            }
        }
    }

    protected function loadCache ()
    {
        if ($this->_cached !== NULL) {
            return;
        }
        $file = $this->cacheDir . '/routes.php';
        $this->_cached = FALSE;
        if ($this->_productionMode) {
            if (file_exists($file)) {
                $routes = include ($file);
            }
            if (isset($routes) && is_array($routes)) {
                $this->_routes = $routes;
                $this->_cached = TRUE;
            }
        } else {
            //@unlink($this->cacheDir . "/$name.php");
        }
    }

    /**
     * Generates cachable url to method mapping
     * @param string $className
     * @param string $basePath
     */
    protected function generateMap ($className, $basePath = '')
    {
        /*
         * Mapping Rules
         * - Optional parameters should not be mapped to URL
         * - if a required parameter is of primitive type
         *   - Map them to URL
         *   - Do not create routes with out it
         * - if a required parameter is not primitive type
         *   - Do not inlcude it in URL
         */
        $reflection = new ReflectionClass($className);
        $classMetadata = parse_doc($reflection->getDocComment());
        $methods = $reflection->getMethods(
        ReflectionMethod::IS_PUBLIC + ReflectionMethod::IS_PROTECTED);
        foreach ($methods as $method) {
            $doc = $method->getDocComment();
            $arguments = array();
            $defaults = array();
            $metadata = parse_doc($doc) + $classMetadata;
            $params = $method->getParameters();
            $position = 0;
            $ignorePathTill = FALSE;
            if (isset($classMetadata['description']))
                $metadata['classDescription'] = $classMetadata['description'];
            if (isset($classMetadata['classLongDescription']))
                $metadata['classLongDescription'] = $classMetadata['longDescription'];
            if (! isset($metadata['param']))
                $metadata['param'] = array();
            foreach ($params as $param) {
                $arguments[$param->getName()] = $position;
                $defaults[$position] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : NULL;
                if (! isset($metadata['param'][$position]))
                    $metadata['param'][$position] = array();
                $metadata['param'][$position]['name'] = trim($param->getName(), 
                '$ ');
                $metadata['param'][$position]['default'] = $defaults[$position];
                if ($param->isOptional()) {
                    $metadata['param'][$position]['required'] = FALSE;
                } else {
                    $metadata['param'][$position]['required'] = TRUE;
                    if ($param->getName() != 'request_data')
                        $ignorePathTill = $position + 1;
                }
                $position ++;
            }
            $methodFlag = $method->isProtected() ? (isRestlerCompatibilityModeEnabled() ? 2 : 3) : (isset(
            $metadata['protected']) ? 1 : 0);
            //take note of the order
            $call = array(
            'className' => $className, 
            'path' => rtrim($basePath, '/'), 
            'methodName' => $method->getName(), 
            'arguments' => $arguments, 
            'defaults' => $defaults, 
            'metadata' => $metadata, 
            'methodFlag' => $methodFlag);
            $methodUrl = strtolower($method->getName());
            if (preg_match_all(
            '/@url\s+(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)[ \t]*\/?(\S*)/s', 
            $doc, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $httpMethod = $match[1];
                    $url = rtrim($basePath . $match[2], '/');
                    $this->_routes[$httpMethod][$url] = $call;
                }
            } elseif ($methodUrl[0] != '_' && ! isset($metadata['url-'])) { 
                //not prefixed with underscore
                // no configuration found so use convention
                if (preg_match_all(
                '/^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)/i', 
                $methodUrl, $matches)) {
                    $httpMethod = strtoupper($matches[0][0]);
                    $methodUrl = substr($methodUrl, strlen($httpMethod));
                } else {
                    $httpMethod = 'GET';
                }
                if ($methodUrl == 'index')
                    $methodUrl = '';
                $url = empty($methodUrl) ? rtrim($basePath, '/') : $basePath .
                 $methodUrl;
        //$url = rtrim($basePath.($methodUrl == 'index' ? '' : $methodUrl),'/');
                if (! $ignorePathTill)
                    $this->_routes[$httpMethod][$url] = $call;
                $position = 1;
                foreach ($params as $param) {
                    if ($param->isOptional() ||
                     $param->getName() == 'request_data') {
                        break;
                    }
                    if (! empty($url))
                        $url .= '/';
                    $url .= '{' . $param->getName() . '}';
                    if ($position == $ignorePathTill)
                        $this->_routes[$httpMethod][$url] = $call;
                    $position ++;
                }
            }
        }
    }
}
if (version_compare(PHP_VERSION, '5.3.0') < 0) {
    require_once 'compat.php';
}

/**
 * Special Exception for raising API errors
 * that can be used in API methods
 * @category   Framework
 * @package    restler
 * @subpackage exception
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://luracast.com/products/restler/
 */
class RestException extends Exception
{

    public function __construct ($httpStatusCode, $errorMessage = NULL)
    {
        parent::__construct($errorMessage, $httpStatusCode);
    }
}

/**
 * Interface for creating response classes
 * @category   Framework
 * @package    restler
 * @subpackage result
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://luracast.com/products/restler/
 */
interface iRespond
{

    /**
     * Result of an api call is passed to this method
     * to create a standard structure for the data
     * @param unknown_type $result can be a primitive or array or object
     */
    public function __formatResponse ($result);

    /**
     * When the api call results in RestException this method
     * will be called to return the error message
     * @param int $statusCode
     * @param String $message
     */
    public function __formatError ($statusCode, $message);
}

/**
 * Default response formating class
 * @category   Framework
 * @package    restler
 * @subpackage result
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://luracast.com/products/restler/
 */
class DefaultResponder implements iRespond
{

    /**
     * Current Restler instance
     * Injected at runtime
     * @var Restler
     */
    public $restler;

    public static $customMIMEType;

    public static $customMIMEVersion;

    private function setCustomMIMEHeader ()
    {
        if (! isset($this->restler->serviceMethodInfo))
            return;
        $metadata = $this->restler->serviceMethodInfo->metadata;
        if (! empty($metadata['mime']))
            self::$customMIMEType = $metadata['mime'];
        if (! empty($metadata['version']))
            self::$customMIMEVersion = $metadata['version'];
        if (! empty(self::$customMIMEType)) {
            $header = 'Content-Type: ' . self::$customMIMEType;
            if (! empty(self::$customMIMEVersion))
                $header .= '-v' . self::$customMIMEVersion;
            $header .= '+' . $this->restler->responseFormat->getExtension();
            header($header);
        }
    }

    function __formatResponse ($result)
    {
        $this->setCustomMIMEHeader();
        /*
        $className = get_class($this->classInstance);
        header(
        'Content-Type: application/vnd.mycompany.' .
         strtolower($className) .
         '-v' .
         $className::VERSION .
         "+" .
         $this->formatInstance->getExtension());
         */
        return $result;
    }

    function __formatError ($statusCode, $message)
    {
        $this->setCustomMIMEHeader();
        return array(
        'error' => array(
        'code' => $statusCode, 
        'message' => $message));
    }
}

/**
 * Interface for creating authentication classes
 * @category   Framework
 * @package    restler
 * @subpackage auth
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://luracast.com/products/restler/
 */
interface iAuthenticate
{

    /**
     * Auth function that is called when a protected method is requested
     * @return boolean TRUE or FALSE
     */
    public function __isAuthenticated ();
}

/**
 * Interface for creating custom data formats
 * like xml, json, yaml, amf etc
 * @category   Framework
 * @package    restler
 * @subpackage format
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://luracast.com/products/restler/
 */
interface iFormat
{

    /**
     * Get Extension => MIME type mappings as an associative array
     * @return array list of mime strings for the format
     * @example array('json'=>'application/json');
     */
    public function getMIMEMap ();

    /**
     * Set the selected MIME type
     * @param string $mime MIME type
     */
    public function setMIME ($mime);

    /**
     * Get selected MIME type
     */
    public function getMIME ();

    /**
     * Set the selected file extension
     * @param string $extension file extension
     */
    public function setExtension ($extension);

    /**
     * Get the selected file extension
     * @return string file extension
     */
    public function getExtension ();

    /**
     * Encode the given data in the format
     * @param array $data resulting data that needs to
     * be encoded in the given format
     * @param boolean $humanReadable set to TRUE when restler
     * is not running in production mode. Formatter has to
     * make the encoded output more human readable
     * @return string encoded string
     */
    public function encode ($data, $humanReadable = FALSE);

    /**
     * Decode the given data from the format
     * @param string $data data sent from client to
     * the api in the given format.
     * @return array associative array of the parsed data
     */
    public function decode ($data);
}

/**
 * URL Encoded String Format
 * @category   Framework
 * @package    restler
 * @subpackage format
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://luracast.com/products/restler/
 */
class UrlEncodedFormat implements iFormat
{

    const MIME = 'application/x-www-form-urlencoded';

    const EXTENSION = 'post';

    public function getMIMEMap ()
    {
        return array(
        self::EXTENSION => self::MIME);
    }

    public function getMIME ()
    {
        return self::MIME;
    }

    public function getExtension ()
    {
        return self::EXTENSION;
    }

    public function setMIME ($mime)
    {
        //do nothing
    }

    public function setExtension ($extension)
    {
        //do nothing
    }

    public function encode ($data, $humanReadable = FALSE)
    {
        return http_build_query($data);
    }

    public function decode ($data)
    {
        parse_str($data, $r);
        return $r;
    }

    public function __toString ()
    {
        return $this->getExtension();
    }
}

//TODO: define JSON_BIGINT_AS_STRING, JSON_PRETTY_PRINT, JSON_UNESCAPED_SLASHES,
// and JSON_UNESCAPED_UNICODE if not defined (PHP version <5.4) and handle the
// options manually to get the same result
/**
 * Javascript Object Notation Format
 * @category   Framework
 * @package    restler
 * @subpackage format
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://luracast.com/products/restler/
 */
class JsonFormat implements iFormat
{
    /**
     * options that you want to pass for json_encode (used internally)
     * just make sure those options are supported by your PHP version
     * @example JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
     * @var int
     */
    public static $encodeOptions = 0;

    const MIME = 'application/json';

    const EXTENSION = 'json';

    public function getMIMEMap ()
    {
        return array(
        self::EXTENSION => self::MIME);
    }

    public function getMIME ()
    {
        return self::MIME;
    }

    public function getExtension ()
    {
        return self::EXTENSION;
    }

    public function setMIME ($mime)
    {
        //do nothing
    }

    public function setExtension ($extension)
    {
        //do nothing
    }

    public function encode ($data, $humanReadable = FALSE)
    {
        $customHumanReadable = TRUE;
        if($humanReadable && defined('JSON_PRETTY_PRINT')){ 
            //PHP >= 5.4
            self::$encodeOptions = self::$encodeOptions | JSON_PRETTY_PRINT;
            $customHumanReadable = FALSE;
        }
        $result = json_encode(object_to_array($data), self::$encodeOptions);
        if($humanReadable && $customHumanReadable)
            $result = $this->json_format($result);
        //TODO: modify below line. it is added for JSON_UNESCAPED_SLASHES
        $result = str_replace('\/', '/', $result);
        return $result;
    }

    public function decode ($data)
    {
        $decoded = json_decode($data);
        if (function_exists('json_last_error')) {
            $message = '';
            switch (json_last_error()) {
                case JSON_ERROR_NONE:
                    return object_to_array($decoded);
                    break;
                case JSON_ERROR_DEPTH:
                    $message = 'maximum stack depth exceeded';
                    break;
                case JSON_ERROR_STATE_MISMATCH:
                    $message = 'underflow or the modes mismatch';
                    break;
                case JSON_ERROR_CTRL_CHAR:
                    $message = 'unexpected control character found';
                    break;
                case JSON_ERROR_SYNTAX:
                    $message = 'malformed JSON';
                    break;
                case JSON_ERROR_UTF8:
                    $message = 'malformed UTF-8 characters, possibly incorrectly encoded';
                    break;
                default:
                    $message = 'unknown error';
                    break;
            }
            throw new RestException(400, 'Error parsing JSON, ' . $message);
        } else 
            if (strlen($data) && $decoded === NULL || $decoded === $data) {
                throw new RestException(400, 'Error parsing JSON');
            }
        return object_to_array($decoded);
    }

    /**
     * Pretty print JSON string
     * @param string $json
     * @return string formated json
     */
    private function json_format ($json)
    {
        $tab = '  ';
        $newJson = '';
        $indentLevel = 0;
        $inString = FALSE;
        $len = strlen($json);
        for ($c = 0; $c < $len; $c ++) {
            $char = $json[$c];
            switch ($char) {
                case '{':
                case '[':
                    if (! $inString) {
                        $newJson .= $char . "\n" . str_repeat($tab, 
                        $indentLevel + 1);
                        $indentLevel ++;
                    } else {
                        $newJson .= $char;
                    }
                    break;
                case '}':
                case ']':
                    if (! $inString) {
                        $indentLevel --;
                        $newJson .= "\n" . str_repeat($tab, $indentLevel) . $char;
                    } else {
                        $newJson .= $char;
                    }
                    break;
                case ',':
                    if (! $inString) {
                        $newJson .= ",\n" . str_repeat($tab, $indentLevel);
                    } else {
                        $newJson .= $char;
                    }
                    break;
                case ':':
                    if (! $inString) {
                        $newJson .= ': ';
                    } else {
                        $newJson .= $char;
                    }
                    break;
                case '"':
                    if ($c == 0) {
                        $inString = TRUE;
                    } elseif ($c > 0 && $json[$c - 1] != '\\') {
                        $inString = ! $inString;
                    }
                default:
                    $newJson .= $char;
                    break;
            }
        }
        return $newJson;
    }

    public function __toString ()
    {
        return $this->getExtension();
    }
}

/**
 * Parses the PHPDoc comments for metadata. Inspired by Documentor code base
 * @category   Framework
 * @package    restler
 * @subpackage helper
 * @author     Murray Picton <info@murraypicton.com>
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 * @link       https://github.com/murraypicton/Doqumentor
 */
class DocParser
{

    private $_params = array();

    function parse ($doc = '')
    {
        if ($doc == '') {
            return $this->_params;
        }
        //Get the comment
        if (preg_match('#^/\*\*(.*)\*/#s', $doc, $comment) === false)
            return $this->_params;
        $comment = trim($comment[1]);
        //Get all the lines and strip the * from the first character
        if (preg_match_all('#^\s*\*(.*)#m', $comment, $lines) === false)
            return $this->_params;
        $this->parseLines($lines[1]);
        return $this->_params;
    }

    private function parseLines ($lines)
    {
        foreach ($lines as $line) {
            $parsedLine = $this->parseLine($line); //Parse the line
            if ($parsedLine === false &&
             ! isset($this->_params['description'])) {
                if (isset($desc)) {
                    //Store the first line in the short description
                    $this->_params['description'] = implode(
                    PHP_EOL, $desc);
                }
                $desc = array();
            } elseif ($parsedLine !== false) {
                $desc[] = $parsedLine; //Store the line in the long description
            }
        }
        $desc = trim(implode(' ', $desc));
        if (! empty($desc))
            $this->_params['longDescription'] = $desc;
    }

    private function parseLine ($line)
    {
        //trim the whitespace from the line
        $line = trim($line);
        if (empty($line))
            return false; //Empty line
        if (strpos($line, '@') === 0) {
            if (strpos($line, ' ') > 0) {
                //Get the parameter name
                $param = substr($line, 1, 
                strpos($line, ' ') - 1);
                $value = substr($line, strlen($param) + 2); //Get the value
            } else {
                $param = substr($line, 1);
                $value = '';
            }
            //Parse the line and return false if the parameter is valid
            if ($this->setParam($param, $value))
                return false;
        }
        return $line;
    }

    private function setParam ($param, $value)
    {
        $allowMultiple = FALSE;
        switch ($param) {
            case 'param':
                $value = $this->formatParam($value);
                $allowMultiple = TRUE;
                break;
            case 'return':
                $value = $this->formatReturn($value);
                break;
            case 'class':
                list ($param, $value) = $this->formatClass($value);
                break;
            case 'status':
                $value = explode(' ', $value, 2);
                $value[0]= intval($value[0]);
                break;
            case 'throws':
                $value = $this->formatThrows($value);
                $allowMultiple = TRUE;
                break;
            case 'header':
                $allowMultiple = TRUE;
        }
        if (empty($this->_params[$param])) {
            if ($allowMultiple)
                $this->_params[$param] = array(
                $value);
            else
                $this->_params[$param] = $value;
        } elseif ($allowMultiple) {
            $this->_params[$param][] = $value;
        } elseif ($param == 'param') {
            $arr = array(
            $this->_params[$param], 
            $value);
            $this->_params[$param] = $arr;
        } else {
            $this->_params[$param] = $value + $this->_params[$param];
        }
        return true;
    }

    private function formatThrows ($value)
    {
        $value = explode(' ', $value, 3);
        $r = array(
        'exception' => $value[0]);
        $r['code'] = @is_numeric($value[1]) ? intval($value[1]) : 500;
        $r['reason'] = @isset($value[2]) ? $value[2] : '';
        return $r;
    }

    private function formatClass ($value)
    {
        $r = preg_split("[{|}]", $value);
        if (is_array($r)) {
            $param = trim($r[0]);
            parse_str($r[1], $value);
            foreach ($value as $key => $val) {
                $val = explode(',', $val);
                if (count($val) > 1)
                    $value[$key] = $val;
            }
        } else {
            $param = 'Unknown';
        }
        return array(
        $param, 
        $value);
    }

    private function formatReturn ($string)
    {
        $arr = explode(' ', $string, 2);
        $r = array(
        'type' => $arr[0]);
        if (! empty($arr[1]))
            $r['description'] = trim($arr[1]);
        return $r;
    }

    private function formatParam ($string)
    {
        $arr = explode(' ', $string, 2);
        $r = array(
        'type' => $arr[0]);
        $arr2 = preg_split("[{|}]", $arr[1]);
        if (! empty($arr2[0]))
            $arr3 = explode(' ', $arr2[0], 2);
        $r['name'] = trim($arr3[0], '$  ');
        if (! empty($arr3[1]))
            $r['description'] = trim($arr3[1]);
        if (! empty($arr2[1])) {
            if (! isset($r['validate']))
                $r['validate'] = array();
            parse_str($arr2[1], $value);
            foreach ($value as $key => $val) {
                $val = explode(',', $val);
                if (count($val) > 1)
                    $value[$key] = $val;
            }
            $r['validate'] = $value;
        }
        if (! empty($arr2[2]))
            $r['description'] = trim($arr2[2]);
        return $r;
    }
}

function parse_doc ($phpDocComment)
{
    $p = new DocParser();
    return $p->parse($phpDocComment);
    $p = new Parser($phpDocComment);
    return $p;
    $phpDocComment = preg_replace(
    "/(^[\\s]*\\/\\*\\*)|(^[\\s]\\*\\/)|
    (^[\\s]*\\*?\\s)|(^[\\s]*)(^[\\t]*)/ixm", "", 
    $phpDocComment);
    $phpDocComment = str_replace("\r", '', $phpDocComment);
    $phpDocComment = preg_replace("/([\\t])+/", "\t", $phpDocComment);
    return explode("\n", $phpDocComment);
    $phpDocComment = trim(preg_replace('/\r?\n *\* */', ' ', $phpDocComment));
    return $phpDocComment;
    preg_match_all('/@([a-z]+)\s+(.*?)\s*(?=$|@[a-z]+\s)/s', $phpDocComment, 
    $matches);
    return array_combine($matches[1], $matches[2]);
}

/**
 * Conveniance function that converts the given object
 * in to associative array
 * @param object $object that needs to be converted
 * @category   Framework
 * @package    restler
 * @subpackage format
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://luracast.com/products/restler/
 */
function object_to_array ($object, $utfEncode = FALSE)
{
    if (is_array($object) || is_object($object)) {
        $array = array();
        foreach ($object as $key => $value) {
            $value = object_to_array($value, $utfEncode);
            if ($utfEncode && is_string($value)) {
                $value = utf8_encode($value);
            }
            $array[$key] = $value;
        }
        return $array;
    }
    return $object;
}

/**
 * an autoloader class with a static function for loading format classes
 * @param String $className class name of a class that implements iFormat
 */
class RestlerAutoLoader
{

    static function formats ($className)
    {
        $className = strtolower($className);
        $file = RESTLER_PATH . "/$className/$className.php";
        if (file_exists($file)) {
            require_once ($file);
        } elseif (file_exists("$className.php")) {
            require_once ("$className.php");
        }
    }
}
spl_autoload_register(array(
'RestlerAutoLoader',  
'formats'));
/**
 * Manage compatibility with PHP 5 < PHP 5.3
 */
if (! function_exists('isRestlerCompatibilityModeEnabled')) {

    function isRestlerCompatibilityModeEnabled ()
    {
        return FALSE;
    }
}
if(! function_exists('trace')){
	function trace($o,$level=LOG_NOTICE){
		//ignore;
	}
}
if(!defined('RESTLER_PATH'))define('RESTLER_PATH', dirname(__FILE__));
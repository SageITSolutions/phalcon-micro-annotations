<?php
namespace Phalcon\Mvc\Router\Annotations;

/**
 * Phalcon\Mvc\Router\Annotations
 * Replacement Router for Micro app that supports route annotations
 * <code>
 * $option = (object)[
 *   "adapter" => 'stream',                 // 'apcu','stream','memory'
 *   "directory" => "app/controllers/",
 *   "namespace" => "app\controllers\\",
 *   "lifetime" => 3600,                    // Default is 6 hours
 *   "cachedirectory" => "/app/storage/cache/annotations"
 * );
 * $router = new \Phalcon\Mvc\Router\Annotations\MicroRouter($di,$option);
 * </code>.
 *
 * @property stdClass $options
 * @property Mixed $adapter
 * @property array $collections
 * @property \Phalcon\Logger\Logger $logger
 */
class MicroRouter extends \Phalcon\Mvc\Router
{
    protected $options;
    protected $adapter;
    protected $collections = array();
    protected $logger;

    /**
     * Creates new MicroRouter
     * @param \Phalcon\Di\Di $di
     * @param \stdClass $options
     */
    public function __construct(\Phalcon\Di\Di $di, \stdClass $options)
    {
        parent::__construct(true);
        $this->removeExtraSlashes(true);
        $this->setDI($di);
        $this->logger = $this->getDI()->get('logger');
        $this->options = $this->defaultOptions($options);
        switch ($this->options->adapter) {
            case "apcu":
                $this->adapter = new \Phalcon\Annotations\Adapter\Apcu([
                    'prefix' => '_micro-router_',
                    'lifetime' => $this->options->lifetime,
                ]);
                break;
            case "stream":
                $this->adapter = new \Phalcon\Annotations\Adapter\Stream([
                    'annotationsDir' => $this->options->cachedirectory
                ]);
                break;
            default:
                $this->adapter = new \Phalcon\Annotations\Adapter\Memory();
        }
        $this->setRoutes();
    }

    /**
     * Populated dedault options overriding with provided
     * @param \stdClass $options
     * @return \stdClass
     */
    protected function defaultOptions(\stdClass $options): \stdClass
    {
        $opt = (object) [
            "adapter" => "apcu",
            "directory" => "app/controllers/",
            "namespace" => "App\Controllers",
            "lifetime" => 21600000,
            "cachedirectory" => "/app/storage/cache/annotations/"
        ];

        $obj = (object) array_merge((array) $opt, (array) $options);
        if ($obj->adapter == 'apcu' && !(function_exists('apcu_enabled') && apcu_enabled()))
            $obj->adapter = "stream";
        if ($obj->adapter == 'stream' && !is_dir($obj->cachedirectory))
            $obj->adapter = "memory";

        $this->setDefaults(
            array(
                'namespace' => $obj->namespace,
                'controller' => 'index',
                'action' => 'index'
            )
        );

        return $obj;
    }

    /**
     * Primary Method to populate routes
     * @return void
     */
    protected function setRoutes(): void
    {
        $classes = [];
        switch ($this->options->adapter) {
            case "apcu":
                $iterator = new \APCuIterator("/^_phan/");

                if (true === is_object($iterator)) {
                    $this->logger->debug('Iterating APCu');
                    foreach ($iterator as $item) {
                        $className = end(explode('\\', $item["key"]));
                        $reflector = str_replace('_phan_micro-router_', '', $item["key"]);
                        $classes[$className] = $reflector;
                    }
                }
                break;
            case "stream":
                $this->logger->debug('Iterating Cache Files');
                foreach (preg_grep('~\.(php)$~', scandir($this->options->cachedirectory)) as $cache_file) {
                    $className = str_replace('.php', '', end(explode('_', $cache_file)));
                    $reflector = str_replace('_', '\\', str_replace('.php', '', $cache_file));
                    $classes[$className] = $reflector;
                }
                break;
        }

        if (empty($classes)) {
            $this->logger->debug('Classes Empty, Parsing Files');
            foreach (preg_grep('~\.(php)$~', scandir($this->options->directory)) as $controller_file) {
                $className = str_replace('.php', '', $controller_file);
                $reflector = $this->options->namespace . '\\' . $className;
                $classes[$className] = $reflector;
            }
        }

        foreach ($classes as $className => $reflector) {
            $this->processReflector($className, $this->adapter->get($reflector));
        }
    }

    /**
     * Clears Cache in the event of a need to rebuilt routes
     * @throws \Exception 
     * @return bool
     */
    public function clearCache(): bool
    {
        try {
            switch ($this->options->adapter) {
                case "apcu":
                    $iterator = new \APCuIterator("/^_phan/");

                    if (false === is_object($iterator)) {
                        throw new \Exception("APCu Object not defined", 500);
                    }

                    foreach ($iterator as $item) {
                        if (true !== apcu_delete($item["key"])) {
                            throw new \Exception(sprintf("Unable to Delete '%s'", $item["key"]), 500);
                        }
                    }

                    if ($this->logger) {
                        $this->logger->notice(sprintf("APCu Cache Cleared for '/^_phan/'", ));
                    }
                    break;
                case "stream":
                    foreach (preg_grep('~\.(php)$~', scandir($this->options->cachedirectory)) as $cache_file) {
                        $delfile = $this->options->cachedirectory . $cache_file;
                        if (is_file($delfile)) {
                            unlink($delfile);
                        }
                    }
                    if ($this->logger) {
                        $this->logger->notice(sprintf("Files Cleared From: '%s'", $this->options->cachedirectory));
                    }
                    break;
                default:
                    break;
            }
            return true;
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error(sprintf('Error encountered clearing Cache: %s', $e->getMessage()));
            }
        } catch (\Error $e) {
            if ($this->logger) {
                $this->logger->error(sprintf('Error encountered clearing Cache: %s', $e->getMessage()));
            }
        }
        return false;
    }

    /**
     * Parses Routes to mount into provided Micro App
     * @param \Phalcon\Mvc\Micro $micro
     * @return void
     */
    public function mountMicro(\Phalcon\Mvc\Micro $micro): void
    {
        foreach ($this->collections as $key => $col) {
            $micro->mount($col);
        }
    }

    /**
     * Processes Reflector Class to identify Annotations
     * @param string $className
     * @param \Phalcon\Annotations\Reflection|null $reflection
     * @return void
     */
    protected function processReflector(string $className, \Phalcon\Annotations\Reflection $reflection = null)
    {
        if (!$reflection)
            return;

        $prefix = $this->processClassAnnotations($className, $reflection->getClassAnnotations());
        $this->processMethodAnnotations($className, $prefix, $reflection->getMethodsAnnotations());
    }

    /**
     * Process Annotations for Class header
     * @param string $className
     * @param \Phalcon\Annotations\Collection|null $collection
     * @return string
     */
    protected function processClassAnnotations(string $className, \Phalcon\Annotations\Collection $collection = null): string
    {
        if (!$collection)
            return '';

        $add_defaults = false;
        $prefix = '';
        foreach ($collection as $annotation) {
            // check if we have an annotation that we care about
            switch ($annotation->getName()) {
                case 'RoutePrefix':
                    $args = $annotation->getArguments();

                    // we need one argument, the prefix!
                    if (count($args) >= 1) {
                        $prefix = $args[0];
                    }
                    break;

                case 'RouteDefault':
                    $args = $annotation->getArguments();

                    // we need one argument, the default type!
                    if (count($args) >= 1 && $args[0] == 'Rest') {
                        $add_defaults = true;
                    }
            }
        }
        if ($add_defaults) {
            $this->addRoute($prefix . '/', 'get', $className, 'indexAction');
            $this->addRoute($prefix . '/{get}', 'get', $className, 'getAction');
            $this->addRoute($prefix . '/{id}', 'put', $className, 'putAction');
            $this->addRoute($prefix . '/', 'post', $className, 'postAction');
            $this->addRoute($prefix . '/{id}', 'delete', $className, 'deleteAction');
        }
        return $prefix;
    }

    /**
     * Process Annotations for Class Methods
     * @param string $className
     * @param string $prefix
     * @param array|null $collection
     * @throws \Phalcon\Exception 
     * @return void
     */
    protected function processMethodAnnotations(string $className, string $prefix, array $collection = null): void
    {
        if (!$collection)
            return;

        //$this->logger->debug(sprintf("processMethodAnnotations for: '%s' with prefix: '%s'", $className, $prefix));
        foreach ($collection as $function => $annotations) {
            // loop the annotations for the current function
            foreach ($annotations as $ann) {
                $args = $ann->getArguments();
                $name = null;

                // handle the annotations we care about
                switch ($ann->getName()) {
                    case 'Get':
                    case 'Post':
                    case 'Put':
                    case 'Delete':
                        if (count($args) == 2) {
                            $name = $args[1];
                        } elseif (count($args) != 1) {
                            throw new \Phalcon\Exception('Invalid argument count for ' .
                                $className . '::' . $function . '() / @' . $ann->getName());
                        }
                        $this->addRoute($prefix . $args[0], $ann->getName(), $className, $function, $name);
                        break;
                    case 'Route':
                        if ($ann->getNamedArgument('methods')) {
                            $httpMethods = $ann->getNamedArgument('methods');
                        } else {
                            $httpMethods = array('GET', 'PUT', 'POST', 'DELETE');
                        }
                        if ($ann->getNamedArgument('name')) {
                            $name = $ann->getNamedArgument('name');
                        }

                        if (count($args) < 1) {
                            throw new \Phalcon\Exception('Invalid argument count for ' .
                                $className . '::' . $function . '() / @' . $ann->getName());
                        }

                        foreach ($httpMethods as $request) {
                            $this->addRoute($prefix . $args[0], $request, $className, $function, $name);
                        }
                        break;
                }
            }
        }
    }

    /**
     * Create or append the method, uri, and http method to the className
     *
     * @param $route
     * @param $request HTTP Request types allowed
     * @param $class Name of controller class for route
     * @param $method Name of class method for action
     * @param $name DEFAULT NULL for named route
     */
    private function addRoute($route, $request, $class, $method, $name = null)
    {
        if (!isset($this->collections[$class])) {
            $handler = $this->options->namespace . '\\' . $class;
            $this->collections[$class] = new \Phalcon\Mvc\Micro\Collection();
            $this->collections[$class]->setHandler($handler)->setLazy(true);
        }

        // always remove trailing slash
        while (strlen($route) > 0 && substr($route, -1) == '/') {
            $route = substr($route, 0, -1);
        }

        $verb = strtolower($request);
        $this->collections[$class]->$verb($route, $method, $name);
    }
}
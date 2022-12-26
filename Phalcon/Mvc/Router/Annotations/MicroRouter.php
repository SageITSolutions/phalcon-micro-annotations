<?php
namespace Phalcon\Mvc\Router\Annotations;

/**
 * Phalcon\Db\Adapter\Pdo\Sqlsrv
 * Specific functions for the MsSQL database system
 * <code>
 * $config = array(
 * "host" => "192.168.0.11",
 * "dbname" => "blog",
 * "port" => 3306,
 * "username" => "sigma",
 * "password" => "secret"
 * );
 * $connection = new \Phalcon\Db\Adapter\Pdo\Sqlsrv($config);
 * </code>.
 *
 * @property \Phalcon\Db\Dialect\Sqlsrv $_dialect
 */
class MicroRouter extends \Phalcon\Di\Injectable
{
    protected $options;
    protected $adapter;

    public function __construct(\stdClass $options)
    {
        $this->options = $this->defaultOptions($options);
        switch ($this->options->adapter) {
            case "apcu":
                $adapter = new \Phalcon\Annotations\Adapter\Apcu([
                    'prefix' => 'micro_routes',
                    'lifetime' => $this->options->lifetime,
                ]);
                break;
            case "stream":
                $adapter = new \Phalcon\Annotations\Adapter\Stream([
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
    private function defaultOptions(\stdClass $options): \stdClass
    {
        $opt = (object) [
            "adapter" => (apcu_enabled() ? 'apcu' : 'stream'),
            "directory" => "app/controllers/",
            "namespace" => "app\controllers\\",
            "lifetime" => 21600000,
            "cachedirectory" => "/app/storage/cache/annotations"
        ];

        $obj = (object) array_merge((array) $opt, (array) $options);
        if ($obj->adapter == 'apcu' && !apcu_enabled())
            $obj->adapter = "stream";
        if ($obj->adapter == 'stream' && !is_dir($obj->cachedirectory))
            $obj->adapter = "memory";

        return $obj;
    }

    protected function setRoutes(): void
    {
        $adapter = new \Phalcon\Annotations\Adapter\Memory();
        foreach (preg_grep('~\.(php)$~', scandir(BASE_PATH . 'app/controllers/')) as $controller_file) {
            $className = str_replace('.php', '', $controller_file);
            $this->processReflector($className, $adapter->get('App\Controllers\\' . $className));
        }
        foreach ($this->collections as $col) {
            $this->mount($col);
        }
    }

    private function processReflector(string $className, \Phalcon\Annotations\Reflection $reflection = null)
    {
        if (!$reflection)
            return;
        $prefix = $this->processClassAnnotations($className, $reflection->getClassAnnotations());
        $this->processMethodAnnotations($className, $prefix, $reflection->getMethodsAnnotations());
    }

    private function processClassAnnotations(string $className, \Phalcon\Annotations\Collection $collection = null): string
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

    private function processMethodAnnotations(string $className, string $prefix, array $collection = null): void
    {
        if (!$collection)
            return;

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
            $this->collections[$class] = new \Phalcon\Mvc\Micro\Collection();
            $this->collections[$class]->setHandler('App\Controllers\\' . $class)->setLazy(true);
        }

        // always remove trailing slash
        while (strlen($route) > 0 && substr($route, -1) == '/') {
            $route = substr($route, 0, -1);
        }

        $verb = strtolower($request);
        $this->collections[$class]->$verb($route, $method, $name);
    }
    public function run()
    {
        $this->handle(str_replace($this->base_uri, '/', $_SERVER["REQUEST_URI"]));
    }
}
<?php
namespace App\Library\Security;

use \Phalcon\Mvc\Dispatcher;
use \Phalcon\Events\Event;
// use \Phalcon\Acl;
use \Phalcon\Acl\Enum;
use \Phalcon\Acl\Role;
use \Phalcon\Acl\Component;

class ACL extends \Phalcon\Di\Injectable{
    /**
     * Override Parent to populate filter
     */
    public function __construct(){
        if(!$this->cache->has('routes')){
            $routes = $this->findRoutes();
            $this->cache->set('routes', (array)$routes);
            $this->saveRoutes($routes);
        }
    }

    /**
     * Cached ACL
     *
     * @return Memory ACL
     */
    protected function _getAcl(){
        if (!isset($this->persistent->acl)) {
            $acl = new \Phalcon\Acl\Adapter\Memory();
            $acl->setDefaultAction(Enum::DENY);

            foreach(\App\Models\Role::find() as $role){
                $acl->addRole(new Role($role->role, $role->description));
            }

            foreach(\App\Models\Component::find() as $component){
                if ($component->component != '*') {
                    $actions = [];
                    foreach($component->resource as $resource){
                        $actions[] = $resource->resource;
                    }
                    if(count($actions) == 0) {
                        $actions[] = '*';
                    }
                    $acl->addComponent(new Component($component->component), $actions);
                }
            }
            
            foreach(\App\Models\Acl::find() as $AccessControl){
                $acl->allow($AccessControl->role->role, $AccessControl->component->component, $AccessControl->resource->resource);
            }

            $this->persistent->acl = $acl;
        }
        return $this->persistent->acl;
    }

    /**
     * Cached Roles Array
     *
     * @return Array of Roles assigned to current user
     */
    protected function _getRoles(){
        if ($this->session->has('loginUser')) return $this->session->loginUser->roles;
        return [
            \App\Models\Role::findFirst('role="default"')
        ];
    }
    
    /**
     * Evaluates all assigned User Roles to determine permissions
     *
     * @param string $controller
     * @param string $action
     * @return boolean
     */
    public function hasAccess($controller, $action){
        $acl = $this->_getAcl();
        $roles = $this->_getRoles();
        $allowed = Enum::DENY;
        foreach($roles as $role){
            $allowed = $acl->isAllowed($role->role, $controller, $action);
            if($allowed == Enum::ALLOW) break;
        }
        return $allowed;
    }

    /**
     * Returns boolean if current user has SUPER permissions
     *
     * @return boolean
     */
    public function isSuper(){
        $roles = $this->_getRoles();
        foreach($roles as $role){
            if($role->role == 'Super') return true;
        }
        return false;
    }

    /**
     * Parse individual Annotation into Route
     *
     * @param String $class
     * @param String $method
     * @param String $prefix
     * @param Array $annotations
     * @return object
     */
    protected function parseAnnotation($class, $method, $prefix, $annotations){
        $verb = null;
        $route = null;
        $alias = null;

        if($annotations){
            foreach($annotations as $annotation){
                $verb = $annotation->getName();
                switch ($verb){
                    case 'Get':
                    case 'Post':
                    case 'Put':
                    case 'Delete':
                        $args = $annotation->getArguments();
                        if(count($args) == 2){
                            $alias = $args[1];
                        }
                        elseif (count($args) != 1) {
                            throw new \Phalcon\Exception('Invalid argument count for ' . $class . '::' . $method . '() / @' . $verb);
                        }
                        $route = $prefix . $args[0];
                        return (object)[
                            "Method"        => $method,
                            "verb"          => $verb,
                            "route"         => $route,
                            "alias"         => $alias
                        ];
                }
            }
        }
        $resource = strtolower(str_replace("Action","",$method));
        if(in_array($resource,['index','get','put','post','delete'])){
            switch ($resource){
                case 'index':
                    $verb = "get";
                    $route = $prefix . "/";
                    break;
                case 'get':
                    $verb = "get";
                    $route = $prefix . "/{id}";
                    break;
                case 'put':
                    $verb = "put";
                    $route = $prefix . "/{id}";
                    break;
                case 'post':
                    $verb = "post";
                    $route = $prefix . "/";
                    break;
                case 'delete':
                    $verb = "delete";
                    $route = $prefix . "/{id}";
                    break;
            }
        }
        
        return (object)[
            "Method"        => $method,
            "verb"          => $verb,
            "route"         => $route,
            "alias"         => $alias
        ];
        
    }

    /**
     * Search Controller Directory for all valid Routes
     *
     * @return void
     */
    protected function findRoutes(){
        $directory  = BASE_PATH . $this->config->application->controllersDir;
        $ns         = $this->config->application->controllersNs;
        $routes     = (object)[
            "controllers" => [],
            "collections"   => []
        ];

        if (is_dir($directory)) {
            foreach (preg_filter('/(.*Controller).php/',"\\\\$ns\\\\$1", scandir($directory)) as $className) {
                $classShort         = strtolower(str_replace(["\\$ns\\",'Controller'],"",$className));
                $methods            = get_class_methods($className);
                $reflector          = $this->annotations->get($className);
                $class_annotations  = $reflector->getClassAnnotations();
                $meth_annotations   = $reflector->getMethodsAnnotations();
                $Prefix             = "";
                $Description        = "";
                
                
                if($class_annotations && $class_annotations->has('RoutePrefix')) {
                    $args = $class_annotations->get('RoutePrefix')->getArguments();
                    if (count($args) >= 1) $Prefix = $args[0];
                }
                
                if($class_annotations && $class_annotations->has('Description')) {
                    $args = $class_annotations->get('Description')->getArguments();
                    if (count($args) >= 1) $Description = $args[0];
                }
                
                $routes->controllers[$classShort] = (object)[
                    "Class"         => $classShort."Controller",
                    "FQN"           => $className,
                    "Resources"     => [],
                    "Annotations"   => $meth_annotations,
                    "Prefix"        => $Prefix,
                    "Description"   => $Description
                ];

                foreach(preg_filter('/(\w+)(Action)/', '$1', get_class_methods($className)) as $resource){
                    $method = $resource.'Action';
                    $processAnnotations = null;
                    if($meth_annotations && @$meth_annotations[$method]) $processAnnotations = $meth_annotations[$method];

                    $routes->controllers[$classShort]->Resources[$resource] = $this->parseAnnotation($className,$method,$Prefix,$processAnnotations);
                }
                
            }
        }  
        return $routes;
    }

    /**
     * Process identified routes and save to DB
     *
     * @param array $routes
     * @return void
     */
    protected function saveRoutes($routes){
        $workload = (object)[
            "processed" => [],
            "updated"   => [],
            "removed"   => [],
            "added"     => []
        ];

        /**
         * Process existing DB routes
         * Remove any existing DB routes that have no associated Controller/Method
         * Update any existing DB routes previously set
         * Populate workload object for next step processing
         */
        foreach(\App\Models\Component::find(["order" => "component ASC"]) as $component){
            $remove_controller = false;
            $reactivate_controller = false;
            $search_criteria = [
                'conditions' => 'component_id = :component_id:',
                'bind'       => [
                    'component_id' => $component->component_id,
                ],
                "order" => "resource ASC"
            ];

            //Remove
            if(!array_key_exists($component->component,$routes->controllers) && $component->component != '*'){
                $component->removed = $this->tools::getDate();
                $component->save(); 
                $workload->removed[$component->component] = [];
                $remove_controller = true;
            }
            //Update
            else {
                if($component->removed && $component->removed != '0000-00-00 00:00:00') $component->removed = null;
                $component->description = $routes->controllers[$component->component]->Description;
                if($component->save()){
                    $workload->updated[$component->component] = [];
                    $reactivate_controller = true;
                }
            }
            //Flag as Processed
            $workload->processed[$component->component] = []; 
            
            foreach(\App\Models\Resource::find($search_criteria) as $resource){
                //Remove
                if($remove_controller || (@!array_key_exists($resource->resource,$routes->controllers[$component->component]->Resources) && $resource->resource != '*')){ 
                    $resource->removed = $this->tools::getDate();
                    $resource->save();
                    $this->tools::default($workload->removed[$component->component],[]);
                    $this->tools::appendArray($workload->removed[$component->component], $resource->resource);
                }
                //Update
                else {  
                    if($resource->removed && $resource->removed != '0000-00-00 00:00:00') $resource->removed = null;
                    if($resource->resource != '*'){
                        $resourceObj    = $routes->controllers[$component->component]->Resources[$resource->resource];
                        $resource->api  = ($resourceObj->verb? $resourceObj->alias ?? $resource->resource : null);
                    }
                    if($resource->save()){
                        $this->tools::default($workload->updated[$component->component],[]);
                        $this->tools::appendArray($workload->updated[$component->component], $resource->resource);
                    }
                }
                //Flag as Processed
                $this->tools::appendArray($workload->processed[$component->component], $resource->resource);
            }
        }

        /**
         * Loop through existing Controllers to compare against already processed DB values
         * Identify any non-processed Controller/Method routes and add them
         */
        foreach($routes->controllers as $name => $controller){
            $Component = \App\Models\Component::findFirst("component = '$name'");
            // Controller doesn't exist
            if(!$Component) {
                $Component = new \App\Models\Component();
                $Component->component   = $name;
                $component->description = $controller->Description;
                $Component->removed     = null;
                $Component->save();

                // Add Global Resource (*)
                $globalResource = new \App\Models\Resource();
                $globalResource->component_id = $Component->component_id;
                $globalResource->resource = '*';
                $globalResource->removed = null;
                $globalResource->save();
                $workload->added[$name] = [];
                $workload->processed[$name] = [];
            }
            //$Component = \App\Models\Component::findFirst("component = '$name'");
            foreach($controller->Resources as $resource_name => $resource){
                $Resource = \App\Models\Resource::findFirst("resource = '$resource_name' AND component_id = $Component->component_id");
                //Method does not exist
                if(!$Resource) {
                    $Resource = new \App\Models\Resource();
                    $Resource->component_id = $Component->component_id;
                    $Resource->resource     = $resource_name;
                    $Resource->api          = ($resource->verb? $resource->alias ?? $resource_name : null);
                    $Resource->removed      = null;
                    $Resource->save();

                    $this->tools::default($workload->added[$name],[]);
                    $this->tools::default($workload->processed[$name],[]);
                    $this->tools::appendArray($workload->added[$name], $resource_name);
                    $this->tools::appendArray($workload->processed[$name], $resource_name);
                }
                unset($Resource); //Ensure next iteration does not use this one
            }
            unset($Component); //Ensure next iteration does not use this one
        }
    }

    public function loadResources(){
        $arrResources = [
            'Guest' => [ // ROLE
                'Index' => ['login'], // IndexController => [alias from Collection]
            ],
            'User' => [
                'Profile' => ['index', 'update', 'changePassword'],
                'Users' => ['index', 'create', 'get', 'search', 'update', 'logout'],
                'Cities' => ['index', 'create', 'get', 'ajax', 'update', 'delete'],
            ],
            'Superuser' => [
                'Users' => ['changePassword', 'delete'],
            ],
        ];

        $config = $this->config;
        $directory = BASE_PATH . $config->application->controllersDir;
        if (is_dir($directory)) {
            foreach (scandir($directory) as $file) {
                if(preg_match("/Controller.php$/i", $file) && !in_array($file, ['AppCronController.php','BaseController.php','PageController.php','SingleController.php'])) {
                    $className = '\App\Controllers\\'.str_replace('.php','',$file);
                    $classes =  get_class_methods($className);
                    $resources = preg_filter('/(\w+)(Action)/', '$1', $classes);
                    $controller = strtolower(str_replace('Controller.php','',$file));
                    $Component = \App\Models\Component::findFirst("component = '$controller'");
                    if(!$Component) {
                        $Component = new \App\Models\Component();
                        $Component->component = $controller;
                        $Component->save();

                        // Add Global Resource (*)
                        $globalResource = new \App\Models\Resource();
                        $globalResource->component_id = $Component->component_id;
                        $globalResource->resource = '*';
                        $globalResource->save();
                    }
                    foreach($resources as $resource_name){
                        $Resource = \App\Models\Resource::findFirst("resource = '$resource_name' AND component_id = $Component->component_id");
                        if(!$Resource) {
                            $Resource = new \App\Models\Resource();
                            $Resource->component_id = $Component->component_id;
                            $Resource->resource = $resource_name;
                            $Resource->save();
                        }
                        unset($Resource);
                    }
                    unset($Component);
                }
            }
        }    
    }
}
?>
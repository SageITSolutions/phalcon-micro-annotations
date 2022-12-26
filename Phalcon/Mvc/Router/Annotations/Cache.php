<?php
namespace App\Service;

class Cache implements \Phalcon\Di\ServiceProviderInterface{

    public function register(\Phalcon\Di\DiInterface $di): void{
        $di->setShared('cache',
            function () {
                $serializerFactory = new \Phalcon\Storage\SerializerFactory();
                $adapterFactory    = new \Phalcon\Cache\AdapterFactory($serializerFactory);
                $jsonSerializer    = new \Phalcon\Storage\Serializer\Json();
                // return $adapterFactory->newInstance('apcu',[
                //     'defaultSerializer' => 'Json',
                //     'lifetime'          => 86400,
                //     'prefix'            => 'app_cache',
                //     'serializer'        => $jsonSerializer
                // ]);
                return new \Phalcon\Cache\Adapter\Memory($serializerFactory, [
                    'defaultSerializer' => 'Json',
                    'lifetime'          => 86400,
                    'prefix'            => 'app_cache',
                    'serializer'        => $jsonSerializer
                ]);
            }
        );
    }
}
?>
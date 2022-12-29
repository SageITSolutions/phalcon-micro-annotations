<div align="center">
  <!-- PROJECT LOGO -->
  <a href="https://github.com/SageITSolutions/phalcon-micro-annotations">
    <img src="images/logo.png" alt="Logo" width="445" height="120">
  </a>

  <h1 align="center">Phalcon Micro-Annotation</h1>

  [![Latest Stable Version](http://poser.pugx.org/sageit/phalcon-micro-annotations/v?style=plastic)](https://packagist.org/packages/sageit/phalcon-micro-annotations) 
  [![Total Downloads](http://poser.pugx.org/sageit/phalcon-micro-annotations/downloads?style=plastic)](https://packagist.org/packages/sageit/phalcon-micro-annotations) 
  [![License](http://poser.pugx.org/sageit/phalcon-micro-annotations/license?style=plastic)](https://packagist.org/packages/sageit/phalcon-micro-annotations)
  [![PHP Version Require](http://poser.pugx.org/sageit/phalcon-micro-annotations/require/php?style=plastic)](https://packagist.org/packages/sageit/phalcon-micro-annotations)
  [![Phalcon Version](https://img.shields.io/packagist/dependency-v/sageit/phalcon-micro-annotations/ext-phalcon?label=Phalcon&logo=Phalcon%20Version&style=plastic)](https://packagist.org/packages/sageit/phalcon-micro-annotations)

  <p>
    Allows you to use annotations for routing on Phalcon Micro Applications. While the built in Phalcon class for an annotation based router works on full suite Phalcon apps, Micro uses a different mounting methodology and does not support this.  The Micro-Annotation Router leverages the build in Micro mounting method while allowing for annotation parsing with the caching method of your choice.
  </p>

  **[Explore the docs »](https://github.com/SageITSolutions/phalcon-micro-annotations)**

  **[Report Bug](https://github.com/SageITSolutions/phalcon-micro-annotations/issues)** ·
  **[Request Feature](https://github.com/SageITSolutions/phalcon-micro-annotations/issues)**
</div>

<!-- TABLE OF CONTENTS -->
## Table of Contents

* [About the Project](#about-the-project)
* [Installation](#installation)
* [Usage](#usage)
* [Caching](#caching)
* [Roadmap](#roadmap)
* [Contributing](#contributing)
* [License](#license)
* [Contact](#contact)
* [Acknowledgements](#acknowledgements)


<br />

<!-- ABOUT THE PROJECT -->
## About The Project

### Built With

* [vscode](https://code.visualstudio.com/)
* [php 8.1.1](https://www.php.net/releases/8_1_1.php)
* [Phalcon 5](https://phalcon.io/en-us) (Micro Framework)

<br />

<!-- GETTING STARTED -->
## Installation

**Git:**
```sh
git clone https://github.com/SageITSolutions/phalcon-micro-annotations.git
```

**Composer:**
```sh
composer require sageit/phalcon-micro-annotations
```
<br />

<!-- USAGE EXAMPLES -->
## Usage

This project consists of an included Router class extension which follows closely to the Phalcon 5 Namespace convention.  Once a service is added in the micro app, this can easily be leveraged.

### Create a Service (Example)

```php
namespace App\Service;

class Router implements \Phalcon\Di\ServiceProviderInterface
{

    public function register(\Phalcon\Di\DiInterface $di): void
    {
        $di->setShared(
            'router',
            function () use ($di) {
                return new \Phalcon\Mvc\Router\Annotations\MicroRouter($di, (object) [
                    "adapter" => "apcu",
                    "directory" => "app/controllers/",
                    "namespace" => "App\Controllers",
                    "lifetime" => 21600000,
                    "cachedirectory" => "/app/storage/cache/annotations/"
                ]);
            }
        );
    }
}
```
When creating the service, MicroRouter requires 2 parameters.
1. The Dependancy Injector the service is added to.
2. An Object containing optional settings.

_The provided example demonstrates the default values assumed if the option is ommited._


### Mount Micro to the service
From within the Micro class call:

```php
$this->getDI()->get('router')->mountMicro($this);
```
_this will pass the micro app (`$this`) to the parsor and call the micro mount method using the parsed annotions_

<br />

<!-- Caching -->
## Caching
This class supports safeguarded Caching.  You can choose between `APCu`, `Stream`, or `Memory` (Not Caching) when creating the router service to optimize usage.

### Specifying Cache Method
Within the Options array passed to the MicroRouter, specify the adapter of choice.

**APCu**
```php
"adapter" => "apcu",
"lifetime" => 21600000,
```

**APCu**
```php
"adapter" => "stream",
"cachedirectory" => "/app/storage/cache/annotations/"
```

**Memory**
```php
"adapter" => "memory"
```
_technically, `Memory` is the fallback when all others fail, so any value not APCU or Stream would resolve to memory_

### Safeguards
Once specified, the class checks for the presence of the required components before using a caching method.  This works in a tiered manner.

1. `APCu` is specified, checks are made if APCu is installed and enabled, if not the adapter will revert to Stream
2. `Stream` is specified (_or APCu specification failed_), checks are made that the directory specified for `cachedirectory` exists, if not adapter will revert to Memory
3. `Memory` is specified (_or result of reverting_), no checks are required, all parsing is done in memory each time.

### Parsing
When caching methods are enabled, they are processed first before iterating files producing I/O calls.  If annotations are present, no files are parsed, and routes are generated from memory.  When no annotations are present (_first call or after **clearing cache**_) then Controller files are parsed from the provided `directory` and annotations are added.
### Clearing Cache
A public utility method is included to facilitate the need to rebuild the cache either in testing or publishing scenarios. Clearing Cache is dependant on the adapter specified.

```php
clearCache()
```
_From within the micro class_
```php
$this->getDI()->get('router')->clearCache();
```
This will remove all APCu entries with the prefix `_phan` or removing all files in the `cachedirectory` depending on the adaptor type.

_if the dependancy injector identifies as `logger` service, the notices are logged indicating the cleared cache_

<br />

<!-- ROADMAP -->
## Roadmap

See the [open issues](/issues) for a list of proposed features (and known issues).

<br />

<!-- CONTRIBUTING -->
## Contributing

Contributions are what make the open source community such an amazing place to be learn, inspire, and create. Any contributions you make are **greatly appreciated**.

1. Fork the Project
2. Create your Feature Branch (`git checkout -b feature/AmazingFeature`)
3. Commit your Changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the Branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

<br />

<!-- LICENSE -->
## License

Distributed under the MIT License. See `LICENSE` for more information.

<br />

<!-- CONTACT -->
## Contact

Sage IT Solutions - [Email](mailto:daniel.davis@sageitsolutions.net)

Project Link: [https://github.com/SageITSolutions/phalcon-micro-annotations](https://github.com/SageITSolutions/phalcon-micro-annotations)

<br />

<!-- ACKNOWLEDGEMENTS -->
## Acknowledgements

* [OakBehringer](https://github.com/OakBehringer/phalcon-micro-route-annotations)

# Throttler

## Introduction

Phalcon Throttler is a Rate Limiter for PHP Phalcon Framework.

It provides a simple interface to build Rate Limiters using various strategies as well as with a Redis Throttler ready out of the box.

PHP 7.1+ and Phalcon 3.1.2+ are required.

## Installation
 
Throttler can be installed through Composer, just include `"oaklabs/phalcon-throttler": "^0.1"` to your composer.json and run `composer update` or `composer install`.

## Usage

### Throttling

Phalcon Throttler comes shipped with a Redis throttler by default. 
It uses [PhpRedis](https://github.com/phpredis/phpredis) to communicate with the Redis server.

First of all we need a `Redis` instance. 
We can then add a `redis` service in the Phalcon Dependency Injection container

```php
$di->setShared('redis', function () use ($config) {
    $redis = new \Redis();
    $redis->pconnect($config->redis->host, $config->redis->port);
    $redis->auth($config->redis->password);

    return $redis;
});
``` 

so that it can be used when we want to create an instance of the Redis Throttler.
We can set it up in the Dependency Injection container as well

```php
$di->setShared('throttler',function() use ($di) {
    return new OakLabs\PhalconThrottler($di->get('redis'), [
        'bucket_size'  => 20,
        'refill_time'  => 600, // 10m
        'refill_amount'  => 10
    ]);
});
```

The second parameter allows to configure the behaviour of the Throttler:

- *bucket_size*: the number of allowed hits in the period of time of reference
- *refill_time*: the amount of time after that the counter will completely or partially reset
- *refill_amount*: the number of hits to be reset every time the refill_time passes 

You are now able to successfully throttle users:

```php
$throttler = $this->getDI()->get('throttler');
$rateLimit = $throttler->consume($this->request->getClientAddress());

if ($rateLimit->isLimited()) {
    // Do something
}
```

### Strategies

The only question that remains is: which one is the appropriate place where the check should be performed?

There is of course not an uniquely valid answer, several places can be used. 

**Check in the dispatcher**

A good strategy is to put the check during the Phalcon dispatcher lifecycle.

In the depencency injection we can use the Phalcon Event Manager to listen to the dispatcher event and bind it to some Security plugin

```php
$di->setShared('eventsManager',function() use ($di) {
    $eventsManager = new \Phalcon\Events\Manager();
    return $eventsManager;
});

$di->set('dispatcher', function () use ($di) {
    //Create an EventsManager
    $eventsManager = $di->getShared('eventsManager');

    $security = new \MyNamespace\Security();
    $eventsManager->attach('dispatch', $security);

    $dispatcher = new \Phalcon\Mvc\Dispatcher();
    $dispatcher->setEventsManager($eventsManager);

    return $dispatcher;
});
```

and put our Rate Limiter in it

```php
<?php

namespace MyNamespace;

use Phalcon\Events\Event;
use Phalcon\Mvc\User\Plugin;
use Phalcon\Mvc\Dispatcher;
use OakLabs\PhalconThrottler\ThrottlerInterface;

class Security extends Plugin
{
    public function beforeDispatch(Event $event, Dispatcher $dispatcher)
    {
        /** @var ThrottlerInterface $throttler */
        $throttler = $this->getDI()->get('throttler');
        $rateLimit = $throttler->consume($this->request->getClientAddress());

        if ($rateLimit->isLimited()) {
            $dispatcher->forward(
                [
                    'namespace' => 'MyNamespace\Http',
                    'controller' => 'error',
                    'action' => 'ratelimited',
                    'params' => $rateLimit->toArray()
                ]
            );
        }
    }
}
```

and finally perform a redirection in case the User gets rate limited.
The information returned by the `$rateLimit->toArray()` method contains:

```php
[
    'hits' => (int) // Number of hits in the reference period,
    'remaining' =>(int) // Remaining hits before getting rate limited,
    'period' => (int) // Reference period in seconds,
    'hits_per_period' => (int) // Allower number of hits allowed in the reference period,
    'warning' => (bool) // Whether a warning is emitted,
    'limited' => (bool) // Whether the User is rate limited
]
```

## Contribution guidelines

Throttler follows PSR-1, PSR-2 and PSR-4 PHP coding standards, and semantic versioning.

Pull requests are welcome.

## License

Throttler is free software distributed under the terms of the MIT license.

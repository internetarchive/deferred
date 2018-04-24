# Deferred: Redis promises and futures for Predis / PHP

* Jim Nelson <jnelson@archive.org>
* Internet Archive
* Presented at RedisConf 2018

## Introduction

Deferred is a promises/futures PHP library for use with [Redis](https://redis.io) (via the [Predis client library](https://github.com/nrk/predis/)).  Deferred was presented at RedisConf 2018 in San Francisco.

Predis supports multiple methods of scheduling operations so they may be issued to the server in batches: pipelines, transactions, and atomic pipelines.  Deferred builds on top of these methods by binding operation results to Future objects which may be monitored by the client code.

Deferred has been tested on PHP 7 and Predis v.1.1.  It should work with older versions of PHP (with some caveats) and recent versions of Predis.

## License

Deferred is licensed under the [GNU Affero General Public License](https://www.gnu.org/licenses/agpl.html|).  See the LICENSE file for more information.

## Concepts

There are several definitions floating around for promises, futures, and deferred programming.  For simplicity, these terms are used in this manner within the Deferred library:

* __Promises__ are objects that schedule one or more commands to be executed on a Redis server
* __Futures__ are objects bound to Redis commands, one Future per scheduled command
* __Fulfillment__ is when a Promise notifies its Future(s) of their result from their associated commands.  The result may be any valid Redis value, including NULL, an array, or simply OK (indicating success).

A Deferred Promise returns a Future object for each scheduled command.  The caller does not have to maintain a reference to these objects, Promises does so internally.

It's only possible for the caller to retrieve a Future's result value after the Promises object has been executed.  A Promises object may only be executed once.

Once fulfilled, a Future is read-only and immutable.

## Coding

### Basics

Standard Predis pipelines are coded something like this:

```php
$redis = new \Predis\Client();
$pipe = $redis->pipeline();

// schedule HGETALL / SISMEMBER / SISMEMBER, no I/O yet
$pipe->hgetall('user:ackbar');
$pipe->sismember('brothers:of:jeff', 'ackbar');
$pipe->sismember('partners:of:jeff', 'ackbar');
$pipe->get('avatar:ackbar');

// I/O happens here
$results = $pipe->execute();

// results are stored in array ordered by command
$user_profile = $results[0];
$is_brother = $results[1];
$is_partner = $results[2];
$avatar = base64_decode($results[3]);
```

The same set of operations using Deferred promises & futures (a Deferred pipeline is created by instantiating `\Deferred\PromisesPipeline`):

```php
$redis = new \Predis\Client();
$promises = new \Deferred\PromisesPipeline($redis);

// schedule HGETALL / SISMEMBER / SISMEMBER, no I/O yet, each call returns a \Deferred\Future
$future_user_profile = $promises->hgetall('user:ackbar');
$future_is_brother = $promises->sismember('brothers:of:jeff', 'ackbar');
$future_is_partner = $promises->sismember('partners:of:jeff', 'ackbar');
$future_avatar = $promises->get('user:ackbar');

// I/O happens here
$results = $promises->execute();

// results are stored in \Deferred\Future objects
$user_profile = $future_user_profile->value();
$is_brother = $future_is_brother->value();
$is_partner = $future_is_partner->value();
$avatar = base64_decode($future_avatar->value());
```

Note that the Future object is _not_ the Redis result value but merely a container object.  Use `\Deferred\Future::value()` after invoking `\Deferred\Promises::execute()` to retrieve the Redis result.

### Transactions & atomic pipelines

Deferred offers two other types of Promises:

__PromisesTransaction__ are for Redis MULTI/EXEC transactions.  Each scheduled command requires a round-trip to the server prior to execution.

__AtomicPromisesPipeline__ are for pipelined MULTI/EXEC transactions.  All commands are scheduled locally before executing on the server.  Unlike a plain pipeline, an atomic pipeline is transactional.

Deciding which Promises object to use depends on the operations being scheduled and atomicity requirements.  See the Redis and Predis documentation for more information.

### Binding to a result

Often it's more convenient to _bind_ the Future's result directly to a PHP variable:

```php
$user_profile = null;
$promises->hgetall('user:ackbar')->bind($user_profile);

$promises->execute();
```

When the Promises object is executed, the result of the HGETALL command is stored in the `$user_profile` variable.  (In the case of HGETALL, `$user_profile` will hold a PHP array.)

Only one PHP variable may be bound to a Future.

### Transforming results

If the Redis result value needs to be converted, cast, or processed in any way, a Future may _transform_ the value:

```php
$avatar = null;
$future = $promises->get('avatar:ackbar');
$future->transform('base64_decode');
$future->bind($avatar);

$promises->execute();
```

Here `\Deferred\Future::transform()` and `Deferred\Future::bind()` are being used in conjunction.  When the avatar image is pulled from Redis, the transformation function will Base64 decode it and return the binary image.  The `$avatar` variable will receive the decoded image.

Multiple transformations may be attached to a Future.  Transformations are processed in registration order.  Here the avatar is uncomprssed and then Base64 decoded:

```php
$future = $promises->get('avatar:ackbar');
$future->transform('gzuncompress');
$future->transform('base64_decode');
```

As seen before, `bind()` and `transform()` may be used by the same future.  It does not matter which order they are executed, transformations _always_ precede bind.  The bound variable will recieve the final result after all transformations have run.

### Notifications

Similar to `bind()`, a caller may be notified when a Future receives its final result (is "fulfilled"):

```php
$future = $promises->sismeber('friends:of:jeff', 'ackbar');
$future->whenFulfilled(function ($sismember) {
  echo "Ackbar is Jeff's friend? " . ($sismember) ? 'YES' : 'NO';
});
```

Unlike `bind()`, more than one callback may be registered with `whenFulfilled()`.  Deferred makes no guarantees of the order they're executed.

### Reducing Futures

Multiple `\Deferred\Future` objects may be _reduced_ to a single Future.  This is useful for code that wants to coalesce several values into a single value:

```php
/**
 * @return \Deferred\Future UserProfile
 */
function loadUserProfile($promises, $userid)
{
  $email =  $promises->hget("user:$userid", 'email');
  $name =   $promises->get("username:$userid");
  $avatar = $promises->hget("avatar:$userid")->transform('base64_decode');

  $user_profile = $promises->reduce($email, $name, $avatar);

  // a reduced Future may be transformed and bound to like other Futures
  $user_profile->transform(function ($reduced) {
    $instance = new UserProfile($reduced[0], $reduced[1]); // email, name
    $instance->setAvatar($reduced[2]);

    return $instance;
  });

  // this returns a *Future*, not a UserProfile.  Once $promises->execute() is called, this Future
  // will hold a UserProfile.
  return $user_profile;
}
```

Here the three elements (`$email`, `$name`,  `$avatar`) are reduced to a single Future (`$user_profile`).  When fulfilled, the result of the reduced Future is an array of each individual result in index order.  (For this example, an array containing the user's email, name, and avatar.)

The reduced Future is like any other Future.  Callers can use `bind()`, `transform()`, and `whenFulfilled()`.

Here the reduced `$user_profile` Future has a transformation combining the three elements to initialize a `UserProfile` object.  In other words, the disparate data elements are reduced to a Future that produces a `UserProfile` object.

Code calling `loadUserProfile()` only needs to know that the returned `\Deferred\Future` will hold a `UserProfile` once fulfilled:

```php
$promises = new \Deferred\PromisesTransaction($redis);

$user_profile_future = loadUserProfile($promises, 'ackbar');

$promises->execute();

$user_profile = $user_profile_future->value();
```

## Coding practices

### Fluent interface

Most of `\Deferred\Future`'s methods return `$this`, meaning you can use Fluent-style coding:

```php
$avatar = null;
$promises->hget('avatar:ackbar')->transform('base64_decode')->bind($avatar)->whenFulfilled(function () {
  // report load event to monitoring service
  StatsD::increment('avatars-loaded');
});
```

When executed, the above operations are completed in this order:

1. transformations are performed: `base64_decode`
2. bindings are completed: `$avatar` receives the final value
3. `whenFulfilled()` callbacks are executed

`transform()`, `bind()`, and `whenFulfilled()` may be called in any order, but the above order is guaranteed when the Future is fulfilled.

### bind() versus whenFulfilled()

A caller could essentially emulate the behavior of `bind()` with `whenFulfilled()`.  Why the duplication?

`bind()` is intended as a convenience for the caller.  PHP's inline functions are awkward and verbose.  Often callers will only need the Redis value without wanting to code a lot of boilerplate to store it in a particular location.

`whenFulfilled()` is intended for more complex observer code that needs to be executed upon completion.  For example, notifications, monitoring, statistics gathering, logging, etc.

Because only one variable may be registered with `bind()`, the practice is to allow whichever code calls `\Deferred\Promises::execute()` to bind its PHP variables to the Futures.  Other intermediate code should use `whenFulfilled()` for notifications.

### Guaranteeing transactionality

Deferred makes it easy to encapsulate Redis code and isolate functionality.  However, some code may _require_ transactions (while other code may be indifferent).  Generally code is not concerned if the transaction is pipelined or not—it's a performance consideration—but often must require atomicity in order to meet contract.

Deferred offers a solution to this problem.  All three styles of promises (`PipelinePromises`, `TransactionPromises`, and `AtomicPromisesPipeline`) all descend from a common `\Deferred\Promises` abstract class.  However, only `TransactionPromises` and `AtomicPromisesPipeline` descend from the abstract `AtomicPromises` class.

`AtomicPromises` indicates transactions.  Type-checking allows for this kind of code:

```php
function mustBeTransaction(\Deferred\AtomicPromises $promises, $userid) {
  // ... do transaction ...
}

function mustAlsoBeTransaction($promises, $userid) {
  if (!is_a($promises, \Deferred\AtomicPromises::class))
    throw new \InvalidArgumentException('Must be a transaction');

  // ... do transaction ...
}
```

In both cases, the transaction code won't execute if a non-transactional Promise is passed.

### HMGET / HGETALL trick

HMGET returns an indexed array (`[ 0 => 'ackbar', 1 => 'ackbar@hell.com' ]`) while HGETALL returns an associative array keyed by hash fields (`['name' => 'ackbar', 'email' => 'ackbar@hell.com' ]`)  If you find yourself in a situation where one code path uses HMGET while the other uses HGETALL, you can normalize the results so they always look like HGETALL:

```php
/**
 * Load a portion or the entire user profile.
 *
 * @return \Deferred\Future Returns array keyed by fields [ 'name' => $name, 'email' => $email, ... ]
 */
function loadUserInfo($promises, $userid, array $fields = null)
{
  if (empty($fields)) {
    $future = $promises->hgetall("user:$userid");
  } else {
    $future = $promises->hmget("user:$userid", $fields)->transform(function ($hmget) use ($fields) {
      return array_combine($fields, $hmget);
    });
  }

  return $future;
}
```

The `array_combine()` function takes the new array's keys (`$fields`) and its values (`$hmget`) and merges them into an associative array.

## Unit tests

A minimal suite of unit tests exist in the `tests\` directory.  They require [PHP-Unit](https://phpunit.de/) to execute (which can be loaded via [Composer](https://getcomposer.org/) using the .json file in the root of the repo).

__WARNING:__  The unit tests execute by connecting to a Redis server at network address `127.0.0.1:6379` (the default configuration for Redis).  _DO __NOT__ EXECUTE THESE TESTS ON A PRODUCTION SERVER._  The tests are destructive and running them could result in data loss.

## More information

* [Futures and promises (Wikipedia)](https://en.wikipedia.org/wiki/Futures_and_promises)
* Redis [pipelining](https://redis.io/topics/pipelining) and [transactions](https://redis.io/topics/transactions)
* Predis [pipelining & atomic pipelining](http://squizzle.me/php/predis/doc/#pipelining) and [transactions](http://squizzle.me/php/predis/doc/#transactions)

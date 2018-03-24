<?php

namespace Deferred;

/**
 * Predis command scheduler based on a promises/futures abstraction.
 *
 * Like a Predis Client object, Promises can perform any Redis command (GET, HSET, ZADD, etc.) via
 * a dynamic calling interface (->get(), ->hset(), ->zadd(), etc.)  However, all commands are
 * scheduled (rather than performed immediately).  The commands only occur when the execute()
 * method is invoked.
 *
 * Unlike Predis trasactions or pipelines, Promises returns a \Deferred\Future object for each
 * scheduled command.  See documentation for Future for more information on how callers may
 * transform and receive responses once the commands are executed.
 *
 * @example
 *  $hget = $promises->hget('key');
 *  $hget->transform(function ($val) { return strrev($val); }); // reverse the value
 *  $promises->execute();
 *  $reversed = $hget->value(); // returns the reversed string
 *
 * @see \Deferred\Future
 */
class Promises
{
  private $client;
  private $futures = [];
  private $has_executed = false;
  private $responses = null;

  /**
   * Create a \Deferred\Promises object for a Predis transaction or pipeline.
   *
   * A Predis Client object can create three different types of ClientContextInterface objects:
   * - $predis->transaction()
   * - $predis->pipeline()
   * - $predis->pipeline(['atomic' => true]);
   * See the documentation for more information on each type of client.
   *
   * NOTE: Do *not* initialize Promises with a "fire-and-forget" ClientContextInterface.
   *
   * @param \Predis\ClientContextInterface $client
   * @throws \InvalidArgumentException If $client is fire-and-forget
   * @see http://squizzle.me/php/predis/doc/Classes#pipeline
   * @see http://squizzle.me/php/predis/doc/Classes#transaction-array
   */
  public function __construct(\Predis\ClientContextInterface $client)
  {
    if (is_a($client, \Predis\Pipeline\FireAndForget::class))
      throw new \InvalidArgumentException('\Deferred\Promises does not support fire-and-forget pipelines');

    $this->client = $client;
  }

  /**
   * Schedule a Redis command and generate a \Deferred\Future placeholder object.
   *
   * If the object implementing ClientContextInterface supports methods which do not schedule
   * Redis commands, they should not be invoked here, but on the object itself.  (In other words,
   * \Deferred\Promises assumes all undefined method calls to it are Redis commands.)
   *
   * @return \Deferred\Future
   */
  public function __call($method, $args)
  {
    // invoke the command on the ClientContextInterface
    call_user_func_array([ $this->client, $method ], $args);

    // store a future for the command & return that to the caller ... note that this creates a
    // cyclical reference (PHP's GC should be able to handle it)
    $future = new \Deferred\Future($this);
    $this->futures[] = $future;

    return $future;
  }

  /**
   * Determines if execute() has been invoked yet.
   *
   * @return bool
   * @see \Deferred\Promises::execute()
   */
  public function hasExecuted()
  {
    return $this->has_executed;
  }

  /**
   * Determines if execute() was able to fulfill all its \Deferred\Futures.
   *
   * @return bool
   * @see \Deferred\Promises::execute()
   */
  public function areFulfilled()
  {
    return $this->has_executed && isset($this->responses);
  }

  /**
   * Whether the \Deferred\Promises executed but failed.
   *
   * @return bool
   * @see \Deferred\Promises::execute()
   */
  public function hasFailed()
  {
    return $this->has_executed && !isset($this->responses);
  }

  /**
   * Raw Redis responses.
   *
   * Only available after a successful execute().
   *
   * @return array|null
   */
  public function responses()
  {
    return $this->responses;
  }

  /**
   * Execute all scheduled commands and bind the responses to their \Deferred\Futures.
   *
   * execute() returns the raw Redis responses as an array.
   *
   * @return array Raw Redis responses
   * @throws \Predis\PredisException
   * @throws \Deferred\NotAllowedException If invoked more than once
   * @throws \Deferred\FatalException If Redis response(s) does not correspond to known Future(s)
   */
  public function execute()
  {
    if ($this->has_executed)
      throw new \Deferred\NotAllowedException('Cannot execute \Deferred\Promises more than once');

    // mark now before execute(), which may throw an Exception
    $this->has_executed = true;

    // execute scheduled commands
    $responses = $this->client->execute();

    // verify counts match, one response per future
    $command_count = count($this->futures);
    $response_count = count($responses);
    if ($response_count != $command_count)
      throw new \Deferred\FatalException("Expecting $command_count responses, only received $response_count");

    // fulfill all Futures
    for ($ctr = 0; $ctr < $command_count; $ctr++)
      $this->futures[$ctr]->fulfill($responses[$ctr]);

    // this marks the object as fulfilled and not failed
    $this->responses = $responses;

    return $responses;
  }

  /**
   * Reduce multiple \Deferred\Futures into a single \Deferred\Future.
   *
   * reduce() is a variadic method accepting \Deferred\Futures as arguments.  All Futures *must*
   * have this Promises instance as their parent.
   *
   * At least two Futures must be supplied.  Duplicates are not allowed.
   *
   * When the reduced Future is fulfilled, it will receive an array representing all combined
   * results stored in the same order as the Futures supplied to this function.  Thus, a parser
   * may be installed on the reduced Future to further process this group of results.
   *
   * For example, an operation which determines if a value "is present" might reduce several
   * SISMEMBER operations by ANDing all the results into a single boolean value.
   *
   * @param \Deferred\Future ...$futures
   * @return \Deferred\Future
   * @throws \InvalidArgumentException
   */
  public function reduce(\Deferred\Future ...$futures)
  {
    // paranoia doesn't mean they're not out to get you
    $future_count = $this->verifyReducingFutures($futures);

    // reduce() generates an "orphaned" Future that's fed responses from the supplied Futures ...
    // $reduced is intentionally NOT included in the $this->futures array (although it maintains a
    // back-reference to this instance)
    $reduced = new \Deferred\Future($this);

    // when individual Futures are fulfilled, their response is recorded in $responses; when all
    // futures are fulfilled, $reduced is fulfilled
    $idx = 0;
    $responses = [];
    foreach ($futures as $future) {
      // note that a reference to $responses is held by each lambda (as it's shared between them)
      // while the current value of $idx is "assigned" to each lambda via pass-by-value syntax
      $future->onFulfilled(function ($result) use ($reduced, $future_count, &$responses, $idx) {
        // add response to the index corresponding to its respective Future
        $responses[$idx] = $result;

        // if all responses have been fulfilled, re-index $responses so its natural order matches
        // the index order and fulfill $reduces
        if (count($responses) >= $future_count) {
          ksort($responses, SORT_NUMERIC);
          $reduced->fulfill($responses);
        }
      });

      $idx++;
    }

    return $reduced;
  }

  /**
   * Verifies the list of Futures are suitable for reduction.
   *
   * @param array $futures
   * @throws \InvalidArgumentException
   * @returns int Number of futures being reduced
   */
  private function verifyReducingFutures(array $futures)
  {
    $future_count = count($futures);
    if ($future_count <= 1)
      throw new \InvalidArgumentException('\Deferred\Promises::reduce() requires more than one \Deferred\Future');

    $accepted = [];
    foreach ($futures as $future) {
      if ($future->parent() != $this)
        throw new \InvalidArgumentException('\Deferred\Future must be child of reducing \Deferred\Promises');

      if (in_array($future, $accepted, true))
        throw new \InvalidArgumentException('Duplicate \Deferred\Future passed to \Deferred\Promises::reduce()');

      $accepted[] = $future;
    }

    return $future_count;
  }
}

<?php

/**
 * Copyright (C) 2018 Internet Archive
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Deferred;

/**
 * Predis response receiver based on a promises/futures abstraction.
 *
 * As Redis commands are scheduled with \Deferred\Promises, each call returns a \Deferred\Future.
 * In turn, callbacks and bindings may be registered with the Future to be notified of the
 * fulfillment of the Redis command, to transform the raw data, and so on.
 *
 * @see \Deferred\Promises
 */
class Future
{
  private $parent;
  private $is_fulfilled = false;
  private $raw_value = null;
  private $value = null;
  private $binding = null;
  private $transformers = [];
  private $listeners = [];

  /**
   * To be used only by Promises.
   *
   * @param \Deferred\Promises $parent
   * @see \Deferred\Promises
   */
  public function __construct(\Deferred\Promises $parent)
  {
    $this->parent = $parent;
  }

  /**
   * \Deferred\Promises which generated this \Deferred\Future.
   *
   * @return \Deferred\Promises
   */
  public function parent()
  {
    return $this->parent;
  }

  /**
   * Determines if the \Deferred\Future has been fulfilled (received its final value).
   *
   * @return boolean
   */
  public function isFulfilled()
  {
    return $this->is_fulfilled;
  }

  /**
   * The result value for the associated Redis command.
   *
   * This is the final value after all transformations have been performed.
   *
   * @return mixed
   * @throws \Deferred\NotAllowedException If unfulfilled
   * @see \Deferred\Future::rawValue()
   */
  public function value()
  {
    if (!$this->isFulfilled())
      throw new \Deferred\NotAllowedException('Cannot get result of unfulfilled \Deferred\Future');

    return $this->value;
  }

  /**
   * The raw (original) result value for the associated Redis command.
   *
   * @return mixed
   * @throws \Deferred\NotAllowedException If unfulfilled
   * @see \Deferred\Future::value()
   */
  public function rawValue()
  {
    if (!$this->isFulfilled())
      throw new \Deferred\NotAllowedException('Cannot get result of unfulfilled \Deferred\Future');

    return $this->raw_value;
  }

  /**
   * Binds the raw Redis result to this future.
   *
   * This method should only be invoked by \Deferred\Promises.
   *
   * @param mixed $response Raw Redis response
   * @throws \Deferred\NotAllowedException
   */
  public function fulfill($response)
  {
    if ($this->isFulfilled())
      throw new \Deferred\NotAllowedException('Attempted to fulfill already-fulfilled \Deferred\Future');

    // store original raw value
    $this->raw_value = $response;

    // run response through transformer chain, if any
    foreach ($this->transformers as $transformer) {
      try {
        $response = call_user_func($transformer, $response);
      } catch (\Exception $e) {
        // preferable if transformers didn't throw Exceptions, but need to be safe here ... the
        // Future goes unfulfilled but the Exception is not propagated upwards.
        //
        // TODO: Reporting / logging mechanism for this situation
        return;
      }
    }

    // store response; the future has arrived
    $this->value = $response;
    $this->is_fulfilled = true;

    // if bound to an external PHP variable, store result there as well
    //
    // Q. Why can't bind() merely assign $this->value a reference to the external reference?
    // A. External code could later modify the external variable and cause an internal change to
    // \Deferred\Future which should always hold the true response.
    $this->binding = $this->value;

    // notify fulfillment listeners
    foreach ($this->listeners as $listener) {
      try {
        call_user_func($listener, $this->value);
      } catch (\Exception $e) {
        // like transformers, can't have listeners throw Exceptions and cause problems elsewhere ...
        // unlike transformers (where each transformer provides input for the next), can merely
        // drop one listener's Exception and give the others a chance to process the fulfilled
        // value
        //
        // TODO: Reporting / logging mechanism
      }
    }
  }

  /**
   * Bind by reference a variable to \Deferred\Future's final result.
   *
   * A reference to $ref is held for the lifetime of the \Deferred\Future, but the variable is only
   * written to when fulfill() is invoked by \Deferred\Promises::execute().  Calling bind() after
   * fulfillment will throw a \Deferred\NotAllowedException.
   *
   * If parsers are installed, the bound reference receives the final parsed result.
   *
   * Only one binding may be assigned at a time.  Repeated calls to this function will bind the
   * last supplied reference.
   *
   * @param mixed &$ref
   * @return \Deferred\Future This instance
   * @throws \Deferred\NotAllowedException
   */
  public function bind(&$ref)
  {
    if ($this->isFulfilled())
      throw new \Deferred\NotAllowedException('Cannot bind to result after fulfillment');

    // see fulfill() for more information on this mechanism
    $this->binding =& $ref;

    return $this;
  }

  /**
   * Install a user-defined transformer to convert Redis responses.
   *
   * Transformers are invoked in order of installation.  Each transformer has the opportunity to
   * convert the response to an appropriate or usable value, i.e. type casting, bounds checking,
   * aggregation, deserialization, and so on.
   *
   * Upon fulfillment, the first installed transformer accepts the raw Redis response and returns
   * a transformed (or converted) result.  (A transformer may also return the supplied result, but
   * it must return *something*.)  The next installed transformer receives the result of the first
   * transformer, and so on.
   *
   * The final transformed value is stored by \Deferred\Future and obtainable via value().  The
   * original response is obtainable via rawValue().
   *
   * If a transformer throws an Exception the Future will remain unfulfilled.
   *
   * $transformer call signature:
   *   mixed doTransform(mixed $response)
   *
   * @param callable $transformer
   * @return \Deferred\Future This instance
   */
  public function transform(callable $transformer)
  {
    $this->transformers[] = $transformer;

    return $this;
  }

  /**
   * Register a listener to be notified when the \Deferred\Future is fulfilled.
   *
   * The listener's signature should be:
   *   void listener(mixed $result)
   *
   * The listener will be passed the final parsed value.
   *
   * @param callable $listener
   * @return \Deferred\Future This instance
   */
  public function whenFulfilled(callable $listener)
  {
    $this->listeners[] = $listener;

    return $this;
  }
}

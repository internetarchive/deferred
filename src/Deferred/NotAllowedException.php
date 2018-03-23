<?php

namespace Deferred;

/**
 * Exception indicating the caller attempted an operation outside of expections.
 *
 * Usually indicates a method was called before a precondition was met.
 */
class NotAllowedException extends \Deferred\DeferredException
{
}

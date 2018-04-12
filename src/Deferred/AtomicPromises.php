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
 * A \Deferred\Promises class which guarantees transactional execution of commands.
 *
 * This class largely exists to allow functions to require transactions when scheduling operations
 * (either using PHP's type hinting or manual runtime type checking) without having to be concerned
 * whether the scheduler is a MULTI/EXEC transaction or an atomic pipeline.
 *
 * @see \Deferred\PromisesTransaction
 * @see \Deferred\AtomicPromisesPipeline
 */
abstract class AtomicPromises extends \Deferred\Promises
{
}

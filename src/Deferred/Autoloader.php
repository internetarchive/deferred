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
 * Quick-and-dirty autoloader for Deferred library.
 */
class Autoloader
{
  /**
   * Register Deferred's autoloader.
   */
  public static function register()
  {
    spl_autoload_register(function ($class) {
      if (strpos($class, 'Deferred\\') !== 0)
        return;

      $path = str_replace('\\', DIRECTORY_SEPARATOR, $class);
      $path = "src/$path.php";

      if (is_file($path))
        require_once($path);
    });
  }
}
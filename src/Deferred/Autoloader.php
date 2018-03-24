<?php

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
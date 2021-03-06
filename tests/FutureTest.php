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

require_once 'vendor/autoload.php';
require_once 'src/Deferred/Autoloader.php';
\Deferred\Autoloader::register();

/**
 * Unit tests for \Deferred\Future.
 *
 * Note that these examples connect to a running Redis instance at localhost (127.0.0.1) port 6379.
 * The code may be modified to connect to a server running at a different endpoint.
 *
 * DO NOT RUN THIS CODE AGAINST A PRODUCTION SERVER.
 */
class FutureTest extends PHPUnit\Framework\TestCase
{
  private $promises;

  public function setUp()
  {
    $client = new Predis\Client();
    $this->promises = new \Deferred\PromisesPipeline($client);
  }

  //
  // tests
  //

  public function testParent()
  {
    $future = $this->promises->get('abc');

    $this->assertEquals($this->promises, $future->parent());
  }

  public function testIsFulfilled()
  {
    $future = $this->promises->get('abc');

    $this->assertFalse($future->isFulfilled());

    $this->promises->execute();

    $this->assertTrue($future->isFulfilled());
  }

  public function testValue()
  {
    $this->promises->set('abc', '1234');
    $future = $this->promises->get('abc');
    $this->promises->execute();

    $this->assertEquals('1234', $future->value());
    $this->assertEquals('1234', $future->rawValue());
  }

  public function testTransform()
  {
    $this->promises->set('abc', '1234');
    $future = $this->promises->get('abc');
    $future->transform('strrev');
    $this->promises->execute();

    $this->assertEquals('4321', $future->value());
    $this->assertEquals('1234', $future->rawValue());
  }

  public function testStackedTransform()
  {
    $this->promises->set('abc', '1234');
    $future = $this->promises->get('abc');
    $future->transform('strrev');
    $future->transform('intval');
    $this->promises->execute();

    // asserting an int, not a string
    $this->assertEquals(4321, $future->value());
  }

  public function testBind()
  {
    $this->promises->set('abc', 'binding');
    $future = $this->promises->get('abc');

    $value = null;
    $future->bind($value);
    $this->assertNull($value);

    $this->promises->execute();

    $this->assertEquals('binding', $value);
  }

  public function testWhenFulfilled()
  {
    $this->promises->set('abc', '1234');
    $future = $this->promises->get('abc');
    $future->transform('strrev');
    $future->transform('intval');

    $fulfilled = false;
    $future->whenFulfilled(function ($value) use (&$fulfilled) {
      $this->assertEquals(4321, $value);
      $fulfilled = true;
    });

    $this->promises->execute();

    $this->assertTrue($fulfilled);
  }
}

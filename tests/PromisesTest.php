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
 * Unit tests for \Deferred\Promises.
 *
 * Note that these examples connect to a running Redis instance at localhost (127.0.0.1) port 6379.
 * The code may be modified to connect to a server running at a different endpoint.
 *
 * DO NOT RUN THIS CODE AGAINST A PRODUCTION SERVER.
 */
class PromisesTest extends PHPUnit\Framework\TestCase
{
  private $client;

  protected function setUp()
  {
    parent::setUp();

    // pass custom parameters to Client to change network address and connection options
    $this->client = new \Predis\Client();
  }

  private function basicPromisesTest(\Deferred\Promises $promises)
  {
    $set = $promises->set('deferred:string', 'gnusto');
    $get = $promises->get('deferred:string');

    $promises->execute();

    $this->assertEquals('gnusto', $get->value());
  }

  //
  // tests
  //

  public function testPipeline()
  {
    $this->basicPromisesTest(new \Deferred\PromisesPipeline($this->client));
  }

  public function testTransaction()
  {
    $this->basicPromisesTest(new \Deferred\PromisesTransaction($this->client));
  }

  public function testAtomic()
  {
    $this->basicPromisesTest(new \Deferred\AtomicPromisesPipeline($this->client));
  }

  /**
   * Test that the three Promises concrete classes can be type-checked for atomicity.
   *
   * @dataProvider provideAtomicTypeChecking
   */
  public function testAtomicTypeChecking($promises_type, $is_atomic)
  {
    $this->assertEquals($is_atomic, is_a($promises_type, \Deferred\AtomicPromises::class, true));
  }

  /**
   * Data provider for testAtomicTypeChecking().
   */
  public function provideAtomicTypeChecking()
  {
    return [
      'PromisesPipeline' =>       [ \Deferred\PromisesPipeline::class, false ],
      'PromisesTransaction' =>    [ \Deferred\PromisesTransaction::class, true ],
      'AtomicPromisesPipline' =>  [ \Deferred\AtomicPromisesPipeline::class, true ],
    ];
  }

  public function testStateFunctions()
  {
    $promises = new \Deferred\PromisesPipeline($this->client);

    $this->assertFalse($promises->hasExecuted());
    $this->assertFalse($promises->areFulfilled());
    $this->assertFalse($promises->hasFailed());
    $this->assertNull($promises->responses());

    // executing an empty Promises object is acceptable
    $promises->execute();

    $this->assertTrue($promises->hasExecuted());
    $this->assertTrue($promises->areFulfilled());
    $this->assertFalse($promises->hasFailed());
    $this->assertNotNull($promises->responses());
  }

  /**
   * @expectedException \Deferred\NotAllowedException
   */
  public function testDoubleExecute()
  {
    $promises = new \Deferred\PromisesPipeline($this->client);
    $promises->execute();
    $promises->execute();
  }

  public function testReduce()
  {
    $promises = new \Deferred\PromisesPipeline($this->client);

    $promises->set('abc', 'value0');
    $promises->set('def', 'value1');

    $f1 = $promises->get('abc');
    $f2 = $promises->get('def');

    $reduced = $promises->reduce($f1, $f2);

    $fulfilled = false;
    $reduced->onFulfilled(function ($results) use (&$fulfilled) {
      $this->assertTrue(is_array($results));
      $this->assertEquals(2, count($results));
      $this->assertEquals('value0', $results[0]);
      $this->assertEquals('value1', $results[1]);
      $fulfilled = true;
    });

    $promises->execute();

    $this->assertTrue($fulfilled);
  }
}

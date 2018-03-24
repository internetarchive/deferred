<?php

require 'vendor/autoload.php';
require 'src/Deferred/Autoloader.php';
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

  private function basicPromisesTest($cci)
  {
    $promises = new \Deferred\Promises($cci);

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
    $this->basicPromisesTest($this->client->pipeline());
  }

  public function testTransaction()
  {
    $this->basicPromisesTest($this->client->transaction());
  }

  public function testAtomic()
  {
    $this->basicPromisesTest($this->client->pipeline(['atomic' => true]));
  }

  public function testStateFunctions()
  {
    $promises = new \Deferred\Promises($this->client->pipeline());

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
    $promises = new \Deferred\Promises($this->client->pipeline());
    $promises->execute();
    $promises->execute();
  }

  public function testReduce()
  {
    $promises = new \Deferred\Promises($this->client->pipeline());

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

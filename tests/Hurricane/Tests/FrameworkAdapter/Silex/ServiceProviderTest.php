<?php

namespace Hurricane\TestsFrameworkAdapter\Silex;

use Hurricane\FrameworkAdapter\Silex\ServiceProvider;

class ServiceProviderTest extends \PHPUnit_Framework_TestCase
{
    protected $subject;

    public function setUp()
    {
        $this->subject = new ServiceProvider();
    }

    public function testTrue()
    {
        $this->assertTrue(true);
    }
}
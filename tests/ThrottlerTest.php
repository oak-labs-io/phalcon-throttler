<?php

namespace OakLabs\PhalconThrottler\Tests;

use OakLabs\PhalconThrottler\RateLimit;
use OakLabs\PhalconThrottler\RedisThrottler;

class ThrottlerTest extends TestCase
{
    protected static $fm;

    public static function setupBeforeClass()
    {
        parent::setUpBeforeClass();
    }

    public function testRedisThrottler()
    {
        $redis = $this->getDI()->get('redis');

        $throttler = new RedisThrottler($redis, [
            'bucket_size'  => 3,
            'refill_time'  => 2, // 10s
            'refill_amount'  => 1,
            'warning_limit' => 1
        ]);

        $meterId = 'my-meter-id-1';

        // Consume 1st time
        $rateLimit = $throttler->consume($meterId);

        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertEquals(1, $rateLimit->getHits());
        $this->assertEquals(3, $rateLimit->getHitsPerPeriod());
        $this->assertEquals(2, $rateLimit->getRemaining());
        $this->assertFalse($rateLimit->isWarning());
        $this->assertFalse($rateLimit->isLimited());

        // Consume 2nd time
        $rateLimit = $throttler->consume($meterId);

        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertEquals(2, $rateLimit->getHits());
        $this->assertEquals(3, $rateLimit->getHitsPerPeriod());
        $this->assertEquals(1, $rateLimit->getRemaining());
        $this->assertTrue($rateLimit->isWarning());
        $this->assertFalse($rateLimit->isLimited());

        // Consume 3rd time
        $rateLimit = $throttler->consume($meterId);

        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertEquals(3, $rateLimit->getHits());
        $this->assertEquals(3, $rateLimit->getHitsPerPeriod());
        $this->assertEquals(0, $rateLimit->getRemaining());
        $this->assertTrue($rateLimit->isWarning());
        $this->assertFalse($rateLimit->isLimited());

        // Consume 4th time
        $rateLimit = $throttler->consume($meterId);

        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertEquals(3, $rateLimit->getHits());
        $this->assertEquals(3, $rateLimit->getHitsPerPeriod());
        $this->assertEquals(0, $rateLimit->getRemaining());
        $this->assertTrue($rateLimit->isLimited());

        // Wait 2s
        sleep(2);

        // Consume 4rd time
        $rateLimit = $throttler->consume($meterId);

        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertEquals(3, $rateLimit->getHits());
        $this->assertEquals(3, $rateLimit->getHitsPerPeriod());
        $this->assertEquals(0, $rateLimit->getRemaining());
        $this->assertTrue($rateLimit->isWarning());
        $this->assertFalse($rateLimit->isLimited());

        $redis->del(sprintf('rate_limiter:%s', $meterId));
    }
}

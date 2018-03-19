<?php

namespace OakLabs\PhalconThrottler;

class RedisThrottler implements ThrottlerInterface
{
    /**
     * @var \Redis
     */
    protected $redis;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var bool
     */
    protected $limitExceeded;

    /**
     * @var bool
     */
    protected $limitWarning;

    /**
     * @param \Redis $redis
     * @param array $config
     */
    public function __construct(\Redis $redis, array $config = [])
    {
        $this->config = array_merge([
            'bucket_size'  => 20,
            'refill_time'  => 600, // 10m
            'refill_amount'  => 10,
            'warning_limit' => 1
        ], $config);

        $this->redis = $redis;
    }

    /**
     * @return bool
     */
    public function isLimitWarning(): bool
    {
        return $this->limitWarning;
    }

    /**
     * @return bool
     */
    public function isLimitExceeded(): bool
    {
        return $this->limitExceeded;
    }

    /**
     * @param string $meterId
     * @param int $warnThreshold
     * @param int $numTokens
     * @param int|null $time
     *
     * @return RateLimit
     */
    public function consume(string $meterId, int $warnThreshold = 0, int $numTokens = 1, int $time = null): RateLimit
    {
        $this->limitWarning = false;

        $this->limitExceeded = false;

        // Build the Redis key
        $key = sprintf('rate_limiter:%s', $meterId);

        // Retrieve the bucket
        $bucket = $this->retrieveBucket($key);

        // Refill the value
        ['new_value' => $newValue, 'refill_count' => $refillCount] = $this->refillBucket($bucket);

        // If still <= 0, it's rate limited
        if ($newValue <= 0) {
            $this->limitExceeded = true;
            $this->limitWarning = true;
        } else {
            // Remove the tokens
            $newValue -= $numTokens;
        }

        if ($newValue <= $this->config['warning_limit']) {
            $this->limitWarning = true;
        }

        // Compute Last Update
        $newLastUpdate = min(
            time(),
            $bucket['last_update'] + $refillCount * $this->config['refill_time']
        );

        // Update Redis
        $this->redis->hMSet($key, [
            'value' => $newValue,
            'last_update' => $newLastUpdate
        ]);

        // Update Expiry time
        $this->updateExpiryTime($key);

        return new RateLimit(
            (int)round(($this->config['bucket_size']) - $newValue) / $numTokens,
            max(0, (int)round(($newValue / $numTokens))),
            $this->config['refill_time'],
            (int)ceil($this->config['bucket_size'] / $numTokens),
            $this->isLimitExceeded(),
            $this->isLimitWarning()
        );
    }

    /**
     * If the bucket does not exist, it is created
     *
     * @param string $key
     * @return array
     */
    protected function retrieveBucket(string $key): array
    {
        if (!$this->redis->hExists($key, 'value')) {
            $bucket = ['value' => $this->config['bucket_size'], 'last_update' => time()];
            $this->redis->hMSet($key, $bucket);
        } else {
            $bucket = $this->redis->hGetAll($key);
        }

        return $bucket;
    }

    /**
     * Refull the bucket and return the new value.
     *
     * @param array $bucket
     * @return array
     */
    protected function refillBucket(array $bucket): array
    {
        // Check the refill count
        $refillCount = (int)floor((time() - $bucket['last_update']) / $this->config['refill_time']);

        // Refill the bucket
        return [
            'new_value' => min(
                $this->config['bucket_size'],
                $bucket['value'] + $refillCount * $this->config['refill_amount']
            ),
            'refill_count' => $refillCount
        ];
    }

    /**
     * @param string $key
     * @return bool
     */
    protected function updateExpiryTime(string $key): bool
    {
        return $this->redis->expireAt($key, time() +
            ((1 + (int)ceil($this->config['bucket_size'] / $this->config['refill_amount']))
                * $this->config['refill_time']));
    }
}

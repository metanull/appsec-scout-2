<?php

/**
 * The queue worker runs with --timeout=1800 (docker/supervisord.conf). If
 * retry_after is shorter than that, the driver considers a still-running
 * job "lost" and releases it back onto the queue mid-run, silently draining
 * its attempts counter without the job ever having actually failed.
 */
it('sets the database queue retry_after longer than the worker timeout', function () {
    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan(1800);
});

it('sets the redis queue retry_after longer than the worker timeout', function () {
    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan(1800);
});

<?php
/*
 * One contender of DbStoreTest::testOneOfManyAtOnceGetsTheMark: waits for
 * the start time, marks the nonce and prints the result.
 *
 * Usage: php store-race.php <nonce> <start, microtime>
 */

require __DIR__ . '/bootstrap.php';

$store = new FetchItDbStore(fetchit_test_modx(), function () {});
while (microtime(true) < (float)$argv[2]) {
    usleep(500);
}
echo $store->mark($argv[1], time() + 60);

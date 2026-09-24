<?php
/**
 * FetchIt 3.x had a namespaced FetchIt\FetchIt class. FetchIt 4 has one
 * class for MODX 2 and MODX 3, and its file defines this name as an alias
 * (see the end of model/fetchit.class.php). This file lets the MODX 3
 * autoloader, and "require src/FetchIt.php" in 3.x code, find it.
 */

if (!class_exists('FetchIt', false)) {
    require_once dirname(__DIR__) . '/model/fetchit.class.php';
}

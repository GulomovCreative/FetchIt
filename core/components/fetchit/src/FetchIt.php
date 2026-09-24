<?php
/**
 * FetchIt 3.x had a namespaced FetchIt\FetchIt class. FetchIt 4 has one
 * class for MODX 2 and MODX 3, and its file defines this name as an alias
 * (see the end of model/fetchit.class.php). This file lets the autoloader
 * and "require src/FetchIt.php" from 3.x code find it.
 */

require_once dirname(__DIR__) . '/model/fetchit.class.php';

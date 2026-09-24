<?php
/**
 * FetchIt 3.x had a namespaced FetchIt\FetchIt class. FetchIt 4 has one
 * class for MODX 2 and MODX 3; this name is an alias of it.
 */

namespace FetchIt;

require_once dirname(__DIR__) . '/model/fetchit.class.php';

if (!class_exists('FetchIt\FetchIt', false)) {
    class_alias('FetchIt', 'FetchIt\FetchIt');
}

<?php

return [
    'frontend.js' => [
        'xtype' => 'textfield',
        'value' => '[[+assetsUrl]]js/fetchit.js',
        'area' => 'fetchit_main',
    ],
    'frontend.js.classname' => [
        'xtype' => 'textfield',
        'value' => 'FetchIt',
        'area' => 'fetchit_main',
    ],
    'frontend.input.invalid.class' => [
        'xtype' => 'textfield',
        'value' => 'is-invalid',
        'area' => 'fetchit_main',
    ],
    'frontend.custom.invalid.class' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'fetchit_main',
    ],
    'frontend.default.notifier' => [
        'xtype' => 'combo-boolean',
        'value' => 0,
        'area' => 'fetchit_main',
    ],
    'protection' => [
        'xtype' => 'combo-boolean',
        'value' => 1,
        'area' => 'fetchit_protection',
    ],
    'protection.secret' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'fetchit_protection',
    ],
    'protection.min_time' => [
        'xtype' => 'numberfield',
        'value' => 3,
        'area' => 'fetchit_protection',
    ],
    'protection.token_ttl' => [
        'xtype' => 'numberfield',
        'value' => 86400,
        'area' => 'fetchit_protection',
    ],
    'protection.rate_limit' => [
        'xtype' => 'numberfield',
        'value' => 10,
        'area' => 'fetchit_protection',
    ],
    'protection.rate_window' => [
        'xtype' => 'numberfield',
        'value' => 600,
        'area' => 'fetchit_protection',
    ],
    'protection.log' => [
        'xtype' => 'numberfield',
        'value' => 1,
        'area' => 'fetchit_protection',
    ],
    'protection.proxies' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'fetchit_protection',
    ],
    'protection.ip_header' => [
        'xtype' => 'textfield',
        'value' => 'X-Forwarded-For',
        'area' => 'fetchit_protection',
    ],
];

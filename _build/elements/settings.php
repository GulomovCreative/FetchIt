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
];

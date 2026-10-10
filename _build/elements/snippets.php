<?php

return [
    'FetchIt' => [
        'file' => 'fetchit',
        'description' => '',
        'properties' => [
            'form' => [
                'type' => 'textfield',
                'value' => 'tpl.FetchIt.example',
            ],
            'snippet' => [
                'type' => 'textfield',
                'value' => 'FormIt',
            ],
            'actionUrl' => [
                'type' => 'textfield',
                'value' => '[[+assetsUrl]]action.php',
            ],
            'clearFieldsOnSuccess' => [
                'type' => 'combo-boolean',
                'value' => true,
            ],
            'formState' => [
                'type' => 'combo-boolean',
                'value' => true,
            ],
            'ariaBusy' => [
                'type' => 'combo-boolean',
                'value' => true,
            ],
        ],
    ],
    'FetchItScript' => [
        'file' => 'fetchitscript',
        'description' => '',
        'properties' => [],
    ],
];

<?php

return [
    'FetchIt' => [
        'file' => 'fetchit',
        'description' => '',
        // Not here: properties the processing snippet may also have (redirectTo,
        // redirectParams, timeout, retries). The snippet stores all its properties
        // with the form, so their defaults would override the snippet's own values.
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

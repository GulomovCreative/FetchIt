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
        ],
    ],
];

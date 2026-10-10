<?php

return [
    'FetchIt' => [
        'file' => 'fetchit',
        'description' => '',
        'events' => [
            // Before plugins that minify the page and drop the comments,
            // the marker of a cached FetchItScript among them.
            'OnWebPagePrerender' => ['priority' => -10],
        ],
    ],
];

<?php

return [
    'frontend' => [
        'bmack/realurl-decodeSpeakingURL' => [
            'target' => \Tx\Realurl\Middleware\DecodeSpeakingURL::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver'
            ]
        ],
    ]
];

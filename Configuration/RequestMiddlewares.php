<?php

return [
    'frontend' => [
        'bmack/realurl-decodeSpeakingURL' => [
            'target' => \Tx\Realurl\Middleware\DecodeSpeakingURL::class,
            'after' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'before' => [
                'typo3/cms-frontend/prepare-tsfe-rendering'
            ]
        ],
    ]
];

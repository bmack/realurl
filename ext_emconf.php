<?php
$EM_CONF[$_EXTKEY] = array(
    'title' => 'RealURL: speaking paths for TYPO3',
    'description' => 'Creates nice looking URLs for TYPO3 pages: converts http://example.com/index.phpid=12345&L=2 to http://example.com/path/to/your/page/. Please, ask for free support in TYPO3 mailing lists or contact the maintainer for paid support.',
    'category' => 'fe',
    'conflicts' => 'cooluri,simulatestatic',
    'state' => 'excludeFromUpdates',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearCacheOnLoad' => 1,
    'author' => 'Dmitry Dulepov',
    'author_email' => 'dmitry.dulepov@gmail.com',
    'author_company' => '',
    'version' => '3.0.0',
    'constraints' => array(
        'depends' => array(
            'php' => '5.5.0-7.1.999',
            'typo3' => '9.4.0-9.5.99',
        ),
        'conflicts' => array(
            'cooluri' => '',
            'simulatestatic' => '',
        ),
        'suggests' => array(
            'static_info_tables' => '2.0.2-',
        ),
    ),
);

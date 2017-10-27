TYPO3 Extension realurl
=======================

This is an unofficial fork of the TYPO3 CMS Extension RealURL
It aims for no backwards compatibility with TYPO3 versions using RealURL 1.x since ages,
and have a compatible version with TYPO3 v8 and TYPO3 v9.

As the class names changed the configuration needs some slight change, too:

```'userFunc' => \Tx\Realurl\UriGeneratorAndResolver::class . '->main'```

Other than that, configuration and behaviour (including potential bugs) are the same.

This fork comes with no support. If you find bugs or have questions, you may want to use the original version:
https://github.com/dmitryd/typo3-realurl

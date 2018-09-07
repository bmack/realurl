TYPO3 Extension realurl v1 for TYPO3 v9
=======================================

This is an unofficial fork of the TYPO3 CMS Extension RealURL.

The fork was originally started by helhum/realurl, but due to lack of support for TYPO3 v9, this
"fork of the fork" was built.

It aims for no backwards compatibility with TYPO3 versions using RealURL 1.x since ages,
and have a compatible version with TYPO3 v9. The minimum requirement is TYPO3 9.4.

As the class names changed the configuration needs some slight change, too:

```'userFunc' => \Tx\Realurl\UriGeneratorAndResolver::class . '->main'```

Other than that, configuration and behaviour (including potential bugs) are the same.

This fork comes with no support. If you find bugs or have questions, you may want to use the original version:
https://github.com/dmitryd/typo3-realurl

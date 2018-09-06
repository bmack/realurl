<?php
namespace Tx\Realurl\Middleware;

/*
 * This file is part of the TYPO3 CMS extension realurl.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tx\Realurl\Hooks\UrlRewritingHook;
use TYPO3\CMS\Core\Site\Entity\PseudoSite;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Middleware to alter the $request object after decoding the current speaking URL
 *
 * If in a PseudoSite we
 *  * Set the siteScript (because realurl relies on that)
 *  * Decode the URL
 *  * Update the Request
 */
class DecodeSpeakingURL implements MiddlewareInterface
{
    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (($site = $request->getAttribute('site', null)) instanceof PseudoSite) {
            $tsfe = $this->getTypoScriptFrontendController();
            $tsfe->siteScript = $request->getAttribute('normalizedParams')->getSiteScript();
            $urlRewriter = GeneralUtility::makeInstance(UrlRewritingHook::class);
            $urlRewriter->decodeSpURL(
                ['pObj' => $tsfe]
            );
            $request = $request->withQueryParams($_GET);
            $GLOBALS['TYPO3_REQUEST'] = $request;
        }

        return $handler->handle($request);
    }

    /**
     * @return TypoScriptFrontendController
     */
    protected function getTypoScriptFrontendController(): TypoScriptFrontendController
    {
        return $GLOBALS['TSFE'];
    }
}

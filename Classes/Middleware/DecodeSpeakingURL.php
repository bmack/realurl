<?php
namespace Tx\Realurl\Middleware;


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
            $urlRewriter = GeneralUtility::makeInstance(UrlRewritingHook::class);
            $urlRewriter->decodeSpURL(
                ['pObj' => $this->getTypoScriptFrontendController()]
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

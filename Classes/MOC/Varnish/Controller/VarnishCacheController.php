<?php
namespace MOC\Varnish\Controller;

use MOC\Varnish\Service\ContentCacheFlusherService;
use MOC\Varnish\Service\VarnishBanService;
use Neos\Flow\Annotations as Flow;
use Neos\Error\Messages\Message;
use Neos\Flow\Http\Client\CurlEngine;
use Neos\Flow\Http\Uri;
use Neos\Flow\Http\Request;
use TYPO3\Neos\Domain\Model\Site;
use TYPO3\Neos\Domain\Service\ContentContext;
use TYPO3\Neos\Domain\Service\ContentDimensionPresetSourceInterface;

class VarnishCacheController extends \TYPO3\Neos\Controller\Module\AbstractModuleController {

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Service\NodeTypeManager
	 */
	protected $nodeTypeManager;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Neos\Domain\Service\ContentContextFactory
	 */
	protected $contextFactory;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Neos\Domain\Service\NodeSearchService
	 */
	protected $nodeSearchService;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Neos\Domain\Repository\SiteRepository
	 */
	protected $siteRepository;

	/**
	 * @Flow\Inject
	 * @var ContentDimensionPresetSourceInterface
	 */
	protected $contentDimensionPresetSource;

	/**
	 * @var array
	 */
	protected $viewFormatToObjectNameMap = array(
		'html' => 'TYPO3\Fluid\View\TemplateView',
		'json' => 'Neos\Flow\Mvc\View\JsonView'
	);

	/**
	 * @return void
	 */
	public function indexAction() {
		$this->view->assign('activeSites', $this->siteRepository->findOnline());
	}

	/**
	 * @param string $searchWord
	 * @param Site $selectedSite
	 * @return void
	 */
	public function searchForNodeAction($searchWord, Site $selectedSite = NULL) {
		$documentNodeTypes = $this->nodeTypeManager->getSubNodeTypes('TYPO3.Neos:Document');
		$shortcutNodeType = $this->nodeTypeManager->getNodeType('TYPO3.Neos:Shortcut');
		$nodeTypes = array_diff($documentNodeTypes, array($shortcutNodeType));
		$sites = array();
		$activeSites = $this->siteRepository->findOnline();
		foreach ($selectedSite ? array($selectedSite) : $activeSites as $site) {
			/** @var Site $site */
			$contextProperties = array(
				'workspaceName' => 'live',
				'currentSite' => $site
			);
			$contentDimensionPresets = $this->contentDimensionPresetSource->getAllPresets();
			if (count($contentDimensionPresets) > 0) {
				$mergedContentDimensions = array();
				foreach ($contentDimensionPresets as $contentDimensionIdentifier => $contentDimension) {
					$mergedContentDimensions[$contentDimensionIdentifier] = array($contentDimension['default']);
					foreach ($contentDimension['presets'] as $contentDimensionPreset) {
						$mergedContentDimensions[$contentDimensionIdentifier] = array_merge($mergedContentDimensions[$contentDimensionIdentifier], $contentDimensionPreset['values']);
					}
					$mergedContentDimensions[$contentDimensionIdentifier] = array_values(array_unique($mergedContentDimensions[$contentDimensionIdentifier]));
				}
				$contextProperties['dimensions'] = $mergedContentDimensions;
			}
			/** @var ContentContext $liveContext */
			$liveContext = $this->contextFactory->create($contextProperties);
			$nodes = $this->nodeSearchService->findByProperties($searchWord, $nodeTypes, $liveContext, $liveContext->getCurrentSiteNode());
			if (count($nodes) > 0) {
				$sites[$site->getNodeName()] = array(
					'site' => $site,
					'nodes' => $nodes
				);
			}
		}
		$this->view->assignMultiple(array(
			'searchWord' => $searchWord,
			'selectedSite' => $selectedSite,
			'sites' => $sites,
			'activeSites' => $activeSites
		));
	}

	/**
	 * @param \TYPO3\TYPO3CR\Domain\Model\Node $node
	 * @return void
	 */
	public function purgeCacheAction(\TYPO3\TYPO3CR\Domain\Model\Node $node) {
		$service = new ContentCacheFlusherService();
		$service->flushForNode($node);
		$this->view->assign('value', TRUE);
	}

	/**
	 * @param string $tags
	 * @param Site $site
	 * @return void
	 */
	public function purgeCacheByTagsAction($tags, Site $site = NULL) {
		$domains = NULL;
		if ($site !== NULL && $site->hasActiveDomains()) {
			$domains = $site->getActiveDomains()->map(function($domain) {
				return $domain->getHostpattern();
			})->toArray();
		}
		$tags = explode(',', $tags);
		$service = new VarnishBanService();
		$service->banByTags($tags, $domains);
		$this->flashMessageContainer->addMessage(new Message(sprintf('Varnish cache cleared for tags "%s" for %s', implode('", "', $tags), $site ? 'site ' . $site->getName() : 'installation')));
		$this->redirect('index');
	}

	/**
	 * @param Site $site
	 * @param string $contentType
	 * @return void
	 */
	public function purgeAllVarnishCacheAction(Site $site = NULL, $contentType = NULL) {
		$domains = NULL;
		if ($site !== NULL && $site->hasActiveDomains()) {
			$domains = $site->getActiveDomains()->map(function($domain) {
				return $domain->getHostpattern();
			})->toArray();
		}
		$service = new VarnishBanService();
		$service->banAll($domains, $contentType);
		$this->flashMessageContainer->addMessage(new Message(sprintf('All varnish cache cleared for %s%s', $site ? 'site ' . $site->getName() : 'installation', $contentType ? ' with content type "' . $contentType . '"' : '')));
		$this->redirect('index');
	}

	/**
	 * @param string $url
	 * @return string
	 */
	public function checkUrlAction($url) {
		$uri = new Uri($url);
		if (isset($this->settings['reverseLookupPort'])) {
			$uri->setPort($this->settings['reverseLookupPort']);
		}
		$request = Request::create($uri);
		$request->setHeader('X-Cache-Debug', '1');
		$engine = new CurlEngine();
		$engine->setOption(CURLOPT_SSL_VERIFYPEER, FALSE);
		$engine->setOption(CURLOPT_SSL_VERIFYHOST, FALSE);
		$response = $engine->sendRequest($request);
		$this->view->assign('value', array(
			'statusCode' => $response->getStatusCode(),
			'host' => parse_url($url, PHP_URL_HOST),
			'url' => $url,
			'headers' => array_map(function($value) {
				return array_pop($value);
			}, $response->getHeaders()->getAll())
		));
	}

}

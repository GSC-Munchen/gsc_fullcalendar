<?php
declare(strict_types=1);

namespace Mediadreams\MdFullcalendar\Controller;

/***
 *
 * This file is part of the "FullCalendar.io for ext:Calendarize" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2019 Christoph Daecke
 *
 ***/

use HDNET\Calendarize\Domain\Repository\IndexRepository;
use Mediadreams\MdFullcalendar\Domain\Repository\CategoryRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * CalController
 */
class CalController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{
    /**
     * @var IndexRepository
     */
    protected $indexRepository;

    /**
     * @var CategoryRepository
     */
    protected $categoryRepository;

    /**
     * CalController constructor
     *
     * @param IndexRepository $indexRepository
     * @param CategoryRepository $categoryRepository
     */
    public function __construct(
        IndexRepository $indexRepository,
        CategoryRepository $categoryRepository
    ) {
        $this->indexRepository = $indexRepository;
        $this->categoryRepository = $categoryRepository;
    }

    /**
     * Show the calendar
     *
     * @return ResponseInterface
     */
    public function showAction(): ResponseInterface
    {
        if (!empty($this->settings['language'])) {
            $pageRender = GeneralUtility::makeInstance(PageRenderer::class);
            $pageRender->addJsFooterFile('EXT:md_fullcalendar/Resources/Public/fullcalendar/lib/locales/' . $this->settings['language'] . '.js');
        }

        if (!empty($this->settings['category'])) {
            $allCategories = $this->categoryRepository->findByParent($this->settings['category']);
            $this->view->assign('categories', $allCategories);
        }

        // pass storagePid to template in order to use it in ajax call listAction()
        // TODO: Remove condition as soon, as TYPO3 v11 is not supported anymore
        $versionInformation = GeneralUtility::makeInstance(Typo3Version::class);
        $contentObject = ($versionInformation->getMajorVersion() >= 12) ?
            $this->request->getAttribute('currentContentObject')->data :
            $this->configurationManager->getContentObject()->data;

        $storagePid = $contentObject['pages'];
        if ($storagePid) {
            $this->view->assign('storagePid', $storagePid);
        }

        $this->view->assignMultiple([
            'contentObject' => $contentObject
        ]);
        return $this->htmlResponse();
    }

    /**
     * Get list of events
     * If "type" is provided, it will return values as json object
     *
     * @return ResponseInterface
     */
    public function listAction(): ResponseInterface
    {
        $params = $this->getRequest()->getQueryParams();

        $type = $params['type'];
        $storagePid = $params['storage'];

        // set start day -1 in order to get all events for selected time span
        $selectedStart = new \DateTime($_POST['start']);
        $selectedStart = $selectedStart
            ->modify('-1 day')
            ->setTime(00, 00, 00);

        // set end day +1 in order to get all events for selected time span
        $selectedEnd = new \DateTime($_POST['end']);
        $selectedEnd = $selectedEnd
            ->modify('+1 day')
            ->setTime(23, 59, 59);

        // Limit the timespan between start and end date to max 50 days in order to prevent Denial of Service attacks
        $daysTimespan = $selectedStart->diff($selectedEnd);
        if ($daysTimespan->format('%a') >= 50) {
            $selectedEnd = clone $selectedStart;
            $selectedEnd = $selectedEnd->modify("+50 days");
        }

        if (!empty($storagePid)) {
            // sanitize input
            $storagePid = GeneralUtility::intExplode(',', $storagePid, true);
            $storagePid = implode(',', $storagePid);

            // set storagePid
            $this->configurationManager->setConfiguration(
                [
                    'persistence' => [
                        'storagePid' => $storagePid
                    ],
                ]
            );
        }

        $search = $this->indexRepository->findByTimeSlot($selectedStart, $selectedEnd);

        if ($type == 1573738558) {
            $items = [];
            $i = 0;
            /** @var \HDNET\Calendarize\Domain\Model\Index $el */
            foreach ($search as $el) {
                if ($el->getUniqueRegisterKey() === 'Event' || $el->getUniqueRegisterKey() === 'News') {
                    $uri = $this->uriBuilder
                        ->reset()
                        ->setTargetPageUid((int)$this->settings['pid']['defaultDetailPid'])
                        ->uriFor(
                            'detail',
                            ['index' => $el->getUid()],
                            'Calendar',
                            'calendarize',
                            'calendar'
                        );

                    $uriAjax = $this->uriBuilder
                        ->reset()
                        ->setTargetPageUid((int)$this->settings['pid']['defaultDetailPid'])
                        ->setTargetPageType(1573760945)
                        ->uriFor(
                            'detail',
                            ['index' => $el->getUid()],
                            'Cal',
                            'mdfullcalendar',
                            'caldetail'
                        );

                    if ($el->isAllDay()) {
                        $start = $el->getStartDateComplete()->format('Y-m-d');
                        $end = $el->getEndDateComplete()->modify('+1 day')->format('Y-m-d');
                    } else {
                        $start = $el->getStartDateComplete()->format('c');
                        $end = $el->getEndDateComplete()->format('c');
                    }

                    $items[$i] = [
                        'id' => $el->getUid(),
                        'title' => $el->getOriginalObject()->getTitle(),
                        'description' => $el->getOriginalObject()->getDescription(),
                        'start' => $start,
                        'end' => $end,
                        'allDay' => $el->isAllDay(),
                        'className' => 'cal-item' . $this->getCssClasses($el->getOriginalObject()->getCategories()),
                        'url' => $uri,
                        'uriAjax' => $uriAjax,
                    ];

                    if ($el->getUniqueRegisterKey() === 'News') {
                        $items[$i]['abstract'] = $el->getOriginalObject()->getTeaser();
                    } else {
                        $items[$i]['abstract'] = $el->getOriginalObject()->getAbstract();
                        $items[$i]['location'] = $el->getOriginalObject()->getLocation();
                        $items[$i]['resourceId'] = $this->getResourceId($el->getOriginalObject()->getLocation());
                        $items[$i]['locationLink'] = $el->getOriginalObject()->getLocationLink();
                        $items[$i]['organizer'] = $el->getOriginalObject()->getOrganizer();
                        $items[$i]['organizerLink'] = $el->getOriginalObject()->getOrganizerLink();
                    }

                    $i++;
                }
            }

            return $this->jsonResponse(json_encode($items));
        } else {
            $this->view->assign('index', $search);
        }

        return $this->htmlResponse();
    }

    /**
     * Get one event
     *
     * @param \HDNET\Calendarize\Domain\Model\Index $index
     * @return void
     */
    public function detailAction(\HDNET\Calendarize\Domain\Model\Index $index): ResponseInterface
    {
        $this->view->assign('index', $index);
        return $this->htmlResponse();
    }

    /**
     * @return ServerRequestInterface
     */
    protected function getRequest(): ServerRequestInterface
    {
        return $GLOBALS['TYPO3_REQUEST'];
    }

    /**
     * This function returns a string with all CSS classes of an item
     *
     * @param $categories The ObjectStorage with the categories
     * @return string
     */
    protected function getCssClasses($categories)
    {
        $cssClasses = '';

        foreach ($categories as $category) {
            $cssClasses .= ' category' . $category->getUid();
        }

        return $cssClasses;
    }

    protected function getResourceId($location)
    {
        // these magic numbers correspond to the ids of resources defined when initializing fullcalendar
        // cf. Show.html:57
        switch ($location) {
            case 'Saal 1':
                return 1;
            case 'Saal 2':
                return 2;
            case 'Saal 3':
                return 3;
            default:
                return null;
        }
    }
}

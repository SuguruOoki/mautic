<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\LeadBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\InputHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditlogController extends CommonController
{
    use LeadAccessTrait;
    use LeadDetailsTrait;

    public function indexAction(Request $request, $leadId, $page = 1)
    {
        if (empty($leadId)) {
            return $this->accessDenied();
        }

        $lead = $this->checkLeadAccess($leadId, 'view');
        if ($lead instanceof Response) {
            return $lead;
        }

        $this->setListFilters();

        $session = $this->get('session');
        if ($request->getMethod() == 'POST' && $request->request->has('search')) {
            $filters = [
                'search'        => InputHelper::clean($request->request->get('search')),
                'includeEvents' => InputHelper::clean($request->request->get('includeEvents', [])),
                'excludeEvents' => InputHelper::clean($request->request->get('excludeEvents', [])),
            ];
            $session->set('mautic.lead.'.$leadId.'.timeline.filters', $filters);
        } else {
            $filters = null;
        }

        $order = [
            $session->get('mautic.lead.'.$leadId.'.timeline.orderby'),
            $session->get('mautic.lead.'.$leadId.'.timeline.orderbydir'),
        ];

        $events = $this->getEngagements($lead, $filters, $order, $page);

        return $this->delegateView(
            [
                'viewParameters' => [
                    'lead'   => $lead,
                    'page'   => $page,
                    'events' => $events,
                ],
                'passthroughVars' => [
                    'route'         => false,
                    'mauticContent' => 'leadTimeline',
                    'timelineCount' => $events['total'],
                ],
                'contentTemplate' => 'MauticLeadBundle:Timeline:list.html.php',
            ]
        );
    }

    /**
     * @return array|\Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function batchExportAction(Request $request, $leadId)
    {
        if (empty($leadId)) {
            return $this->accessDenied();
        }

        $lead = $this->checkLeadAccess($leadId, 'view');
        if ($lead instanceof Response) {
            return $lead;
        }

        $this->setListFilters();

        $session = $this->get('session');
        if ($request->getMethod() == 'POST' && $request->request->has('search')) {
            $filters = [
                'search'        => InputHelper::clean($request->request->get('search')),
                'includeEvents' => InputHelper::clean($request->request->get('includeEvents', [])),
                'excludeEvents' => InputHelper::clean($request->request->get('excludeEvents', [])),
            ];
            $session->set('mautic.lead.'.$leadId.'.timeline.filters', $filters);
        } else {
            $filters = null;
        }

        $order = [
            $session->get('mautic.lead.'.$leadId.'.timeline.orderby'),
            $session->get('mautic.lead.'.$leadId.'.timeline.orderbydir'),
        ];

        $dataType = $this->request->get('filetype', 'csv');

        $resultsCallback = function ($event) {
            $eventLabel = (isset($event['eventLabel'])) ? $event['eventLabel'] : $event['eventType'];
            if (is_array($eventLabel)) {
                $eventLabel = $eventLabel['label'];
            }

            return [
                'eventName'      => $eventLabel,
                'eventType'      => isset($event['eventType']) ? $event['eventType'] : '',
                'eventTimestamp' => $this->get('mautic.helper.template.date')->toText($event['timestamp'], 'local', 'Y-m-d H:i:s', true),
            ];
        };

        $results    = $this->getEngagements($lead, $filters, $order, 1, 200);
        $count      = $results['total'];
        $items      = $results['events'];
        $iterations = ceil($count / 200);
        $loop       = 1;

        // Max of 50 iterations for 10K result export
        if ($iterations > 50) {
            $iterations = 50;
        }

        $toExport = [];

        while ($loop <= $iterations) {
            if (is_callable($resultsCallback)) {
                foreach ($items as $item) {
                    $toExport[] = $resultsCallback($item);
                }
            } else {
                foreach ($items as $item) {
                    $toExport[] = (array) $item;
                }
            }

            $items = $this->getEngagements($lead, $filters, $order, $loop + 1, 200);

            $this->getDoctrine()->getManager()->clear();

            ++$loop;
        }

        return $this->exportResultsAs($toExport, $dataType, 'contact_timeline');
    }
}

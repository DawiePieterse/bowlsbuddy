<?php

namespace Calendar\View\Helper;

use Square\Entity\Square;
use Square\Manager\GreenManager;
use Zend\View\Helper\AbstractHelper;

class EventsForCell extends AbstractHelper
{

    protected $greenManager;

    public function __construct(GreenManager $greenManager)
    {
        $this->greenManager = $greenManager;
    }


    public function __invoke(array $eventsForCol, Square $square)
    {
        $eventsForCell = array();

        foreach ($eventsForCol as $eid => $event) {
            if ($this->greenManager->eventCoversSquare($event, $square)) {
                if ($event->need('status') == 'enabled') {
                    $eventsForCell[$eid] = $event;
                }
            }
        }

        return $eventsForCell;
    }

}
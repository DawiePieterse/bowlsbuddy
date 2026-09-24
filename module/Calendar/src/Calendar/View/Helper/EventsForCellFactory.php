<?php

namespace Calendar\View\Helper;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class EventsForCellFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new EventsForCell($sm->getServiceLocator()->get('Square\Manager\GreenManager'));
    }

}

<?php

namespace Calendar\View\Helper\Cell;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class CellLogicFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new CellLogic($sm->getServiceLocator()->get('Square\Manager\GreenManager'));
    }

}

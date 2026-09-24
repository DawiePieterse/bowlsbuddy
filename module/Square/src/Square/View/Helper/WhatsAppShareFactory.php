<?php

namespace Square\View\Helper;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class WhatsAppShareFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new WhatsAppShare($sm->getServiceLocator()->get('Square\Manager\GreenManager'));
    }

}

<?php

namespace Square\Manager;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class GreenManagerFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new GreenManager(
            $sm->get('Square\Manager\SquareManager'),
            $sm->get('Base\Manager\OptionManager'));
    }

}

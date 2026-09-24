<?php

namespace Square\View\Helper;

use Booking\Entity\Booking;
use DateTime;
use IntlDateFormatter;
use Square\Entity\Square;
use Square\Manager\GreenManager;
use Zend\View\Helper\AbstractHelper;

class WhatsAppShare extends AbstractHelper
{

    protected $greenManager;

    public function __construct(GreenManager $greenManager)
    {
        $this->greenManager = $greenManager;
    }

    public function __invoke(Booking $booking, Square $square, DateTime $dateTimeStart, DateTime $dateTimeEnd)
    {
        $view = $this->getView();

        $rink = $square->need('name');
        $green = $this->greenManager->getGreenOf($square);

        if ($green !== $rink) {
            $rink .= ' (' . sprintf($view->t('Green %s'), $green) . ')';
        }

        $lines = array(
            '*' . $view->option('client.name.full') . ' - ' . $view->t('Rink booking') . '*',
            $view->option('subject.square.type') . ' ' . $rink,
            $view->dateFormat($dateTimeStart, IntlDateFormatter::FULL),
            $view->timeFormat($dateTimeStart) . ' - ' . $view->timeFormat($dateTimeEnd, true, null, true),
            $view->t('Players') . ': ' . implode(', ', $view->calendarBookingNames()->names($booking)),
            $view->serverUrl($view->url('frontend')),
        );

        return sprintf('<a href="https://wa.me/?text=%s" target="_blank" rel="noopener" class="default-button whatsapp-share">%s</a>',
            rawurlencode(implode("\n", $lines)), $view->t('Share via WhatsApp'));
    }

}

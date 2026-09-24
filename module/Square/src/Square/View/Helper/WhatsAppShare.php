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

    /* @return string|self share button for the booking, or the helper itself when called without a booking */
    public function __invoke(?Booking $booking = null, ?Square $square = null, ?DateTime $dateTimeStart = null, ?DateTime $dateTimeEnd = null)
    {
        if (! $booking) {
            return $this;
        }

        $view = $this->getView();

        $rink = $square->need('name');
        $green = $this->greenManager->getGreenOf($square);

        if ($green !== $rink) {
            $rink .= ' (' . sprintf($view->t('Green %s'), $green) . ')';
        }

        return $this->link(implode("\n", array(
            '*' . $view->option('client.name.full') . ' - ' . $view->t('Rink booking') . '*',
            $view->option('subject.square.type') . ' ' . $rink,
            $view->dateFormat($dateTimeStart, IntlDateFormatter::FULL),
            $view->timeRange($dateTimeStart, $dateTimeEnd, '%s - %s'),
            $view->t('Players') . ': ' . implode(', ', $view->calendarBookingNames()->names($booking)),
            $view->serverUrl($view->url('frontend')),
        )), $view->t('Share via WhatsApp'));
    }

    /* Button that opens WhatsApp with the text prefilled; the user picks the recipients there. */
    public function link($text, $label)
    {
        return sprintf('<a href="https://wa.me/?text=%s" target="_blank" rel="noopener" class="default-button whatsapp-share">%s</a>',
            rawurlencode($text), $this->getView()->escapeHtml($label));
    }

}

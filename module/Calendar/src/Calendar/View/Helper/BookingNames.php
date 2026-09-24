<?php

namespace Calendar\View\Helper;

use Booking\Entity\Booking;
use Zend\View\Helper\AbstractHelper;

class BookingNames extends AbstractHelper
{

    /* @return string|self escaped "Booker, Player 2, Player 3", or the helper itself when called without a booking */
    public function __invoke(?Booking $booking = null)
    {
        if (! $booking) {
            return $this;
        }

        return $this->getView()->escapeHtml(implode(', ', $this->names($booking)));
    }

    /* @return array unescaped names of the booker and the additional players */
    public function names(Booking $booking)
    {
        $names = array($booking->getMeta('custom-name') ?: $booking->needExtra('user')->need('alias'));

        $playerNames = @unserialize((string) $booking->getMeta('player-names'));

        if (is_array($playerNames)) {
            foreach ($playerNames as $playerName) {
                if (isset($playerName['value']) && trim($playerName['value'])) {
                    $names[] = trim($playerName['value']);
                }
            }
        }

        return $names;
    }

}

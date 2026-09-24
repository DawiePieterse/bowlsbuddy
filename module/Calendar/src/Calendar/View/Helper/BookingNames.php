<?php

namespace Calendar\View\Helper;

use Booking\Entity\Booking;
use Zend\View\Helper\AbstractHelper;

class BookingNames extends AbstractHelper
{

    /* @return string escaped "Booker, Player 2, Player 3" */
    public function __invoke(Booking $booking)
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

        return $this->getView()->escapeHtml(implode(', ', $names));
    }

}

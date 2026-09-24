<?php

namespace Frontend\Controller;

use DateTime;
use RuntimeException;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Validator\Csrf;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{

    public function indexAction()
    {
        if (! $this->params()->fromQuery('squares')) {
            return $this->greensOverview();
        }

        $calendarViewModel = $this->forward()->dispatch('Calendar\Controller\Calendar', ['action' => 'index']);
        $calendarViewModel->setCaptureTo('calendar');

        $dateStart = $calendarViewModel->getVariable('dateStart');
        $dateNow = $calendarViewModel->getVariable('dateNow');
        $squares = $calendarViewModel->getVariable('squares');
        $squaresFilter = $calendarViewModel->getVariable('squaresFilter');
        $user = $calendarViewModel->getVariable('user');

        $greenManager = @$this->getServiceLocator()->get('Square\Manager\GreenManager');

        $greensShown = array();

        foreach ($squares as $square) {
            $greensShown[$greenManager->getGreenOf($square)] = true;
        }

        $green = count($greensShown) == 1 ? key($greensShown) : null;

        $this->redirectBack()->setOrigin('frontend', [], ['query' => [
            'date' => $dateStart->format('Y-m-d'),
            'squares' => $squaresFilter,
        ]]);

        $viewModel = new ViewModel(array(
            'dateStart' => $dateStart,
            'dateNow' => $dateNow,
            'squaresFilter' => $squaresFilter,
            'user' => $user,
            'green' => $green,
            'greenClosed' => $green !== null && $greenManager->isClosed($green, $dateStart),
        ));

        $viewModel->addChild($calendarViewModel);

        return $viewModel;
    }

    public function greenToggleAction()
    {
        $this->authorize('admin.event');

        $request = $this->getRequest();

        if (! $request->isPost()) {
            return $this->redirect()->toRoute('frontend');
        }

        if (! $this->greensCsrf()->isValid($request->getPost('csrf'))) {
            throw new RuntimeException('This form has expired, please reload the page and try again');
        }

        $greenManager = @$this->getServiceLocator()->get('Square\Manager\GreenManager');

        $green = $request->getPost('green');
        $date = DateTime::createFromFormat('!Y-m-d', (string) $request->getPost('date'));

        if (! isset($greenManager->getGreens()[$green]) || ! $date) {
            throw new RuntimeException('The passed green or date is invalid');
        }

        $closed = $request->getPost('closed') === '1';

        $greenManager->setClosed($green, $date, $closed);

        $this->flashMessenger()->addSuccessMessage(sprintf($this->t($closed ? 'Green %s is now closed on %s' : 'Green %s is now open on %s'),
            $green, $this->dateFormat($date, \IntlDateFormatter::MEDIUM)));

        return $this->redirect()->toRoute('frontend');
    }

    public function daySheetAction()
    {
        $this->authorize('admin.booking');

        $date = DateTime::createFromFormat('!Y-m-d', (string) $this->params()->fromQuery('date'));

        if (! $date) {
            throw new RuntimeException('The passed date is invalid');
        }

        $serviceManager = @$this->getServiceLocator();
        $greenManager = $serviceManager->get('Square\Manager\GreenManager');

        $reservations = $serviceManager->get('Booking\Manager\ReservationManager')
            ->getInRange($date, (clone $date)->setTime(23, 59, 59));
        $bookings = $serviceManager->get('Booking\Manager\BookingManager')->getByReservations($reservations);
        $serviceManager->get('User\Manager\UserManager')->getByBookings($bookings);

        $bookingsBySquare = array();

        foreach ($reservations as $reservation) {
            $booking = $reservation->getExtra('booking');

            if ($booking && $booking->need('status') != 'cancelled') {
                $bookingsBySquare[$booking->need('sid')][] = array('reservation' => $reservation, 'booking' => $booking);
            }
        }

        $greens = $greenManager->getGreens();

        $dayStart = clone $date;
        $dayEnd = (clone $date)->modify('+1 day');

        $eventsBySquare = array();

        foreach ($serviceManager->get('Event\Manager\EventManager')->getInRange($dayStart, $dayEnd) as $event) {
            if ($event->need('status') != 'enabled') {
                continue;
            }

            $entry = array(
                'name' => $event->getMeta('name'),
                'start' => max(new DateTime($event->need('datetime_start')), $dayStart),
                'end' => min(new DateTime($event->need('datetime_end')), $dayEnd),
            );

            foreach ($greens as $squares) {
                foreach ($squares as $sid => $square) {
                    if (is_null($event->get('sid')) || $event->get('sid') == $sid) {
                        $eventsBySquare[$sid][] = $entry;
                    }
                }
            }
        }

        $viewModel = new ViewModel(array(
            'date' => $date,
            'greens' => $greens,
            'closed' => $greenManager->getClosedOn($date),
            'bookingsBySquare' => $bookingsBySquare,
            'eventsBySquare' => $eventsBySquare,
        ));

        $viewModel->setTemplate('frontend/index/day-sheet');
        $viewModel->setTerminal(true);

        return $viewModel;
    }

    protected function greensOverview()
    {
        $serviceManager = @$this->getServiceLocator();

        $greenManager = $serviceManager->get('Square\Manager\GreenManager');
        $squareManager = $serviceManager->get('Square\Manager\SquareManager');
        $squareValidator = $serviceManager->get('Square\Service\SquareValidator');
        $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
        $eventManager = $serviceManager->get('Event\Manager\EventManager');
        $user = $serviceManager->get('User\Manager\UserSessionManager')->getSessionUser();

        $greens = $greenManager->getGreens();

        /* Sets the time_start_sec and time_end_sec extras on each rink */
        $squareManager->getMinStartTime();
        $squareManager->getMaxEndTime();

        $rangeStart = new DateTime('today');
        $rangeEnd = new DateTime('today +14 days');

        $reservations = $reservationManager->getInRange($rangeStart, $rangeEnd, null, null, false);
        $reservationManager->getSecondsPerDay($reservations);
        $serviceManager->get('Booking\Manager\BookingManager')->getByReservations($reservations);

        $events = $eventManager->getInRange($rangeStart, $rangeEnd);
        $eventManager->getSecondsPerDay($events);

        $occupancy = $this->collectOccupancy($reservations);
        $blocked = $this->collectBlocked($events);
        $now = time();

        $days = array();
        $day = clone $rangeStart;

        for ($i = 0; $i < 14; $i++) {
            if (! $squareValidator->isDayHidden($day)) {
                $available = array();

                foreach ($greens as $green => $squares) {
                    $available[$green] = 0;

                    foreach ($squares as $square) {
                        if ($this->isRinkAvailable($square, $day->getTimestamp(), $occupancy[$day->format('Y-m-d')][$square->need('sid')] ?? array(), $blocked, $now)) {
                            $available[$green]++;
                        }
                    }
                }

                $days[] = array('date' => clone $day, 'closed' => $greenManager->getClosedOn($day), 'available' => $available);
            }

            $day->modify('+1 day');
        }

        $this->redirectBack()->setOrigin('frontend');

        $viewModel = new ViewModel(array(
            'greens' => $greens,
            'days' => $days,
            'user' => $user,
            'csrfHash' => ($user && $user->can('admin.event')) ? $this->greensCsrf()->getHash() : null,
        ));

        $viewModel->setTemplate('frontend/index/greens');

        return $viewModel;
    }

    /* @return array date => sid => list of [start sec, end sec, quantity] of active public bookings */
    protected function collectOccupancy(array $reservations)
    {
        $occupancy = array();

        foreach ($reservations as $reservation) {
            $booking = $reservation->getExtra('booking');

            if (! $booking || $booking->need('status') == 'cancelled' || $booking->need('visibility') != 'public') {
                continue;
            }

            $occupancy[$reservation->need('date')][$booking->need('sid')][] = array(
                $reservation->needExtra('time_start_sec'),
                $reservation->needExtra('time_end_sec'),
                (int) $booking->need('quantity'),
            );
        }

        return $occupancy;
    }

    /* @return array list of [sid or null for all rinks, start timestamp, end timestamp] of enabled events */
    protected function collectBlocked(array $events)
    {
        $blocked = array();

        foreach ($events as $event) {
            if ($event->need('status') == 'enabled') {
                $blocked[] = array($event->get('sid'), $event->needExtra('datetime_start')->getTimestamp(), $event->needExtra('datetime_end')->getTimestamp());
            }
        }

        return $blocked;
    }

    /* A rink is available when at least one of its time slots that day has not started and can still be booked. */
    protected function isRinkAvailable($square, $dayTimestamp, array $taken, array $blocked, $now)
    {
        $sid = $square->need('sid');
        $capacity = (int) $square->need('capacity');
        $capacityHeterogenic = $square->need('capacity_heterogenic');
        $timeBlock = (int) $square->need('time_block');
        $timeEnd = $square->needExtra('time_end_sec');

        for ($slotStart = $square->needExtra('time_start_sec'); $slotStart < $timeEnd; $slotStart += $timeBlock) {
            $slotEnd = $slotStart + $timeBlock;

            if ($dayTimestamp + $slotStart <= $now) {
                continue;
            }

            foreach ($blocked as list($blockedSid, $blockedStart, $blockedEnd)) {
                if ((is_null($blockedSid) || $blockedSid == $sid) && $blockedStart < $dayTimestamp + $slotEnd && $blockedEnd > $dayTimestamp + $slotStart) {
                    continue 2;
                }
            }

            $quantity = 0;

            foreach ($taken as list($takenStart, $takenEnd, $takenQuantity)) {
                if ($takenStart < $slotEnd && $takenEnd > $slotStart) {
                    $quantity += $takenQuantity;
                }
            }

            if ($quantity < $capacity && ! ($quantity && ! $capacityHeterogenic)) {
                return true;
            }
        }

        return false;
    }

    protected function greensCsrf()
    {
        return new Csrf(array('name' => 'greens_csrf', 'timeout' => 3600));
    }

}

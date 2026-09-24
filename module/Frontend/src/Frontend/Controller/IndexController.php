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
        $closed = array();

        foreach ($greens as $green => $squares) {
            $closed[$green] = $greenManager->isClosed($green, $date);
        }

        $viewModel = new ViewModel(array(
            'date' => $date,
            'greens' => $greens,
            'closed' => $closed,
            'bookingsBySquare' => $bookingsBySquare,
        ));

        $viewModel->setTemplate('frontend/index/day-sheet');
        $viewModel->setTerminal(true);

        return $viewModel;
    }

    protected function greensOverview()
    {
        $serviceManager = @$this->getServiceLocator();

        $greenManager = $serviceManager->get('Square\Manager\GreenManager');
        $user = $serviceManager->get('User\Manager\UserSessionManager')->getSessionUser();

        $greens = $greenManager->getGreens();

        $rangeStart = new DateTime('today');
        $rangeEnd = new DateTime('today +14 days');

        $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
        $reservations = $reservationManager->getInRange($rangeStart, $rangeEnd);
        $serviceManager->get('Booking\Manager\BookingManager')->getByReservations($reservations);
        $events = $serviceManager->get('Event\Manager\EventManager')->getInRange($rangeStart, $rangeEnd);

        $occupancy = $this->collectOccupancy($reservations);

        $days = array();
        $day = clone $rangeStart;

        for ($i = 0; $i < 14; $i++) {
            if (! $this->isDayHidden($day)) {
                $closed = array();
                $available = array();

                foreach ($greens as $green => $squares) {
                    $closed[$green] = $greenManager->isClosed($green, $day);
                    $available[$green] = 0;

                    foreach ($squares as $square) {
                        if ($this->isRinkAvailable($square, $day, $occupancy, $events)) {
                            $available[$green]++;
                        }
                    }
                }

                $days[] = array('date' => clone $day, 'closed' => $closed, 'available' => $available);
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
                $this->timeToSeconds($reservation->need('time_start')),
                $this->timeToSeconds($reservation->need('time_end')),
                (int) $booking->need('quantity'),
            );
        }

        return $occupancy;
    }

    /* A rink is available when at least one of its time slots that day has not started and can still be booked. */
    protected function isRinkAvailable($square, DateTime $day, array $occupancy, array $events)
    {
        $sid = $square->need('sid');
        $capacity = (int) $square->need('capacity');
        $capacityHeterogenic = $square->need('capacity_heterogenic');
        $timeBlock = (int) $square->need('time_block');
        $now = new DateTime();

        $taken = $occupancy[$day->format('Y-m-d')][$sid] ?? array();

        for ($slotStart = $this->timeToSeconds($square->need('time_start')); $slotStart < $this->timeToSeconds($square->need('time_end')); $slotStart += $timeBlock) {
            $slotEnd = $slotStart + $timeBlock;

            $slotStartDateTime = (clone $day)->modify('+' . $slotStart . ' sec');
            $slotEndDateTime = (clone $day)->modify('+' . $slotEnd . ' sec');

            if ($slotStartDateTime <= $now) {
                continue;
            }

            foreach ($events as $event) {
                if ((is_null($event->get('sid')) || $event->get('sid') == $sid) &&
                    new DateTime($event->need('datetime_start')) < $slotEndDateTime &&
                    new DateTime($event->need('datetime_end')) > $slotStartDateTime) {

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

    protected function timeToSeconds($time)
    {
        $parts = explode(':', $time);

        return $parts[0] * 3600 + $parts[1] * 60 + ($parts[2] ?? 0);
    }

    protected function greensCsrf()
    {
        return new Csrf(array('name' => 'greens_csrf', 'timeout' => 3600));
    }

    /* Mirrors the day exception rules of the calendar ("Sunday", "2026-12-25", "+2026-12-26" to force show) */
    protected function isDayHidden(DateTime $date)
    {
        $hidden = false;
        $forced = false;

        foreach (preg_split('~(\\n|,)~', (string) $this->option('service.calendar.day-exceptions')) as $dayException) {
            $dayException = trim($dayException);

            if (! $dayException) {
                continue;
            }

            if ($dayException[0] === '+') {
                if (trim($dayException, '+') === $date->format($this->t('Y-m-d'))) {
                    $forced = true;
                }
            } else if ($dayException === $date->format($this->t('Y-m-d')) || $dayException === $this->t($date->format('l'))) {
                $hidden = true;
            }
        }

        return $hidden && ! $forced;
    }

}

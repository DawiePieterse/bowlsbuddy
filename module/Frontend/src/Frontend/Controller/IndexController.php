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

    protected function greensOverview()
    {
        $serviceManager = @$this->getServiceLocator();

        $greenManager = $serviceManager->get('Square\Manager\GreenManager');
        $user = $serviceManager->get('User\Manager\UserSessionManager')->getSessionUser();

        $greens = $greenManager->getGreens();

        $days = array();
        $day = new DateTime('today');

        for ($i = 0; $i < 14; $i++) {
            if (! $this->isDayHidden($day)) {
                $closed = array();

                foreach ($greens as $green => $squares) {
                    $closed[$green] = $greenManager->isClosed($green, $day);
                }

                $days[] = array('date' => clone $day, 'closed' => $closed);
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

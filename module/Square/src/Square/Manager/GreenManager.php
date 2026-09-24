<?php

namespace Square\Manager;

use Base\Manager\OptionManager;
use DateTime;
use Square\Entity\Square;

/* A rink belongs to the green named by the prefix of its name: "A-1" is on green "A". */
class GreenManager
{

    const CLOSED_OPTION = 'service.greens.closed';

    protected $squareManager;
    protected $optionManager;

    protected $closed;

    public function __construct(SquareManager $squareManager, OptionManager $optionManager)
    {
        $this->squareManager = $squareManager;
        $this->optionManager = $optionManager;
    }

    public function getGreenOf(Square $square)
    {
        $name = $square->need('name');
        $dashPosition = strpos($name, '-');

        if ($dashPosition === false) {
            return trim($name);
        }

        return trim(substr($name, 0, $dashPosition));
    }

    /**
     * @return array green => [sid => Square], sorted by green and rink name
     */
    public function getGreens()
    {
        $greens = array();

        foreach ($this->squareManager->getAllVisible() as $sid => $square) {
            $greens[$this->getGreenOf($square)][$sid] = $square;
        }

        ksort($greens, SORT_NATURAL);

        foreach ($greens as &$squares) {
            uasort($squares, function($a, $b) {
                return strnatcmp($a->need('name'), $b->need('name'));
            });
        }

        return $greens;
    }

    public function isClosed($green, DateTime $date)
    {
        $closed = $this->loadClosed();

        return isset($closed[$date->format('Y-m-d') . ':' . $green]);
    }

    public function isSquareClosed(Square $square, DateTime $date)
    {
        return $this->isClosed($this->getGreenOf($square), $date);
    }

    public function setClosed($green, DateTime $date, $closed)
    {
        $entries = $this->loadClosed();
        $key = $date->format('Y-m-d') . ':' . $green;

        if ($closed) {
            $entries[$key] = true;
        } else {
            unset($entries[$key]);
        }

        $today = (new DateTime())->format('Y-m-d');

        foreach (array_keys($entries) as $entry) {
            if (substr($entry, 0, 10) < $today) {
                unset($entries[$entry]);
            }
        }

        ksort($entries);

        $this->optionManager->set(self::CLOSED_OPTION, implode("\n", array_keys($entries)));
        $this->closed = $entries;
    }

    protected function loadClosed()
    {
        if (is_null($this->closed)) {
            $this->closed = array();

            foreach (preg_split('~\R~', (string) $this->optionManager->get(self::CLOSED_OPTION, '')) as $entry) {
                $entry = trim($entry);

                if ($entry) {
                    $this->closed[$entry] = true;
                }
            }
        }

        return $this->closed;
    }

}

<?php

return array(
    'router' => array(
        'routes' => array(
            'frontend' => array(
                'type' => 'Literal',
                'options' => array(
                    'route' => '/',
                    'defaults' => array(
                        'controller' => 'Frontend\Controller\Index',
                        'action' => 'index',
                    ),
                ),
            ),
            'day-sheet' => array(
                'type' => 'Literal',
                'options' => array(
                    'route' => '/greens/day-sheet',
                    'defaults' => array(
                        'controller' => 'Frontend\Controller\Index',
                        'action' => 'day-sheet',
                    ),
                ),
            ),
            'green-toggle' => array(
                'type' => 'Literal',
                'options' => array(
                    'route' => '/greens/toggle',
                    'defaults' => array(
                        'controller' => 'Frontend\Controller\Index',
                        'action' => 'green-toggle',
                    ),
                ),
            ),
        ),
    ),

    'controllers' => array(
        'invokables' => array(
            'Frontend\Controller\Index' => 'Frontend\Controller\IndexController',
        ),
    ),

    'view_manager' => array(
        'template_path_stack' => array(
            __DIR__ . '/../view',
        ),
    ),
);
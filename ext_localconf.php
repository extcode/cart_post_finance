<?php

defined('TYPO3_MODE') or die();

// configure plugins

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
    'CartPostFinance',
    'Cart',
    [
        \Extcode\CartPostFinance\Controller\Order\PaymentController::class => 'success, error',
    ],
    [
        \Extcode\CartPostFinance\Controller\Order\PaymentController::class => 'success, error',
    ]
);

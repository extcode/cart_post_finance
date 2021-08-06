<?php

namespace Extcode\CartPostFinance\Controller\Order;

/*
 * This file is part of the package extcode/cart-post-finance.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Extcode\Cart\Domain\Model\Cart;
use Extcode\Cart\Domain\Repository\CartRepository;
use Extcode\Cart\Domain\Repository\Order\PaymentRepository;
use Extcode\Cart\Domain\Repository\Order\TransactionRepository;
use Extcode\Cart\Service\SessionHandler;
use Extcode\CartPostFinance\Event\Order\AuthorizedEvent;
use Extcode\CartPostFinance\Event\Order\CancelEvent;
use Extcode\CartPostFinance\Event\Order\FulfillEvent;
use PostFinanceCheckout\Sdk\ApiClient;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class PaymentController extends ActionController
{
    /**
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @var SessionHandler
     */
    protected $sessionHandler;

    /**
     * @var CartRepository
     */
    protected $cartRepository;

    /**
     * @var PaymentRepository
     */
    protected $paymentRepository;

    /**
     * @var TransactionRepository
     */
    protected $transactionRepository;

    /**
     * @var Cart
     */
    protected $cart;

    /**
     * @var array
     */
    protected $cartPostFinanceConf = [];

    /**
     * @var array
     */
    protected $cartConf = [];

    public function __construct(
        PersistenceManager $persistenceManager,
        SessionHandler $sessionHandler,
        CartRepository $cartRepository,
        PaymentRepository $paymentRepository,
        TransactionRepository $transactionRepository
    ) {
        $this->persistenceManager = $persistenceManager;
        $this->sessionHandler = $sessionHandler;
        $this->cartRepository = $cartRepository;
        $this->paymentRepository = $paymentRepository;
        $this->transactionRepository = $transactionRepository;
    }

    protected function initializeAction(): void
    {
        $this->cartPostFinanceConf = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
            'CartPostFinance'
        );

        $this->cartConf = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
            'Cart'
        );
    }

    public function successAction(): void
    {
        if ($this->request->hasArgument('hash') && !empty($this->request->getArgument('hash'))) {
            $this->loadCartByHash($this->request->getArgument('hash'));

            if ($this->cart) {
                $orderItem = $this->cart->getOrderItem();

                $spaceId = $this->cartPostFinanceConf['spaceId'];
                $userId = $this->cartPostFinanceConf['userId'];
                $secret = $this->cartPostFinanceConf['secret'];

                $payment = $this->cart->getOrderItem()->getPayment();
                $paymentTransactions = $payment->getTransactions()->getArray();
                $paymentTransaction = end($paymentTransactions);

                $client = new ApiClient($userId, $secret);
                $transaction = $client->getTransactionService()->read($spaceId, (int)$paymentTransaction->getTxnId());

                if ($transaction->getState() !== $paymentTransaction->getExternalStatusCode()) {
                    $paymentTransaction->setExternalStatusCode($transaction->getState());
                    $this->transactionRepository->update($paymentTransaction);
                    $this->persistenceManager->persistAll();

                    if ($transaction->getState() === 'FULFILL') {
                        $payment = $paymentTransaction->getPayment();
                        $payment->setStatus('paid');
                        $this->paymentRepository->update($payment);

                        $event = new FulfillEvent($this->cart->getCart(), $orderItem, $this->cartConf);
                    } elseif ($transaction->getState() === 'AUTHORIZED') {
                        $payment = $paymentTransaction->getPayment();
                        $payment->setStatus('authorized');
                        $this->paymentRepository->update($payment);

                        $event = new AuthorizedEvent($this->cart->getCart(), $orderItem, $this->cartConf);
                    }

                    $this->eventDispatcher->dispatch($event);
                }

                $this->redirect('show', 'Cart\Order', 'Cart', ['orderItem' => $orderItem]);
            } else {
                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartpostfinance.controller.order.payment.action.success.error_occured',
                        'cart_post_finance'
                    ),
                    '',
                    AbstractMessage::ERROR
                );
            }
        } else {
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartpostfinance.controller.order.payment.action.success.access_denied',
                    'cart_post_finance'
                ),
                '',
                AbstractMessage::ERROR
            );
        }
    }

    public function errorAction(): void
    {
        if ($this->request->hasArgument('hash') && !empty($this->request->getArgument('hash'))) {
            $this->loadCartByHash($this->request->getArgument('hash'), 'FHash');

            if ($this->cart) {
                $orderItem = $this->cart->getOrderItem();
                $payment = $orderItem->getPayment();

                $this->restoreCartSession();

                $payment->setStatus('canceled');

                $this->paymentRepository->update($payment);
                $this->persistenceManager->persistAll();

                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartpostfinance.controller.order.payment.action.cancel.successfully_canceled',
                        'cart_post_finance'
                    )
                );

                $cancelEvent = new CancelEvent($this->cart->getCart(), $orderItem, $this->cartConf);
                $this->eventDispatcher->dispatch($cancelEvent);

                $this->redirect('show', 'Cart\Cart', 'Cart');
            } else {
                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartpostfinance.controller.order.payment.action.cancel.error_occured',
                        'cart_post_finance'
                    ),
                    '',
                    AbstractMessage::ERROR
                );
            }
        } else {
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartpostfinance.controller.order.payment.action.cancel.access_denied',
                    'cart_post_finance'
                ),
                '',
                AbstractMessage::ERROR
            );
        }
    }

    protected function loadCartByHash(string $hash, string $type = 'SHash'): void
    {
        $querySettings = GeneralUtility::makeInstance(
            Typo3QuerySettings::class
        );
        $querySettings->setStoragePageIds([$this->cartConf['settings']['order']['pid']]);
        $this->cartRepository->setDefaultQuerySettings($querySettings);

        $findOneByMethod = 'findOneBy' . $type;
        $this->cart = $this->cartRepository->$findOneByMethod($hash);
    }

    protected function restoreCartSession(): void
    {
        $cart = $this->cart->getCart();
        $cart->resetOrderNumber();
        $cart->resetInvoiceNumber();
        $this->sessionHandler->write($cart, $this->cartConf['settings']['cart']['pid']);
    }
}

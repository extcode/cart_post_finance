<?php
declare(strict_types=1);
namespace Extcode\CartPostFinance\EventListener\Order\Payment;

/*
 * This file is part of the package extcode/cart-post-finance.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Extcode\Cart\Domain\Model\Cart;
use Extcode\Cart\Domain\Model\Cart\Cart as CartCart;
use Extcode\Cart\Domain\Model\Order\Item as OrderItem;
use Extcode\Cart\Domain\Model\Order\Transaction;
use Extcode\Cart\Domain\Repository\CartRepository;
use Extcode\Cart\Domain\Repository\Order\PaymentRepository;
use Extcode\Cart\Event\Order\PaymentEvent;
use Extcode\Cart\Service\SessionHandler;
use PostFinanceCheckout\Sdk\ApiClient;
use PostFinanceCheckout\Sdk\Model\LineItemCreate;
use PostFinanceCheckout\Sdk\Model\LineItemType;
use PostFinanceCheckout\Sdk\Model\TransactionCreate;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\LogManagerInterface;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

class ProviderRedirect
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @var ConfigurationManager
     */
    protected $configurationManager;

    /**
     * @var TypoScriptService
     */
    protected $typoScriptService;

    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

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
     * @var CartCart
     */
    protected $cart;

    /**
     * @var string
     */
    protected $cartSHash = '';

    /**
     * @var string
     */
    protected $cartFHash = '';

    /**
     * @var array
     */
    protected $cartPostFinanceConf = [];

    /**
     * @var array
     */
    protected $cartConf = [];

    /**
     * @var OrderItem
     */
    protected $orderItem;

    public function __construct(
        LogManagerInterface $logManager,
        ConfigurationManager $configurationManager,
        PersistenceManager $persistenceManager,
        TypoScriptService $typoScriptService,
        UriBuilder $uriBuilder,
        SessionHandler $sessionHandler,
        CartRepository $cartRepository,
        PaymentRepository $paymentRepository
    ) {
        $this->logger = $logManager->getLogger();
        $this->configurationManager = $configurationManager;
        $this->persistenceManager = $persistenceManager;
        $this->typoScriptService = $typoScriptService;
        $this->uriBuilder = $uriBuilder;
        $this->sessionHandler = $sessionHandler;
        $this->cartRepository = $cartRepository;
        $this->paymentRepository = $paymentRepository;

        $this->cartConf = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
            'Cart'
        );

        $this->cartPostFinanceConf = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
            'CartPostFinance'
        );
    }

    public function __invoke(PaymentEvent $event): void
    {
        $this->orderItem = $event->getOrderItem();
        $this->cart = $event->getCart();

        $provider = $this->orderItem->getPayment()->getProvider();

        if ($provider !== 'POSTFINANCE') {
            return;
        }

        $cart = $this->saveCurrentCartToDatabase();

        $this->cartSHash = $cart->getSHash();
        $this->cartFHash = $cart->getFHash();

        $spaceId = $this->cartPostFinanceConf['spaceId'];
        $userId = $this->cartPostFinanceConf['userId'];
        $secret = $this->cartPostFinanceConf['secret'];

        $client = new ApiClient($userId, $secret);

        try {
            $transactionPayload = new TransactionCreate();
            $transactionPayload->setCurrency($this->orderItem->getCurrencyCode());
            $transactionPayload->setLineItems($this->getLineItems());
            $transactionPayload->setAutoConfirmationEnabled(true);
            $transactionPayload->setSuccessUrl($this->getUrl('success', $this->cartSHash));
            $transactionPayload->setFailedUrl($this->getUrl('error', $this->cartFHash));

            $transaction = $client->getTransactionService()->create($spaceId, $transactionPayload);

            if ($transaction->getId()) {
                $this->savePaymentTransaction($transaction);

                $redirectionUrl = $client->getTransactionPaymentPageService()->paymentPageUrl($spaceId, $transaction->getId());
                header('Location: ' . $redirectionUrl);
            }
        } catch (\Exception $exception) {
            $this->logger->error('Payment Error', [
                'exceptionMessage' => $exception->getMessage()
            ]);

            $this->restoreCartSession();

            // TODO: Add Flash Message
            // TODO: Redirect To Cart
            // TODO: Remove Debugger Dump
        }

        $event->setPropagationStopped(true);
    }

    protected function getLineItems(): array
    {
        $lineItems = [];

        foreach ($this->orderItem->getProducts() as $product) {
            $lineItem = new LineItemCreate();
            $lineItem->setName($product->getTitle());
            $lineItem->setUniqueId($product->getUid());
            $lineItem->setSku($product->getSku());
            $lineItem->setQuantity($product->getCount());
            $lineItem->setAmountIncludingTax($product->getGross());
            $lineItem->setType(LineItemType::PRODUCT);

            $lineItems[] = $lineItem;
        }

        return $lineItems;
    }

    protected function getUrl(string $action, string $hash): string
    {
        $pid = (int)$this->cartConf['settings']['cart']['pid'];

        $arguments = [
            'tx_cartpostfinance_cart' => [
                'controller' => 'Order\Payment',
                'order' => $this->orderItem->getUid(),
                'action' => $action,
                'hash' => $hash
            ]
        ];

        return $this->uriBuilder->reset()
            ->setTargetPageUid($pid)
            ->setTargetPageType((int)$this->cartPostFinanceConf['redirectTypeNum'])
            ->setCreateAbsoluteUri(true)
            ->setArguments($arguments)
            ->build();
    }

    protected function saveCurrentCartToDatabase(): Cart
    {
        $cart = GeneralUtility::makeInstance(Cart::class);

        $cart->setOrderItem($this->orderItem);
        $cart->setCart($this->cart);
        $cart->setPid((int)$this->cartConf['settings']['order']['pid']);

        $this->cartRepository->add($cart);
        $this->persistenceManager->persistAll();

        return $cart;
    }

    protected function restoreCartSession(): void
    {
        $this->cart->resetOrderNumber();
        $this->cart->resetInvoiceNumber();
        $this->sessionHandler->write($this->cart, $this->cartConf['settings']['cart']['pid']);
    }

    public function savePaymentTransaction(\PostFinanceCheckout\Sdk\Model\Transaction $transaction): void
    {
        $paymentTransaction = GeneralUtility::makeInstance(Transaction::class);
        $paymentTransaction->setTxnId((string)$transaction->getId());
        $paymentTransaction->setExternalStatusCode($transaction->getState());

        $payment = $this->orderItem->getPayment();
        $payment->addTransaction($paymentTransaction);

        $this->paymentRepository->update($payment);
        $this->persistenceManager->persistAll();
    }
}

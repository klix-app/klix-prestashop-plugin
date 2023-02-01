<?php

namespace SpellPayment;

use Configuration;
use Currency;
use Exception;
use Order;
use OrderHistory;
use PrestaShopCollection;
use PrestaShopException;
use Tools;

require_once(__DIR__ . '/Repositories/OrderIdToSpellUuid.php');
require_once(__DIR__ . '/SpellHelper.php');
require_once(__DIR__ . '/OrderMessageHelper.php');

use SpellPayment\SpellHelper;
use SpellPayment\OrderMessageHelper;
use SpellPayment\Repositories\OrderIdToSpellUuid;

class RefundHelper
{
    /**
     * @param Order $order
     * @param array $productList
     * @param string $payment_id
     *
     * @return bool
     * @throws \PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     *
     */
    public function processRefund(Order $order, array $productList, string $payment_id): bool
    {
        $refundData = $this->getRefundData($order, $productList);
        $order_id = $order->id;
        $order = new Order((int)$order_id);
        list($configValues, $errors) = SpellHelper::getConfigFieldsValues();
        $spell = SpellHelper::getSpell($configValues);
        try {
            $result = $spell->refundPayment($payment_id, $refundData);
            $history = new OrderHistory();
            $history->id_order = (int)$order->id;
            $history->changeIdOrderState(7, (int)($order->id));
            $history->add(true);
            $history->save();
            if (isset($result['__all__'])) {
                $spell->logInfo(sprintf(
                    "payment not refunded: %s",
                    var_export($result, true)
                ));
                $this->handleMessage($order, $result['__all__'][0]['message']);
                return false;
            }
        } catch (ApiException $exception) {
            $message = $exception->getMessage();
            $spell->logInfo("Error processing the refund for Order ID: $order->id. $message");
            $this->handleMessage($order, "Refund for Order ID: $order->id has failed.");

            return false;
        }

        $amount = $refundData['amount'];
        $currency = $refundData['currency'];
        $message = "A refund of $amount $currency has been processed for Order ID: $order->id";

        OrderMessageHelper::addMessage($order, $message);

        return true;
    }

    /**
     * @param Order $order
     * @param array $productList
     *
     * @return array
     */
    public function getRefundData(Order $order, $productList = []): array
    {
        $currency           = new Currency($order->id_currency);
        $refund             = [];
        $refund['currency'] = $currency->iso_code;
        $refund['amount']   = $this->getProductsRefundAmount($productList);
        $refund['amount']   += $this->getShippingRefundAmount($order);
        $refund['amount']   = Tools::ps_round($refund['amount'], 2, $order->round_mode);
        $refund['amount']   = ($refund['amount'] * 100);
        return $refund;
    }

    /**
     * @param array $productList
     *
     * @return float
     */
    public function getProductsRefundAmount($productList = []): float
    {
        $refundAmount = 0;
        foreach ($productList as $productListItem) {
            $refundAmount += $productListItem['amount'];
        }

        return $refundAmount;
    }

    /**
     * @param Order $order
     *
     * @return float
     */
    private function getShippingRefundAmount(Order $order): float
    {
        $cancelProduct = Tools::getValue('cancel_product');

        // If total shipping is being refunded (standard refund), then shipping_amount is equal to 0
        // and shipping value is 1.
        if (isset($cancelProduct['shipping']) && '1' === $cancelProduct['shipping']) {
            return (float)$order->total_shipping;
        }

        // If shipping amount is is being partially refunded, the "shipping" key is not set
        // and shipping_amount value reflects the total amount to be refunded.
        if (isset($cancelProduct['shipping_amount']) && '0' !== $cancelProduct['shipping_amount']) {
            return (float)$cancelProduct['shipping_amount'];
        }

        return 0;
    }

    /**
     * @return bool
     */
    public function isVoucherRefund(): bool
    {
        $cancelProduct = Tools::getValue('cancel_product');
        if (isset($cancelProduct['voucher']) && '1' === $cancelProduct['voucher']) {
            return true;
        }

        return false;
    }

    /**
     * @param Order $order
     * @param ?array $productList
     *
     */
    public function isRefundAllow(Order $order, ?array $productList)
    {
        if (!$order->module || 'spellpayment' !== $order->module) {
            return false;
        }
        $order_id = $order->id;
        if (!$relation = OrderIdToSpellUuid::getByOrderId($order_id)) {
           return false;
        }

        if (!isset($productList)) {
            $this->handleMessage(
                $order,
                "Refund for Order ID: $order->id has failed, due to a missing productList"
            );

            return false;
        }

        if ($this->isVoucherRefund()) {
            $this->handleMessage(
                $order,
                "Refund for Order ID: $order->id will not be processed, due to a voucher being generated"
            );

            return false;
        }

        if ($this->isSplitOrder($order->reference)) {
            $this->handleMessage(
                $order,
                "Refund for Order ID: $order->id has failed, due to the order coming from a split shopping cart"
            );

            return false;
        }

        return $relation['spell_payment_uuid'];;
    }

    /**
     * @param string $orderReference
     *
     * @return bool
     */
    public function isSplitOrder(string $orderReference): bool
    {
        /** @var PrestaShopCollection $orderCollection */
        $orderCollection = Order::getByReference($orderReference);

        return count($orderCollection->getResults()) > 1;
    }

    /**
     * @param Order $order
     * @param string $message
     *
     * @throws \PrestaShopDatabaseException
     * @throws \PrestaShopException
     */
    public function handleMessage(Order $order, string $message): void
    {
        OrderMessageHelper::addMessage($order, $message);
        list($configValues, $errors) = SpellHelper::getConfigFieldsValues();
        $spell = SpellHelper::getSpell($configValues);
        $spell->logInfo($message);
        Tools::displayError($message);
    }
}

<?php

require_once(__DIR__.'/../../lib/SpellPayment/SpellHelper.php');
use SpellPayment\SpellHelper;

require_once(__DIR__ . '/../../lib/SpellPayment/Repositories/OrderIdToSpellUuid.php');
use SpellPayment\Repositories\OrderIdToSpellUuid;

class SpellpaymentCheckoutcallbackModuleFrontController extends \ModuleFrontController
{
    /** @param Order $order */
    private function restoreCart($cart_id)
    {
        $old_cart = new Cart($cart_id);
        $duplication = $old_cart->duplicate();
        if (!$duplication || !\Validate::isLoadedObject($duplication['cart'])) {
            return 'Sorry. We cannot renew your order.';
        } elseif (!$duplication['success']) {
            return 'Some items are no longer available, and we are unable to renew your order.';
        } else {
            $this->context->cookie->id_cart = $duplication['cart']->id;
            $context = $this->context;
            $context->cart = $duplication['cart'];
            \CartRule::autoAddToCart($context);
            $this->context->cookie->write();
            return null;
        }
    }

    private function makeSuccessPageUrl($order)
    {
        $cart = $this->context->cart;
        $customer = new \Customer((int)($cart->id_customer));
        $redirect_params = [
            'id_cart' => (int)$order->id_cart,
            'id_module' => (int)$this->module->id,
            'id_order' => $order->id,
            'key' => $customer->secure_key,
        ];
        return $this->context->link->getPageLink(
            'order-confirmation', true, null, $redirect_params
        );
    }

    private function processPaymentResult()
    {
        if (!$order_id = $_REQUEST['order_id']) {
            return ['status' => 400, 'message' => 'Parameter `order_id` is mandatory'];
        }
        if (!$relation = OrderIdToSpellUuid::getByOrderId($order_id)) {
            return ['status' => 404, 'message' => 'No known Klix E-commerce Gateway payments found for order #'.$order_id];
        }
        $spell_payment_uuid = $relation['spell_payment_uuid'];
        $order = new Order((int)$order_id);
        list($configValues, $errors) = SpellHelper::getConfigFieldsValues();
        $spell = SpellHelper::getSpell($configValues);
        try {
            $purchases = $spell->purchases($spell_payment_uuid);
        } catch (\Throwable $exc) {
            $order->setCurrentState(\Configuration::get('PS_OS_ERROR'));
            return ['status' => 502, 'message' => 'Failed to retrieve purchases from Klix E-commerce Gateway - '.$exc->getMessage()];
        }
        $status = $purchases['status'] ?? null;
        $message = array_slice($purchases['transaction_data']['attempts'] ?? [], -1)[0]['error']['message'] ?? '';

        if ($status !== 'paid') {
            $is_cancel = $_REQUEST['is_cancel'] ?? false;
            if ($is_cancel) {
                $order->setCurrentState(\Configuration::get('PS_OS_CANCELED'));
            }
            return [
                'status' => 302,
                'redirect_url' => $this->context->link->getPageLink(
                    'order', true, null, ['id_order' => $order->id]
                )
            ];
        } else {
            if ($order->getCurrentState() != \Configuration::get('PS_OS_PAYMENT')) {
                // sends email email, so we want to ensure it's called just once on either redirect or API callback
                $order->setCurrentState(\Configuration::get('PS_OS_PAYMENT'));
            }
            $redirect_url = $this->makeSuccessPageUrl($order);
            return ['status' => 302, 'redirect_url' => $redirect_url];
        }
    }

    public function initContent()
    {
        \Db::getInstance()->execute(
            "SELECT GET_LOCK('spell_payment', 15);"
        );

        $processed = $this->processPaymentResult();
        $status = $processed['status'];
        $message = $processed['message'] ?? null;
        $restore_cart_id = $_REQUEST['restore_cart_id'] ?? null;
        if ($status === 302 && !$restore_cart_id) {
            $redirect_url = $processed['redirect_url'];
            $is_api = $_REQUEST['is_module_callback'] ?? false;
            if ($is_api) {
                http_response_code(200);
                // status 200 and empty body so that service did not retry the request
            } else {
                \Tools::redirect($redirect_url, '');
            }
        } else {
            if ($restore_cart_id) {
                $restore_error = $this->restoreCart($restore_cart_id);
                if ($restore_error) {
                    \Tools::displayError($message.'. '.$restore_error);
                } else {
                    \Tools::redirect($this->context->link->getPageLink(
                        'cart', null, null, ['action' => 'show', 'error' => $message]
                    ));
                }
            } else {
                http_response_code($status);
                print($message);
            }
        }

        \Db::getInstance()->execute(
            "SELECT RELEASE_LOCK('spell_payment');"
        );

        die();
    }
}
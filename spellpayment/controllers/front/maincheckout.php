<?php

/**
 * Main checkout listed in the class.
 *
 * @package Spellpayment
 */

require_once(__DIR__ . '/../../lib/SpellPayment/SpellHelper.php');

use SpellPayment\SpellHelper;

require_once(__DIR__ . '/../../lib/SpellPayment/Repositories/OrderIdToSpellUuid.php');

use SpellPayment\Repositories\OrderIdToSpellUuid;

class SpellpaymentMaincheckoutModuleFrontController extends \ModuleFrontController
{
    const SPELL_MODULE_VERSION = 'v1.2.4';

    /**
     * Function for get amount of cart.
     */
    private function getAmount()
    {
        return $this->context->cart->getOrderTotal(true, \Cart::BOTH);
    }

    /**
     * Function for making payment params
     */
    private function makePaymentParams($configValues, $order)
    {
        $cart = $this->context->cart;
        $customer = new \Customer((int)($cart->id_customer));
        $billingAddress = new \Address(intval($cart->id_address_invoice));
        $shippingAddress = new \Address(intval($cart->id_address_delivery));
        $country = \Country::getIsoById((int)$billingAddress->id_country);
        $notes = $this->getNotes($cart);

        $currency = new \Currency((int)($cart->id_currency));
        $currency_code = trim($currency->iso_code);

        // ignoring Yen, Rubles, Dinars, etc - can't find API to get decimal
        // places in Prestashop, and it was done same way in other modules anyway
        $amountInCents = intval(round($this->getAmount() * 100));

        $redirect_url = $this->context->link->getModuleLink(
            $this->module->name,
            'checkoutcallback',
            ['order_id' => $order->id]
        );
        $failure_url = $this->context->link->getModuleLink(
            $this->module->name,
            'checkoutcallback',
            ['order_id' => $order->id, 'restore_cart_id' => $cart->id]
        );
        $cancel_url = $this->context->link->getModuleLink(
            $this->module->name,
            'checkoutcallback',
            ['order_id' => $order->id, 'restore_cart_id' => $cart->id, 'is_cancel' => true]
        );
        $callback_url = $this->context->link->getModuleLink(
            $this->module->name,
            'checkoutcallback',
            ['order_id' => $order->id, 'is_module_callback' => true]
        );

        $lang_code = SpellHelper::parseLanguage(\Context::getContext()->language->iso_code);

        $params = [
            'success_redirect' => $redirect_url,
            'success_callback' => $callback_url,
            'failure_redirect' => $failure_url,
            'cancel_redirect' => $cancel_url,
            'creator_agent' => 'PrestashopModule 1.7 ver.' . self::SPELL_MODULE_VERSION,
            'platform' => 'prestashop',
            'reference' => $order->reference,
            'purchase' => [
                "currency" => $currency_code,
                "language" => $lang_code,
                "notes" => $notes,
                "products" => [
                    [
                        'name' => 'Payment',
                        'price' => $amountInCents,
                        'quantity' => 1,
                    ],
                ],
                'shipping_options' => $this->getShippingPackages()
            ],
            'brand_id' => $configValues['SPELLPAYMENT_SHOP_ID'],
            'client' => [
                'email' => $customer->email,
                'phone' => ($billingAddress->phone) ?: $billingAddress->phone_mobile,
                'full_name' => $billingAddress->firstname . ' ' . $billingAddress->lastname,
                'street_address' => $billingAddress->address1 . ' ' . $billingAddress->address2,
                'country' => $country,
                'city' => $billingAddress->city,
                'zip_code' => $billingAddress->postcode,
                'shipping_street_address' => $shippingAddress->address1 . ' ' . $shippingAddress->address2,
                'shipping_country' => $country,
                'shipping_city' => $shippingAddress->city,
                'shipping_zip_code' => $shippingAddress->postcode,
            ],
        ];

        return $params;
    }


    /**
     * Function for getting Shipping packages.
     */
    private function getShippingPackages()
    {
        $result = array();
        try {
            $shipping_packages = $this->context->cart->getDeliveryOption(null, false, false);
            $shipping_packages_list = $this->context->cart->getDeliveryOptionList(null, true);

            foreach ($shipping_packages as $package_id => $package) {
                /**
                 * @var $shipping_rate WC_Shipping_Rate
                 */
                foreach ($shipping_packages_list as $shipping_rate_id => $shipping_rate) {
                    if (isset($shipping_rate[$package])) {
                        $shipping_rate_package = $shipping_rate[$package]['carrier_list'];
                        foreach ($shipping_rate_package as $carrier_id => $carrier) {
                            $result[] = array(
                                'id' => $carrier['instance']->id,
                                'label' => $carrier['instance']->getCarrierNameFromShopName(),
                                'price' => round($carrier['price_with_tax'] * 100),
                            );
                        }
                    }
                }
            }
        } catch (Exception $e) {
            \PrestaShopLogger::addLog(
                'Get Packages: ' . $e->getMessage(),
                3,
                null,
                'Packages'
            );
        }

        return $result;
    }

    /**
     * Function for get the notes from the cart.
     */
    private function getNotes($cart)
    {
        $products = $cart->getProducts(true);
        $nameString = '';
        if (count($products) > 0) {
            foreach ($products as $key => $product) {
                $name=$product['name'].' x '.$product['quantity'];
                if ($key == 0) {
                    $nameString = $name;
                } else {
                    $nameString = $nameString . '; ' . $name;
                }
            }
        }
        return $nameString;
    }

    /**
     * Init. function of the checkout controller.
     */
    public function initContent()
    {
        parent::initContent();
        list($configValues, $errors) = SpellHelper::getConfigFieldsValues();
        $spell = SpellHelper::getSpell($configValues);
        $currency = new \Currency((int)($this->context->cart->id_currency));

        $this->context->cart->secure_key = md5(uniqid(rand(), true));
        $this->context->cart->update();

        $this->module->validateOrder(
            $this->context->cart->id,
            \Configuration::get('SPELLPAYMENT_STATE_WAITING'),
            $this->getAmount(),
            $this->trans('Klix.app payments',[],'Modules.Spellpayment.Admin'),
            null,
            array(),
            (int)$currency->id,
            false,
            $this->context->cart->secure_key
        );
        $order = new \Order($this->module->currentOrder);
        $paymentParams = $this->makePaymentParams($configValues, $order);

        $paymentRs = $spell->createPayment($paymentParams);

        $checkout_url = $paymentRs['checkout_url'] ?? null;
        $spell_payment_uuid = $paymentRs['id'] ?? null;
        if (!$spell_payment_uuid) {
            $msg = 'Could not init payment in service - ' . json_encode($paymentRs);
            throw new \Exception($msg);
        }

        OrderIdToSpellUuid::addNew([
            'order_id' => $order->id,
            'spell_payment_uuid' => $spell_payment_uuid,
        ]);

        $spell_payment_method = \Tools::getValue('spell_payment_method', '');
        if ($spell_payment_method) {
            $checkout_url .= '?preferred=' . $spell_payment_method;
        }

        \Tools::redirect($checkout_url, '');
    }
}

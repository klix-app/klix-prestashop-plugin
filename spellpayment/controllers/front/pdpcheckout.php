<?php

/**
 * Callback method listed in the class.
 *
 * @package Spellpayment
 */
require_once(__DIR__ . '/../../lib/SpellPayment/SpellHelper.php');

use SpellPayment\SpellHelper;

require_once(__DIR__ . '/../../lib/SpellPayment/Repositories/OrderIdToSpellUuid.php');

use SpellPayment\Repositories\OrderIdToSpellUuid;

class spellpaymentpdpcheckoutModuleFrontController extends \ModuleFrontController
{
    const SPELL_MODULE_VERSION = 'v1.2.0';
    public function initContent()
    {
        parent::initContent();
        $cart = null;
        if (isset($_REQUEST['product_id'])) {
            $cart = $this->createCartFromProduct();
        }
        list($configValues, $errors) = SpellHelper::getConfigFieldsValues();
        $spell = SpellHelper::getSpell($configValues);

        if (!$cart) {
            $cart = $this->context->cart;
        }

        $this->context->cart->secure_key = md5(uniqid(rand(), true));
        $this->context->cart->update();

        $paymentParams = $this->makePaymentParams($configValues);

        $paymentRs = $spell->createPayment($paymentParams);

        $checkout_url = $paymentRs['checkout_url'] ?? null;
        $spell_payment_uuid = $paymentRs['id'] ?? null;
        if (!$spell_payment_uuid) {
            $msg = 'Could not init payment in service - ' . json_encode($paymentRs);
            throw new \Exception($msg);
        }
        OrderIdToSpellUuid::addNew([
            'order_id' => $cart->id,
            'spell_payment_uuid' => $spell_payment_uuid,
        ]);
        \Tools::redirect($checkout_url, '');
    }


    private function createCartFromProduct()
    {
        $cart = null;
        $id_product = @$_REQUEST['product_id'];

        //id_product_attribute
        $id_product_attribute = @$_REQUEST['id_product_attribute'];

        //Qty
        $qty = @$_REQUEST['qty'];

        // Language id
        $lang_id = (int) \Configuration::get('PS_LANG_DEFAULT');

        // Load product object
        $product = new \Product($id_product, false, $lang_id);

        // Validate product object
        if (\Validate::isLoadedObject($product)) {
            if (!$this->context->cart->id) {
                if (\Context::getContext()->cookie->id_guest) {
                    $guest = new \Guest(\Context::getContext()->cookie->id_guest);
                    $this->context->cart->mobile_theme = $guest->mobile_theme;
                }
            }
            // $cookie = \Context::getContext()->cookie;
            // if ((int)$cookie->id_cart) {
            //     $cart = new \Cart((int)$cookie->id_cart);
            // }
            if (!isset($cart) || !$cart->id) {
                if (\Context::getContext()->cookie->id_guest) {
                    $guest = new \Guest(\Context::getContext()->cookie->id_guest);
                    $this->context->cart->mobile_theme = $guest->mobile_theme;
                }
                $this->context->cart->add();
                if ($this->context->cart->id) {
                    $this->context->cookie->id_cart = (int)$this->context->cart->id;
                }
                $cart = $this->context->cart;
            }
            $updateQuantity = $cart->updateQty((int)($qty), (int)($id_product), (int)($id_product_attribute), false, 'up');
            $cart->id_customer = (int)($this->context->cookie->id_customer);
            $cart->update();
        }
        return $cart;
    }


    private function getAmount()
    {
        return $this->context->cart->getOrderTotal(true, \Cart::BOTH_WITHOUT_SHIPPING);
    }

    private function makePaymentParams($configValues)
    {
        $cart = $this->context->cart;
        $customer = new \Customer((int)($cart->id_customer));
        $notes = $this->getNotes($cart);

        $currency = new \Currency((int)($cart->id_currency));
        $currency_code = trim($currency->iso_code);

        // ignoring Yen, Rubles, Dinars, etc - can't find API to get decimal
        // places in Prestashop, and it was done same way in other modules anyway
        $amountInCents = intval(round($this->getAmount() * 100));
        $redirect_url = $this->context->link->getModuleLink(
            $this->module->name,
            'checkoutcallback',
            ['cart_id' => $cart->id]
        );
        $failure_url = $this->context->link->getModuleLink(
            $this->module->name,
            'checkoutcallback',
            ['cart_id' => $cart->id, 'restore_cart_id' => $cart->id]
        );
        $cancel_url = $this->context->link->getModuleLink(
            $this->module->name,
            'checkoutcallback',
            ['cart_id' => $cart->id, 'restore_cart_id' => $cart->id, 'is_cancel' => true]
        );
        $callback_url = $this->context->link->getModuleLink(
            $this->module->name,
            'checkoutcallback',
            ['cart_id' => $cart->id, 'is_module_callback' => true]
        );

        $lang_code = SpellHelper::parseLanguage(\Context::getContext()->language->iso_code);

        $params = [
            'success_redirect' => $redirect_url,
            'success_callback' => $callback_url,
            'failure_redirect' => $failure_url,
            'cancel_redirect' => $cancel_url,
            'creator_agent' => 'PrestashopModule ' . self::SPELL_MODULE_VERSION,
            'platform' => 'prestashop',
            // 'reference' => $cart->reference,
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
                'email' => $customer->id ? $customer->email : 'dummy@data.com',
            ],
            'payment_method_whitelist' => ['klix']
        ];

        return $params;
    }

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
}

<?php
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
	print('_PS_VERSION_ constant missing, quiting the Klix E-commerce Gateway module');
    exit;
}

require_once(__DIR__.'/lib/SpellPayment/SpellHelper.php');
use SpellPayment\SpellHelper;

require_once(__DIR__ . '/lib/SpellPayment/Repositories/OrderIdToSpellUuid.php');
use SpellPayment\Repositories\OrderIdToSpellUuid;

class SpellPayment extends PaymentModule
{
    public $name;
    public $tab;
    public $version;
    public $ps_versions_compliancy;
    public $author;
    public $controllers;
    public $need_instance;
    public $currencies;
    public $currencies_mode;
    public $bootstrap;
    public $display;

    public $displayName = 'Klix E-commerce Gateway';
    public $description = 'Klix E-commerce Gateway';
    public $confirmUninstall = 'Are you sure you want to delete your details?';

    public function __construct()
    {
        $this->name = 'spellpayment';
        $this->tab = 'payments_gateways';
        $this->version = '1.1.2';
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->author = 'Klix';
        $this->controllers = ['validation'];
        $this->need_instance = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        $this->display = true;

        parent::__construct();

        $this->supportCountryTranslationsDiscovery();
    }

    public function isUsingNewTranslationSystem() {
        return true;
    }

    private function supportCountryTranslationsDiscovery() {
        $this->trans('Other', [], 'Modules.Spellpayment.Front');
        $this->trans('Latvia', [], 'Modules.Spellpayment.Front');
        $this->trans('Lithuania', [], 'Modules.Spellpayment.Front');
        $this->trans('Estonia', [], 'Modules.Spellpayment.Front');
    }

    private function getDetectedCountry() {
        if (!$cart = $this->context->cart) {
            return null;
        }
        if (!$id_address_delivery = $cart->id_address_delivery) {
            return null;
        }
        $address_delivery = new Address($id_address_delivery);
        if (!$id_country = $address_delivery->id_country) {
            return null;
        }
        $address_delivery_country = new Country($id_country);
        return $address_delivery_country->iso_code ?: null;
    }

    /** @throws \Exception - if payment method was not configured properly- */
    public function collectCheckoutTplData($params)
    {
        list($configValues, $errors) = SpellHelper::getConfigFieldsValues();

        $currency = Context::getContext()->currency->iso_code;
        $spell = SpellHelper::getSpell($configValues);
        $payment_methods = $spell->payment_methods(
            $currency,
            SpellHelper::parseLanguage(Context::getContext()->language->iso_code)
        );
        $msgItem = $payment_methods['__all__'][0] ?? null;
        if ('authentication_failed' === ($msgItem['code'] ?? null)) {
            $msg = 'Klix authentication_failed - '.
                ($msgItem['message'] ?? '(no message)');
            throw new \Exception($msg);
        }

        $payment_method_selection_enabled = $configValues['SPELLPAYMENT_METHOD_SELECTION_ENABLED'];
        $country_options = SpellHelper::getCountryOptions($payment_methods);
        $this->translateCountryNames($payment_methods);
        $payment_method_title = $this->trans('Select payment method', [], 'Modules.Spellpayment.Front');
        $payment_method_description = $this->trans('Choose payment method on next page', [], 'Modules.Spellpayment.Front');
        return [
            'title' => $payment_method_selection_enabled ? $payment_method_title : $payment_method_description,
            'payment_method_selection_enabled' => $payment_method_selection_enabled,
            'payment_methods_api_data' => $payment_methods,
            'country_options' => $country_options,
            'by_method' => SpellHelper::collectByMethod($payment_methods['by_country']),
            '$params' => $params,
            'selected_country' => SpellHelper::getPreselectedCountry($this->getDetectedCountry(), $country_options),
        ];
    }

    private function translateCountryNames(&$payment_methods) {
        foreach($payment_methods['country_names'] as $countryCode => $name) {
            $payment_methods['country_names'][$countryCode] = $this->trans($name, [], 'Modules.Spellpayment.Front');
        }
    }

    /**
     * order state is some sort of unique identifier for our
     * module required to register payments during checkout
     */
    private function ensureOrderState()
    {
        $stateId = \Configuration::get('SPELLPAYMENT_STATE_WAITING');
        if ($stateId === false) {
            $order_state = new OrderState();
        } else {
            $order_state = new OrderState($stateId);
        }
        $order_state->name = [];
        foreach (\Language::getLanguages() as $language) {
            $order_state->name[$language['id_lang']] = 'Awaiting payment';
        }
        $order_state->module_name = $this->name;
        $order_state->color       = "RoyalBlue";
        $order_state->unremovable = true;
        $order_state->hidden      = false;
        $order_state->logable     = false;
        $order_state->delivery    = false;
        $order_state->shipped     = false;
        $order_state->paid        = false;
        $order_state->deleted     = false;
        $order_state->invoice     = false;
        $order_state->send_email  = false;
        $order_state->save();

        \Configuration::updateValue("SPELLPAYMENT_STATE_WAITING", $order_state->id);
    }

    /** called by Prestashop when you install the module */
    public function install()
    {
        if (\Shop::isFeatureActive()) {
            \Shop::setContext(\Shop::CONTEXT_ALL);
        }

        $this->ensureOrderState();
        OrderIdToSpellUuid::recreate();

        Configuration::updateValue('SPELLPAYMENT_METHOD_SELECTION_ENABLED', true);

        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('Header')
            && $this->registerHook('displayProductAdditionalInfo');
    }

    public function uninstall()
    {
        OrderIdToSpellUuid::drop();
        return $this->unregisterHook('paymentOptions')
            && $this->unregisterHook('paymentReturn')
            && $this->unregisterHook('Header')
            && $this->unregisterHook('displayProductAdditionalInfo')
            && parent::uninstall();
    }

    /** Load the configuration form */
    public function getContent()
    {
        $status = null;

        // If values have been submitted in the form, process.
        if (((bool)Tools::isSubmit('submitSpellpaymentModule')) == true) {
            list($configValues, $errors) = SpellHelper::getConfigFieldsValues();
            if (!$errors) {
                foreach ($configValues as $name => $value) {
                    Configuration::updateValue($name, trim($value));
                }
                $status = 'SAVED_SUCCESSFULLY';
            } else {
                $status = 'CHANGES_NOT_SAVED';
            }
        }

        return $this->renderForm($status);
    }

    /** called by Prestashop on admin configuration page */
    public function getConfigForm()
    {
        return SpellHelper::getConfigForm();
    }

    public function renderForm($status = null)
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = 'en';
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitSpellpaymentModule';

        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        list($configValues, $errors) = SpellHelper::getConfigFieldsValues();
        $helper->tpl_vars = [
            'fields_value' => $configValues,
            'languages' => [
                [
                    'id_lang' => 'en',
                    'iso_code' => 'en',
                    'name' => 'English',
                    'is_default' => true,
                ],
            ],
            'id_language' => 'en',
        ];
        $errorsHeaderHtml = '';
        $statusHtml = '';
        if ($status === 'SAVED_SUCCESSFULLY') {
            $statusHtml = '<div style="color: #00e400">Changes Saved Successfully</div>';
        } else if ($status === 'CHANGES_NOT_SAVED') {
            $statusHtml = '<div style="color: red">Changes Not Saved</div>';
            $errorsHeaderHtml = '<div style="color: red">'.implode('<br/>', array_map('htmlspecialchars', $errors)).'</div>';
        }

        return $statusHtml.$errorsHeaderHtml.$helper->generateForm([SpellHelper::getConfigForm()]);
    }

    /** @return PaymentOption[] */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return [];
        }

        if (false == Configuration::get('SPELLPAYMENT_ACTIVE_MODE', false)) {
            return [];
        }

        try {
            $tpl_data = $this->collectCheckoutTplData($params);
        } catch (\Throwable $exc) {
            $error = 'Misconfigured payment method - '.$exc->getMessage();
            $tpl_data = ['error' => $error];
        }
        $action = $this->context->link->getModuleLink(
            $this->name, 'spellpayment', [], true
        );
        $tpl_data['action_url'] = $action;

        try {
            $loader = new \Twig\Loader\FilesystemLoader(__DIR__.'/views/templates');
            $twig = new \Twig\Environment($loader);
            $formHtml = $twig->render('front/method_parameters_form.twig', $tpl_data);
        } catch (\Throwable $exc) {
            $formHtml = '<form style="color: red">Failed to render form - '.htmlentities($exc->getMessage()).'</form>';
        }

        $paymentOption = (new PaymentOption())
            ->setModuleName($this->name)
            ->setCallToActionText($tpl_data['title'])
            ->setForm($formHtml)
            ->setAction($action);

        return [$paymentOption];
    }

    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addJS($this->_path . 'views/js/back.js');
        }
    }

    public function hookDisplayPaymentReturn($params)
    {
        if ($this->active == false) {
            return false;
        }

        return 'Thanks for using Klix E-commerce Gateway';
    }

    public function hookDisplayProductAdditionalInfo($params)
    {
        if(!Configuration::get('SPELLPAYMENT_ACTIVE_MODE'))
            return '';
        $product = $this->context->controller->getProduct();        
        $product_price=bcmul((string) $product->price,'100');
        $brand_id=Configuration::get('SPELLPAYMENT_SHOP_ID');
        $language=SpellHelper::parseLanguage(Context::getContext()->language->iso_code);

        $widget_html = sprintf('<klix-pay-later amount="%s" brand_id="%s" 
                language="%s" theme="light" view="product">
                </klix-pay-later>',$product_price,$brand_id,$language);
        return $widget_html;
    }

    public function hookHeader($params)
    {
        if(!Configuration::get('SPELLPAYMENT_ACTIVE_MODE'))
            return '';
        return '<script type="module" src="https://klix.blob.core.windows.net/public/pay-later-widget/build/klix-pay-later-widget.esm.js"></script>
        <script nomodule="" src="https://klix.blob.core.windows.net/public/pay-later-widget/build/klix-pay-later-widget.js"></script>';
    }
}

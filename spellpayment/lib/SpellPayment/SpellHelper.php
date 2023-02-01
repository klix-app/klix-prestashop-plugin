<?php

namespace SpellPayment;

require_once(__DIR__ . '/DefaultLogger.php');

use SpellPayment\DefaultLogger;

require_once(__DIR__ . '/SpellAPI.php');

use SpellPayment\SpellAPI;

/**
 * helper functions to adapt payload returned by the Klix API
 */
class SpellHelper
{
    public static function getConfigForm()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => 'Settings',
                    'icon' => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => 'Enable API',
                        'name' => 'SPELLPAYMENT_ACTIVE_MODE',
                        'is_bool' => true,
                        'values' => [
                            ['value' => true],
                            ['value' => false],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => 'Enable payment method selection',
                        'name' => 'SPELLPAYMENT_METHOD_SELECTION_ENABLED',
                        'values' => [
                            ['value' => true],
                            ['value' => false],
                        ],
                        'desc' => 'If enabled, buyers will be able to choose the desired payment method directly in PrestaShop.',
                    ],
                    [
                        'type' => 'switch',
                        'label' => 'Enable one click payment',
                        'name' => 'SPELLPAYMENT_ONE_CLICK_PAYMENT_ENABLED',
                        'values' => [
                            ['value' => true],
                            ['value' => false],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => 'Brand ID',
                        'name' => 'SPELLPAYMENT_SHOP_ID',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => 'Secret key',
                        'name' => 'SPELLPAYMENT_SHOP_PASS',
                        'required' => true,
                    ],
                    [
                        'type' => 'switch',
                        'label' => 'Enable logging',
                        'name' => 'SPELLPAYMENT_ENABLE_LOGGING',
                        'values' => [
                            ['value' => true],
                            ['value' => false],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => 'Save',
                ],
            ]
        ];
    }

    public static function getConfigFieldsValues()
    {
        $errors = [];
        $configValues = [];
        foreach (self::getConfigForm()['form']['input'] as $field) {
            $name = $field['name'];
            $value = \Tools::getValue($name, \Configuration::get($name, null));
            if (($field['required'] ?? false) && !$value) {
                $errors[] = 'Field ' . $name . ' is mandatory!';
            }
            $configValues[$name] = $value;
        }
        return [$configValues, $errors];
    }

    public static function getSpell($configValues)
    {
        $brand_id = $configValues['SPELLPAYMENT_SHOP_ID'];
        $secret_code = $configValues['SPELLPAYMENT_SHOP_PASS'];
        $debug = $configValues['SPELLPAYMENT_ENABLE_LOGGING'] ? true : false;

        $logger = new DefaultLogger(new class()
        {
            public function info($msg)
            {
                \PrestaShopLogger::addLog($msg);
            }
        });
        if (!$secret_code || !$brand_id) {
            throw new \Exception('Shop authentication token/brand id of Klix.app payments are not set');
        }

        return new SpellAPI($secret_code, $brand_id, $logger, $debug);
    }

    public static function getCountryOptions($payment_methods)
    {
        $country_options = array_values(array_unique(
            array_keys($payment_methods['by_country'])
        ));
        $any_index = array_search('any', $country_options);
        if ($any_index !== false) {
            array_splice($country_options, $any_index, 1);
            $country_options = array_merge($country_options, ['any']);
        }
        return $country_options;
    }

    public static function getPreselectedCountry($detected_country, $country_options)
    {
        $selected_country = '';
        $any_index = array_search('any', $country_options);
        if (in_array($detected_country, $country_options)) {
            $selected_country = $detected_country;
        } else if ($any_index !== false) {
            $selected_country = 'any';
        } else if (count($country_options) > 0) {
            $selected_country = $country_options[0];
        }
        return $selected_country;
    }

    public static function collectByMethod($by_country)
    {
        $by_method = [];
        foreach ($by_country as $country => $pms) {
            foreach ($pms as $pm) {
                if (!array_key_exists($pm, $by_method)) {
                    $by_method[$pm] = [
                        "payment_method" => $pm,
                        "countries" => [],
                    ];
                }
                if (!in_array($country, $by_method[$pm]["countries"])) {
                    $by_method[$pm]["countries"][] = $country;
                }
            }
        }
        return $by_method;
    }

    public static function parseLanguage($lang_code)
    {
        if (!in_array($lang_code, ['en', 'et', 'lt', 'lv', 'ru'])) {
            $lang_code = 'en';
        }
        return $lang_code;
    }
}

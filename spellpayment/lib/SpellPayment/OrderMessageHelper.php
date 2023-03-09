<?php

namespace SpellPayment;

use Validate;
use Tools;
use CustomerThread;
use CustomerMessage;
use Customer;
use Order;

class OrderMessageHelper
{
    /**
     * Add message as order note
     *
     * @param Order $order
     * @param string $message
     * @throws \PrestaShopDatabaseException
     * @throws \PrestaShopException
     */
    public static function addMessage(Order $order, string $message): void
    {
        $message = strip_tags($message);

        if (!Validate::isCleanHtml($message)) {
            return;
        }

        /** @var Customer $customer */
        $customer = $order->getCustomer();

        $customerThread = new CustomerThread();
        $customerThread->id_contact = 0;
        $customerThread->id_customer = (int)$order->id_customer;
        $customerThread->id_shop = (int)$order->id_shop;
        $customerThread->id_order = (int)$order->id;
        $customerThread->id_lang = (int)$order->id_lang;
        $customerThread->email = $customer->email;
        $customerThread->status = 'open';
        $customerThread->token = Tools::passwdGen(12);
        $customerThread->add();

        $customerMessage = new CustomerMessage();
        $customerMessage->id_customer_thread = $customerThread->id;
        $customerMessage->id_employee = 0;
        $customerMessage->message = $message;
        $customerMessage->private = 1;
        $customerMessage->add();
    }
}
<?php

namespace SpellPayment\Repositories;

class OrderIdToSpellUuid
{
    const TABLE = _DB_PREFIX_ . 'spellpayment_ps_order_id_to_spell_id';

    private static function normRow($row)
    {
        return [
            'order_id' => $row['order_id'],
            'spell_payment_uuid' => $row['spell_payment_uuid'],
        ];
    }

    public static function recreate()
    {
        \Db::getInstance()->execute('DROP TABLE IF EXISTS ' . OrderIdToSpellUuid::TABLE);
        \Db::getInstance()->execute('CREATE TABLE ' . OrderIdToSpellUuid::TABLE . ' (
			order_id INT NOT NULL,
			spell_payment_uuid CHAR(36) NOT NULL,
			UNIQUE KEY order_id (order_id)
		)');
    }

    public static function drop()
    {
        \Db::getInstance()->execute('DROP TABLE IF EXISTS ' . OrderIdToSpellUuid::TABLE);
    }

    public static function addNew($row)
    {
        \Db::getInstance()->insert(self::TABLE, self::normRow($row), false, false, \Db::REPLACE, false);
    }

    public static function update($order_id, $cart_id)
    {
        \Db::getInstance()->update('spellpayment_ps_order_id_to_spell_id', array('order_id' => $order_id), 'order_id = ' . (int)$cart_id);
    }

    /** @return array = self::normRow() */
    public static function getByOrderId($order_id)
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE order_id = ' . ((int)$order_id);
        return \Db::getInstance()->executeS($sql)[0] ?? null;
    }
}

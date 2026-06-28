<?php
namespace FacturaScripts\Plugins\YeveaStore\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class YeveaStoreOrder extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var string */
    public $code;

    /** @var string */
    public $customer_name;

    /** @var string */
    public $customer_email;

    /** @var string */
    public $address;

    /** @var string */
    public $customer_phone;

    /** @var string */
    public $customer_nif;

    /** @var string */
    public $customer_city;

    /** @var string */
    public $customer_zip;

    /** @var string */
    public $customer_province;

    /** @var string */
    public $customer_country;

    /** @var string */
    public $status;

    /** @var float */
    public $total;

    /** @var string */
    public $notes;

    /** @var string */
    public $codcliente;

    /** @var string */
    public $codpedido;

    /** @var string */
    public $creation_date;

    /** @var string */
    public $modification_date;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'code';
    }

    public static function tableName(): string
    {
        return 'yeveastore_orders';
    }

    public function clear(): void
    {
        parent::clear();
        $this->customer_country = 'ES';
        $this->status = 'pending';
        $this->total = 0;
    }

    public function test(): bool
    {
        if (empty($this->code)) {
            $this->code = 'ORD-' . strtoupper(bin2hex(random_bytes(4)));
        }
        return parent::test();
    }

    public function saveInsert(array $values = []): bool
    {
        $this->creation_date = Tools::dateTime();
        $this->modification_date = Tools::dateTime();
        return parent::saveInsert($values);
    }

    public function saveUpdate(array $values = []): bool
    {
        $this->modification_date = Tools::dateTime();
        return parent::saveUpdate($values);
    }
}

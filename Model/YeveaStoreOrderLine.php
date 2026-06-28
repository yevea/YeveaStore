<?php
namespace FacturaScripts\Plugins\YeveaStore\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

class YeveaStoreOrderLine extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $order_id;

    /** @var string */
    public $product_referencia;

    /** @var string */
    public $product_name;

    /** @var int */
    public $quantity;

    /** @var float */
    public $price;

    /** @var float */
    public $subtotal;

    /** @var float|null */
    public $largo_cm;

    /** @var float|null */
    public $ancho_cm;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'yeveastore_order_lines';
    }

    public function clear(): void
    {
        parent::clear();
        $this->quantity = 1;
        $this->price = 0;
        $this->subtotal = 0;
    }
}

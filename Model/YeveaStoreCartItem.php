<?php
namespace FacturaScripts\Plugins\YeveaStore\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class YeveaStoreCartItem extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var string */
    public $session_id;

    /** @var string */
    public $product_referencia;

    /** @var int */
    public $quantity;

    /** @var float|null */
    public $largo_cm;

    /** @var float|null */
    public $ancho_cm;

    /** @var string */
    public $creation_date;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'yeveastore_cart_items';
    }

    public function clear(): void
    {
        parent::clear();
        $this->quantity = 1;
    }

    public function saveInsert(array $values = []): bool
    {
        $this->creation_date = Tools::dateTime();
        return parent::saveInsert($values);
    }
}

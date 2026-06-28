<?php

namespace Model;

use FacturaScripts\Core\Template\ModelClass;

class YeveaStorePresupuesto extends ModelClass {
    protected $id;
    protected $codigo;
    protected $fecha_creacion;
    protected $fecha_validez;
    protected $tipo;
    protected $datos_cliente;
    protected $productos;
    protected $total;
    protected $estado;
    protected $pedido_codigo;
    protected $created_at;
    protected $updated_at;

    public function primaryColumn() {
        return 'id';
    }

    public function primaryDescriptionColumn() {
        return 'codigo';
    }

    public function tableName() {
        return 'yeveastore_presupuestos';
    }

    public function clear() {
        $this->tipo = 'particular';
        $this->estado = 'activo';
    }

    public function isExpired() {
        return (strtotime($this->fecha_validez) < time() && $this->estado === 'activo');
    }
}
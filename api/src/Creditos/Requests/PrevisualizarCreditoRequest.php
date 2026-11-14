<?php

namespace App\Creditos\Requests;

/** Misma validación que crear el crédito, salvo el control de previsualización: aquí todavía no hay una previa que validar. */
class PrevisualizarCreditoRequest extends CrearCreditoRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'saldo_refinanciado_previsualizado' => ['prohibited']];
    }
}

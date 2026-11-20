<?php

namespace App\Creditos\Requests;

/** Misma validación que editar las condiciones, salvo el motivo: se pide al guardar, no al previsualizar. */
class PrevisualizarEdicionCreditoRequest extends EditarCreditoRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'motivo' => ['nullable', 'string', 'max:500']];
    }
}

<?php

namespace App\Dashboard\Requests;

use App\Creditos\Enums\EstadoCredito;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConsultarDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El superadmin no tiene empresa de sesión: elige la empresa explícitamente y no filtra por asesor ni por
     * cliente (esas listas dependen de una empresa de sesión). El resto de usuarios trabaja en su empresa.
     */
    public function rules(): array
    {
        $usuario = $this->user();
        $superadmin = (bool) $usuario?->es_superadmin;

        return [
            'periodo' => ['nullable', 'date_format:Y-m'],
            'empresa_id' => [$superadmin ? 'required' : 'prohibited', Rule::exists('empresas', 'id')],
            'asesor_id' => [$superadmin ? 'prohibited' : 'nullable', Rule::exists('usuarios', 'id')->where('empresa_id', $usuario?->empresa_id)],
            'cliente_id' => [$superadmin ? 'prohibited' : 'nullable', Rule::exists('clientes', 'id')->where('empresa_id', $usuario?->empresa_id)],
            'estado' => ['nullable', Rule::enum(EstadoCredito::class)],
        ];
    }

    public function empresaId(): int
    {
        return $this->user()->es_superadmin ? (int) $this->input('empresa_id') : $this->user()->empresa_id;
    }

    public function asesorId(): ?int
    {
        return $this->filled('asesor_id') ? (int) $this->input('asesor_id') : null;
    }

    public function clienteId(): ?int
    {
        return $this->filled('cliente_id') ? (int) $this->input('cliente_id') : null;
    }

    public function estado(): ?EstadoCredito
    {
        return $this->filled('estado') ? EstadoCredito::from($this->input('estado')) : null;
    }

    /** Mes de referencia del dashboard; sin `periodo` es el mes en curso (zona `America/Bogota`). */
    public function periodo(): CarbonImmutable
    {
        return $this->filled('periodo')
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->input('periodo').'-01')->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();
    }
}

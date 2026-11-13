<?php

namespace App\Usuarios\Requests;

use App\Usuarios\Models\Permiso;
use App\Usuarios\Requests\Concerns\ValidaAlcanceAsesor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EditarUsuarioRequest extends FormRequest
{
    use ValidaAlcanceAsesor;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100'],
            // Unicidad global intencional: el login (`AuthService`/`UsuarioRepository::buscarPorUsuario`) busca por
            // `usuario` sin empresa_id, así que dos empresas no pueden compartir el mismo nombre de usuario.
            'usuario' => [
                'required',
                'string',
                'max:50',
                Rule::unique('usuarios', 'usuario')->ignore($this->route('usuario')),
            ],
            'rol_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('empresa_id', $this->user()->empresa_id)],
            'activo' => ['required', 'boolean'],
            'es_asesor_global' => ['sometimes', 'boolean'],
            'permisos_extra' => ['sometimes', 'array'],
            'permisos_extra.*.permiso_id' => ['required', 'integer', Rule::in($this->idsPermisosValidos())],
            'permisos_extra.*.concedido' => ['required', 'boolean'],
            // Excepciones temporales (Fase 2): motivo obligatorio; vencimiento opcional, por defecto 30 días y máximo 90.
            'permisos_extra.*.motivo' => ['required', 'string', 'max:500'],
            'permisos_extra.*.vence_en' => ['nullable', 'date', 'after:now', 'before_or_equal:'.now()->addDays(90)->toDateTimeString()],
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $this->validarAlcanceAsesor($validador);
    }

    /** Evita una query `exists` por elemento del array (una por cada permiso_id enviado). */
    private function idsPermisosValidos(): array
    {
        return Permiso::query()->pluck('id')->all();
    }
}

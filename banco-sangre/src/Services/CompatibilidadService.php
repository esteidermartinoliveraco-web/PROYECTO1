<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

final class CompatibilidadService
{
    public const GRUPOS_VALIDOS = [
        'A_POS', 'A_NEG', 'B_POS', 'B_NEG', 'AB_POS', 'AB_NEG', 'O_POS', 'O_NEG'
    ];

    /**
     * Matriz de compatibilidad para Glóbulos Rojos y Sangre Total.
     * Clave: Grupo del Receptor -> Valor: Lista de grupos de Donantes compatibles.
     */
    private const COMPATIBILIDAD_GLOBULOS_ROJOS = [
        'O_NEG'  => ['O_NEG'],
        'O_POS'  => ['O_NEG', 'O_POS'],
        'A_NEG'  => ['O_NEG', 'A_NEG'],
        'A_POS'  => ['O_NEG', 'O_POS', 'A_NEG', 'A_POS'],
        'B_NEG'  => ['O_NEG', 'B_NEG'],
        'B_POS'  => ['O_NEG', 'O_POS', 'B_NEG', 'B_POS'],
        'AB_NEG' => ['O_NEG', 'A_NEG', 'B_NEG', 'AB_NEG'],
        'AB_POS' => ['O_NEG', 'O_POS', 'A_NEG', 'A_POS', 'B_NEG', 'B_POS', 'AB_NEG', 'AB_POS'],
    ];

    /**
     * Matriz de compatibilidad para Plasma Fresco y Crioprecipitado (regla inversa ABO).
     * Clave: Grupo del Receptor -> Valor: Lista de grupos de Donantes compatibles.
     */
    private const COMPATIBILIDAD_PLASMA = [
        'O_NEG'  => ['O_NEG', 'O_POS', 'A_NEG', 'A_POS', 'B_NEG', 'B_POS', 'AB_NEG', 'AB_POS'],
        'O_POS'  => ['O_NEG', 'O_POS', 'A_NEG', 'A_POS', 'B_NEG', 'B_POS', 'AB_NEG', 'AB_POS'],
        'A_NEG'  => ['A_NEG', 'A_POS', 'AB_NEG', 'AB_POS'],
        'A_POS'  => ['A_NEG', 'A_POS', 'AB_NEG', 'AB_POS'],
        'B_NEG'  => ['B_NEG', 'B_POS', 'AB_NEG', 'AB_POS'],
        'B_POS'  => ['B_NEG', 'B_POS', 'AB_NEG', 'AB_POS'],
        'AB_NEG' => ['AB_NEG', 'AB_POS'],
        'AB_POS' => ['AB_NEG', 'AB_POS'],
    ];

    /**
     * Devuelve los grupos sanguíneos de donantes compatibles según el receptor y el hemocomponente.
     *
     * @return string[]
     */
    public function obtenerDonantesCompatibles(string $grupoReceptor, string $tipoHemocomponente): array
    {
        $grupo = strtoupper(trim($grupoReceptor));
        $componente = strtoupper(trim($tipoHemocomponente));

        if (!in_array($grupo, self::GRUPOS_VALIDOS, true)) {
            throw new InvalidArgumentException("Grupo sanguíneo de receptor no válido: {$grupo}");
        }

        return match ($componente) {
            'GLOBULOS_ROJOS', 'SANGRE_TOTAL'   => self::COMPATIBILIDAD_GLOBULOS_ROJOS[$grupo],
            'PLASMA_FRESCO', 'CRIOPRECIPITADO' => self::COMPATIBILIDAD_PLASMA[$grupo],
            'PLAQUETAS'                        => self::COMPATIBILIDAD_GLOBULOS_ROJOS[$grupo], // Prioriza isogrupo/compatible eritrocitario
            default                            => throw new InvalidArgumentException("Tipo de hemocomponente no válido: {$componente}")
        };
    }

    /**
     * Verifica si un donante específico puede transfundir a un paciente dado.
     */
    public function esCompatible(string $grupoDonante, string $grupoReceptor, string $tipoHemocomponente): bool
    {
        $compatibles = $this->obtenerDonantesCompatibles($grupoReceptor, $tipoHemocomponente);
        return in_array(strtoupper(trim($grupoDonante)), $compatibles, true);
    }

    /**
     * Calcula los días de descanso obligatorio según el sexo biológico del donante.
     * Estándar clínico: 90 días (3 meses) para hombres, 120 días (4 meses) para mujeres.
     */
    public function obtenerDiasDescanso(string $sexo): int
    {
        return match (strtoupper(trim($sexo))) {
            'M'     => 90,
            'F'     => 120,
            default => throw new InvalidArgumentException('El sexo debe ser M o F.')
        };
    }

    /**
     * Calcula la fecha exacta (YYYY-MM-DD) en que un donante volverá a estar habilitado tras donar.
     */
    public function calcularProximaFechaHabilitacion(string $sexo, ?DateTimeImmutable $fechaDonacion = null): string
    {
        $base = $fechaDonacion ?? new DateTimeImmutable('today');
        $dias = $this->obtenerDiasDescanso($sexo);
        return $base->modify("+{$dias} days")->format('Y-m-d');
    }

    /**
     * Evalúa si un donante cumple hoy con todos los requisitos para recibir alertas o donar.
     */
    public function estaHabilitadoParaDonar(string $estado, ?string $fechaHabilitacionProxima): bool
    {
        if (strtoupper(trim($estado)) !== 'ACTIVO') {
            return false;
        }

        if ($fechaHabilitacionProxima === null || $fechaHabilitacionProxima === '') {
            return true;
        }

        $hoy = new DateTimeImmutable('today');
        $habilitacion = DateTimeImmutable::createFromFormat('Y-m-d', $fechaHabilitacionProxima);

        return $habilitacion !== false && $habilitacion <= $hoy;
    }

    /**
     * Calcula la fecha de caducidad de una unidad fraccionada según su tipo de hemocomponente.
     */
    public function calcularFechaCaducidad(string $tipoHemocomponente, DateTimeImmutable $fechaExtraccion): string
    {
        $intervalo = match (strtoupper(trim($tipoHemocomponente))) {
            'PLAQUETAS'       => '+5 days',
            'SANGRE_TOTAL'    => '+35 days',
            'GLOBULOS_ROJOS'  => '+42 days',
            'PLASMA_FRESCO'   => '+365 days',
            'CRIOPRECIPITADO' => '+365 days',
            default           => throw new InvalidArgumentException("Hemocomponente no reconocido: {$tipoHemocomponente}")
        };

        return $fechaExtraccion->modify($intervalo)->format('Y-m-d H:i:s');
    }
}
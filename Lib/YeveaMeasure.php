<?php
namespace FacturaScripts\Plugins\YeveaStore\Lib;

use FacturaScripts\Core\Model\Familia;

/**
 * Per-family measurement price calculator ("Tabla de Precios").
 *
 * Each familia stores a JSON config in calc_config:
 * {
 *   "mode": "none" | "area" | "volume",
 *   "price_label": "Precio por m²", "price_unit": "m²",
 *   "show_unit_price": true, "calc_weight": false, "overage_pct": 0,
 *   "dims": {
 *     "length": {"label": "Ancho", "unit": "cm", "options": "60-99"},
 *     "width":  {"label": "Profundo", "unit": "cm", "options": "35;40-45"},
 *     "height": {"label": "Grueso", "unit": "cm", "options": ""}
 *   },
 *   "capture_rates": [{"desde": 0, "hasta": 6, "precio": 90}, …]
 * }
 *
 * - mode area:   price = product €/m² × (L×W in m²) × (1 + overage%)
 * - mode volume: price = product €/litre × (L×W×H in litres) × (1 + overage%)
 * - "options" restricts customer input to a fixed set ("60-99" range and/or
 *   "60;61" list); empty means free input bounded by the familia min/max.
 * - capture_rates: €/m² per thickness range, used by YeveaCaptura to price
 *   slabs at capture time.
 *
 * Legacy: a familia of tipo 'tableros' with no calc_config behaves as mode
 * "area" (that was the hard-coded behaviour before this class existed).
 */
class YeveaMeasure
{
    /** @var array<string, object> */
    private static array $cache = [];

    public static function configFor(?string $codfamilia): object
    {
        $key = (string) $codfamilia;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $familia = null;
        if ($key !== '') {
            $candidate = new Familia();
            if ($candidate->loadFromCode($key)) {
                $familia = $candidate;
            }
        }

        return self::$cache[$key] = self::normalize($familia);
    }

    /** Clears the per-request cache (used after saving a config). */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    public static function normalize(?Familia $familia): object
    {
        $raw = [];
        if ($familia !== null && !empty($familia->calc_config)) {
            $raw = json_decode((string) $familia->calc_config, true) ?: [];
        }

        $mode = in_array($raw['mode'] ?? '', ['area', 'volume'], true) ? $raw['mode'] : 'none';

        // legacy behaviour: tableros families are area-priced by default
        if ($mode === 'none' && empty($raw) && $familia !== null && ($familia->tipofamilia ?? '') === 'tableros') {
            $mode = 'area';
        }

        $dims = [];
        $familiaLimits = [
            'length' => [(float) ($familia->largo_min ?? 0), (float) ($familia->largo_max ?? 0)],
            'width' => [(float) ($familia->ancho_min ?? 0), (float) ($familia->ancho_max ?? 0)],
            'height' => [0.0, 0.0],
        ];
        foreach (['length', 'width', 'height'] as $dim) {
            $d = is_array($raw['dims'][$dim] ?? null) ? $raw['dims'][$dim] : [];
            $optionsSpec = trim((string) ($d['options'] ?? ''));
            $dims[$dim] = (object) [
                'label' => trim((string) ($d['label'] ?? '')),
                'unit' => trim((string) ($d['unit'] ?? '')) ?: 'cm',
                'options_spec' => $optionsSpec,
                'options' => self::expandOptions($optionsSpec),
                'min' => $familiaLimits[$dim][0],
                'max' => $familiaLimits[$dim][1],
            ];
        }

        $rates = [];
        foreach ((array) ($raw['capture_rates'] ?? []) as $row) {
            $desde = (float) ($row['desde'] ?? 0);
            $hasta = (float) ($row['hasta'] ?? 0);
            $precio = (float) ($row['precio'] ?? 0);
            if ($hasta > 0 && $precio > 0 && $hasta >= $desde) {
                $rates[] = (object) ['desde' => $desde, 'hasta' => $hasta, 'precio' => $precio];
            }
        }

        return (object) [
            'mode' => $mode,
            'price_label' => trim((string) ($raw['price_label'] ?? '')),
            'price_unit' => trim((string) ($raw['price_unit'] ?? '')) ?: ($mode === 'volume' ? 'l' : 'm²'),
            'show_unit_price' => (bool) ($raw['show_unit_price'] ?? true),
            'calc_weight' => (bool) ($raw['calc_weight'] ?? false),
            'overage_pct' => max(0.0, (float) ($raw['overage_pct'] ?? 0)),
            'dims' => (object) $dims,
            'capture_rates' => $rates,
        ];
    }

    /**
     * Expands an options spec to a sorted list of floats.
     * Accepts ranges "60-99" (step 1) and lists "60;61;70,5", mixed.
     *
     * @return float[]
     */
    public static function expandOptions(string $spec): array
    {
        $values = [];
        foreach (preg_split('/[;\n]+/', $spec) ?: [] as $part) {
            $part = str_replace(',', '.', trim($part));
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)$/', $part, $m)) {
                for ($v = (float) $m[1]; $v <= (float) $m[2] + 0.0001; $v++) {
                    $values[] = round($v, 2);
                }
            } elseif (is_numeric($part)) {
                $values[] = round((float) $part, 2);
            }
        }
        $values = array_values(array_unique($values));
        sort($values);
        return $values;
    }

    /**
     * Multiplier for the product's per-unit price: m² for area mode, litres
     * for volume mode, overage included. Null when the needed dimensions are
     * missing. Mode 'none' keeps the legacy behaviour: items that carry
     * dimensions (old tableros cart lines) are still priced by area.
     */
    public static function factorFor(?string $codfamilia, ?float $largoCm, ?float $anchoCm, ?float $altoCm = null): ?float
    {
        $config = self::configFor($codfamilia);

        $base = null;
        if ($config->mode === 'volume') {
            if ($largoCm > 0 && $anchoCm > 0 && $altoCm > 0) {
                $base = $largoCm * $anchoCm * $altoCm / 1000; // cm³ → litres
            }
        } elseif ($largoCm > 0 && $anchoCm > 0) {
            // area mode, and legacy fallback for mode none with dims present
            $base = $largoCm * $anchoCm / 10000; // cm² → m²
        }

        if ($base === null) {
            return null;
        }
        $overage = ($config->mode === 'none') ? 0.0 : $config->overage_pct;
        return $base * (1 + $overage / 100);
    }

    /**
     * True when the submitted dimensions are acceptable for this config:
     * required dims present and each within its options list (when defined)
     * or the familia min/max bounds (when set).
     */
    public static function validateDims(object $config, ?float $largoCm, ?float $anchoCm, ?float $altoCm = null): bool
    {
        $checks = [['length', $largoCm], ['width', $anchoCm]];
        if ($config->mode === 'volume') {
            $checks[] = ['height', $altoCm];
        }

        foreach ($checks as [$dim, $value]) {
            if ($value === null || $value <= 0) {
                return false;
            }
            $d = $config->dims->{$dim};
            if (!empty($d->options)) {
                $found = false;
                foreach ($d->options as $option) {
                    if (abs($option - $value) < 0.001) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
                continue;
            }
            if ($d->min > 0 && $value < $d->min) {
                return false;
            }
            if ($d->max > 0 && $value > $d->max) {
                return false;
            }
        }
        return true;
    }

    /**
     * Capture price for a slab: €/m² from the thickness-range table
     * (capture_rates) × area in m². 0 when no range matches.
     */
    public static function capturePrice(?string $codfamilia, float $gruesoCm, float $largoCm, float $anchoCm): float
    {
        if ($gruesoCm <= 0 || $largoCm <= 0 || $anchoCm <= 0) {
            return 0.0;
        }

        $config = self::configFor($codfamilia);
        foreach ($config->capture_rates as $rate) {
            if ($gruesoCm >= $rate->desde && $gruesoCm <= $rate->hasta) {
                return round($rate->precio * $largoCm * $anchoCm / 10000, 2);
            }
        }
        return 0.0;
    }
}

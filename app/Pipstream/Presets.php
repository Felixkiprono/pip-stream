<?php

namespace App\Pipstream;

/**
 * Copy styles offered in the app. Customers pick a style instead of filling in sizing maths;
 * "custom" exposes the raw fields.
 */
final class Presets
{
    public const STYLES = [
        'careful' => ['label' => 'Careful', 'blurb' => 'Risk 0.5% of balance per signal, at most 3 trades open.',
            'sizing_mode' => 'risk_pct', 'risk_pct' => 0.5, 'max_lot' => 0.5, 'limits' => ['max_open_trades' => 3]],
        'balanced' => ['label' => 'Balanced', 'blurb' => 'Risk 1% of balance per signal, at most 5 trades open.',
            'sizing_mode' => 'risk_pct', 'risk_pct' => 1.0, 'max_lot' => 1.0, 'limits' => ['max_open_trades' => 5]],
        'bold' => ['label' => 'Bold', 'blurb' => 'Risk 2% of balance per signal, at most 8 trades open.',
            'sizing_mode' => 'risk_pct', 'risk_pct' => 2.0, 'max_lot' => 2.0, 'limits' => ['max_open_trades' => 8]],
        'fixed' => ['label' => 'Fixed size', 'blurb' => 'The same lot size on every signal.',
            'sizing_mode' => 'fixed', 'fixed_lots' => 0.01, 'max_lot' => 1.0, 'limits' => ['max_open_trades' => 5]],
    ];

    public static function apply(string $style, array $overrides = []): array
    {
        $p = self::STYLES[$style] ?? self::STYLES['balanced'];
        $out = ['sizing_mode' => $p['sizing_mode'], 'risk_pct' => $p['risk_pct'] ?? null,
            'fixed_lots' => $p['fixed_lots'] ?? null, 'max_lot' => $p['max_lot'], 'limits' => $p['limits'], 'stake' => 1.0];
        foreach (['fixed_lots', 'max_lot', 'stake'] as $k) {
            if (isset($overrides[$k]) && $overrides[$k] !== '') {
                $out[$k] = (float) $overrides[$k];
            }
        }
        if (isset($overrides['max_daily_loss']) && $overrides['max_daily_loss'] !== '') {
            $out['limits']['max_daily_loss'] = (float) $overrides['max_daily_loss'];
        }

        return $out;
    }

    public static function styleOf(array $rule): string
    {
        foreach (self::STYLES as $k => $p) {
            if ($rule['sizing_mode'] === $p['sizing_mode'] && ($p['sizing_mode'] === 'fixed' ||
                (float) $rule['risk_pct'] === (float) $p['risk_pct'])) {
                return $k;
            }
        }

        return 'custom';
    }
}

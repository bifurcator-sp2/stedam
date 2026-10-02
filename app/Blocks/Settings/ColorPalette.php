<?php

namespace App\Blocks\Settings;

class ColorPalette
{
    /**
     * Структура: группа => [тип => [имя_блока => [light => [...], dark => [...]]]]
     * Или проще — плоский список с тремя каналами.
     *
     * @return array<string, array{light: array{bg:string,border:string,accent:string}, dark: array{bg:string,border:string,accent:string}}>
     */
    public static function all(): array
    {
        return [
            // ─── Теория (синие) ───────────────────────────────
            'definition' => [
                'light' => ['bg' => '#EFF6FF', 'border' => '#BFDBFE', 'accent' => '#3B82F6'],
                'dark'  => ['bg' => '#172554', 'border' => '#1E40AF', 'accent' => '#60A5FA'],
            ],
            'theorem' => [
                'light' => ['bg' => '#E0E7FF', 'border' => '#A5B4FC', 'accent' => '#6366F1'],
                'dark'  => ['bg' => '#1E1B4B', 'border' => '#3730A3', 'accent' => '#818CF8'],
            ],
            'statement' => [
                'light' => ['bg' => '#EEF2FF', 'border' => '#C7D2FE', 'accent' => '#818CF8'],
                'dark'  => ['bg' => '#1E1B4B', 'border' => '#4338CA', 'accent' => '#A5B4FC'],
            ],
            'lemma' => [
                'light' => ['bg' => '#F0F9FF', 'border' => '#BAE6FD', 'accent' => '#0EA5E9'],
                'dark'  => ['bg' => '#082F49', 'border' => '#075985', 'accent' => '#38BDF8'],
            ],

            // ─── Доказательства (зелёные) ─────────────────────
            'proof' => [
                'light' => ['bg' => '#ECFDF5', 'border' => '#A7F3D0', 'accent' => '#10B981'],
                'dark'  => ['bg' => '#022C22', 'border' => '#065F46', 'accent' => '#34D399'],
            ],
            'conclusion' => [
                'light' => ['bg' => '#D1FAE5', 'border' => '#6EE7B7', 'accent' => '#059669'],
                'dark'  => ['bg' => '#064E3B', 'border' => '#047857', 'accent' => '#10B981'],
            ],
            'solution' => [
                'light' => ['bg' => '#F0FDF4', 'border' => '#BBF7D0', 'accent' => '#22C55E'],
                'dark'  => ['bg' => '#052E16', 'border' => '#166534', 'accent' => '#4ADE80'],
            ],

            // ─── Примеры (янтарные) ───────────────────────────
            'example' => [
                'light' => ['bg' => '#FFFBEB', 'border' => '#FDE68A', 'accent' => '#F59E0B'],
                'dark'  => ['bg' => '#451A03', 'border' => '#78350F', 'accent' => '#FBBF24'],
            ],
            'task' => [
                'light' => ['bg' => '#FEF3C7', 'border' => '#FCD34D', 'accent' => '#D97706'],
                'dark'  => ['bg' => '#78350F', 'border' => '#92400E', 'accent' => '#F59E0B'],
            ],
            'exercise' => [
                'light' => ['bg' => '#FFF7ED', 'border' => '#FED7AA', 'accent' => '#F97316'],
                'dark'  => ['bg' => '#431407', 'border' => '#7C2D12', 'accent' => '#FB923C'],
            ],
            'illustration' => [
                'light' => ['bg' => '#FEFCE8', 'border' => '#FEF08A', 'accent' => '#EAB308'],
                'dark'  => ['bg' => '#422006', 'border' => '#854D0E', 'accent' => '#FACC15'],
            ],

            // ─── Внимание (красные) ───────────────────────────
            'note' => [
                'light' => ['bg' => '#FEF2F2', 'border' => '#FECACA', 'accent' => '#EF4444'],
                'dark'  => ['bg' => '#450A0A', 'border' => '#991B1B', 'accent' => '#F87171'],
            ],
            'warning-block' => [
                'light' => ['bg' => '#FFF7ED', 'border' => '#FDBA74', 'accent' => '#F97316'],
                'dark'  => ['bg' => '#431407', 'border' => '#9A3412', 'accent' => '#FB923C'],
            ],
            'error-block' => [
                'light' => ['bg' => '#FEE2E2', 'border' => '#FCA5A5', 'accent' => '#DC2626'],
                'dark'  => ['bg' => '#7F1D1D', 'border' => '#B91C1C', 'accent' => '#EF4444'],
            ],
            'important' => [
                'light' => ['bg' => '#FFF1F2', 'border' => '#FDA4AF', 'accent' => '#E11D48'],
                'dark'  => ['bg' => '#4C0519', 'border' => '#9F1239', 'accent' => '#FB7185'],
            ],

            // ─── Справочное (фиолетовые/серые) ────────────────
            'quote' => [
                'light' => ['bg' => '#F5F3FF', 'border' => '#DDD6FE', 'accent' => '#8B5CF6'],
                'dark'  => ['bg' => '#2E1065', 'border' => '#5B21B6', 'accent' => '#A78BFA'],
            ],
            'history' => [
                'light' => ['bg' => '#FAF5FF', 'border' => '#E9D5FF', 'accent' => '#A855F7'],
                'dark'  => ['bg' => '#3B0764', 'border' => '#6B21A8', 'accent' => '#C084FC'],
            ],
            'remark' => [
                'light' => ['bg' => '#F8FAFC', 'border' => '#E2E8F0', 'accent' => '#64748B'],
                'dark'  => ['bg' => '#1E293B', 'border' => '#334155', 'accent' => '#94A3B8'],
            ],
            'reference' => [
                'light' => ['bg' => '#F1F5F9', 'border' => '#CBD5E1', 'accent' => '#475569'],
                'dark'  => ['bg' => '#0F172A', 'border' => '#1E293B', 'accent' => '#64748B'],
            ],
        ];
    }

    /** Плоские списки для allowed в SettingDefinition */
    public static function bgTokens(): array
    {
        return array_map(fn ($name) => "bg-{$name}", array_keys(self::all()));
    }

    public static function borderTokens(): array
    {
        return array_map(fn ($name) => "border-{$name}", array_keys(self::all()));
    }

    public static function accentTokens(): array
    {
        return array_map(fn ($name) => "accent-{$name}", array_keys(self::all()));
    }
}

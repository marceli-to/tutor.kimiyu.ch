import type { GraphicsMode } from '@/components/GraphicsField.vue';
import type {
    LessonModule,
    Purpose,
    Scope,
} from '@/components/LessonOptions.vue';

export type LessonSettings = {
    purpose: Purpose;
    scope: Scope;
    modules: LessonModule[];
    graphics_mode: GraphicsMode;
};

export type PresetKey = 'kurz' | 'normal' | 'pruefung';

const ALL_MODULES: LessonModule[] = [
    'quiz',
    'sortieren',
    'karten',
    'lueckentext',
];

// Voreinstellungen im einfachen Modus, sie füllen die Felder des erweiterten Modus
export const PRESETS: {
    key: PresetKey;
    label: string;
    hint: string;
    settings: LessonSettings;
}[] = [
    {
        key: 'kurz',
        label: 'Kurz & schnell',
        hint: 'Wenig Text, kurzes Quiz und Karteikarten, ohne Grafik. Am günstigsten.',
        settings: {
            purpose: 'neu',
            scope: 'kurz',
            modules: ['quiz', 'karten'],
            graphics_mode: 'none',
        },
    },
    {
        key: 'normal',
        label: 'Normal',
        hint: 'Erklärung, Quiz, passende Übungen, eine Grafik, wenn sie hilft.',
        settings: {
            purpose: 'neu',
            scope: 'normal',
            modules: ALL_MODULES,
            graphics_mode: 'auto',
        },
    },
    {
        key: 'pruefung',
        label: 'Prüfung',
        hint: 'Kompakt, Fokus auf Begriffe und typische Prüfungsfragen.',
        settings: {
            purpose: 'pruefung',
            scope: 'normal',
            modules: ALL_MODULES,
            graphics_mode: 'auto',
        },
    },
];

export function presetSettings(key: PresetKey): LessonSettings {
    const settings = PRESETS.find((preset) => preset.key === key)!.settings;

    return { ...settings, modules: [...settings.modules] };
}

// Gleich, wenn Zweck, Umfang, Grafik und die Menge der Module übereinstimmen
export function sameSettings(a: LessonSettings, b: LessonSettings): boolean {
    return (
        a.purpose === b.purpose &&
        a.scope === b.scope &&
        a.graphics_mode === b.graphics_mode &&
        a.modules.length === b.modules.length &&
        a.modules.every((module) => b.modules.includes(module))
    );
}

export function matchPreset(settings: LessonSettings): PresetKey | null {
    return (
        PRESETS.find((preset) => sameSettings(preset.settings, settings))
            ?.key ?? null
    );
}

// Eine Zeile wie «Neuer Stoff · Ausführlich · 2 Module · ohne Grafik»
export function settingsSummary(settings: LessonSettings): string {
    const purpose = settings.purpose === 'pruefung' ? 'Prüfung' : 'Neuer Stoff';
    const scope = {
        kurz: 'Kurz',
        normal: 'Normal',
        ausfuehrlich: 'Ausführlich',
    }[settings.scope];
    const modules =
        settings.modules.length === 1
            ? '1 Modul'
            : `${settings.modules.length} Module`;
    const graphics = {
        none: 'ohne Grafik',
        auto: 'Grafik, wenn sie hilft',
        custom: 'eigene Grafiken',
    }[settings.graphics_mode];

    return [purpose, scope, modules, graphics].join(' · ');
}

<?php

namespace App\Lessons;

/**
 * Hero-Muster aus SKILL.md.
 */
enum HeroPattern: string
{
    case Regler = 'regler';
    case Ansichten = 'ansichten';
    case Schritte = 'schritte';
    case Zeitstrahl = 'zeitstrahl';
    case Hotspots = 'hotspots';
    case Rechner = 'rechner';

    public function label(): string
    {
        return match ($this) {
            self::Regler => 'Regler',
            self::Ansichten => 'Ansichten umschalten',
            self::Schritte => 'Schritt für Schritt',
            self::Zeitstrahl => 'Zeitstrahl',
            self::Hotspots => 'Karte/Schema antippen',
            self::Rechner => 'Rechner/Umformer',
        };
    }
}

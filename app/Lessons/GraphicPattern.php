<?php

namespace App\Lessons;

/**
 * Graphic patterns from SKILL.md.
 */
enum GraphicPattern: string
{
	case Sliders = 'sliders';
	case Views = 'views';
	case Steps = 'steps';
	case Timeline = 'timeline';
	case Hotspots = 'hotspots';
	case Calculator = 'calculator';

	public function label(): string
	{
		return match ($this) {
			self::Sliders => 'Regler',
			self::Views => 'Ansichten umschalten',
			self::Steps => 'Schritt für Schritt',
			self::Timeline => 'Zeitstrahl',
			self::Hotspots => 'Karte/Schema antippen',
			self::Calculator => 'Rechner/Umformer',
		};
	}
}

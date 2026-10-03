<?php

namespace App\Enums;

enum LessonStatus: string
{
	case Draft = 'draft';
	case Generating = 'generating';
	case Planned = 'planned';
	case Review = 'review';
	case Published = 'published';
	case Failed = 'failed';

	public function label(): string
	{
		return match ($this) {
			self::Draft => 'Entwurf',
			self::Generating => 'Wird erstellt',
			self::Planned => 'Plan prüfen',
			self::Review => 'Zur Prüfung',
			self::Published => 'Freigegeben',
			self::Failed => 'Fehlgeschlagen',
		};
	}
}

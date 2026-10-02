// Content of a lesson, schema version 1 (see app/Lessons/ContentValidator.php)

export type CategoryId = 'cat1' | 'cat2' | 'cat3';

// Origin of a block: from the photos or added from subject knowledge (missing on old pages)
export type Origin = { origin?: 'photo' | 'added' };

export type LessonBlock = Origin &
	(
		| { type: 'paragraph'; text: string }
		| { type: 'formula'; text: string; addendum?: string | null }
		| { type: 'facts'; entries: { title: string; text: string }[] }
		| {
				type: 'columns';
				entries: {
					title: string;
					category: CategoryId;
					paragraphs: string[];
				}[];
		  }
		| { type: 'box'; title: string; paragraphs: string[] }
		// Graphic 2 or 3 at this place; graphic 1 is at the top
		| { type: 'graphic'; number: number }
		// Languages profile: word list and verb table
		| {
				type: 'vocabulary';
				title?: string | null;
				entries: {
					foreign: string;
					german: string;
					info?: string | null;
				}[];
		  }
		| {
				type: 'conjugation';
				verb: string;
				tense: string;
				forms: { person: string; form: string }[];
		  }
		// Math profile: a task calculated step by step
		| {
				type: 'worked_solution';
				task: string;
				steps: { text: string; reason?: string | null }[];
				result: string;
		  }
	);

export type QuizQuestion = Origin & {
	id: string;
	question: string;
	options: string[];
	answer: number;
	hint?: string | null;
	explanation: string;
};

export type SortModuleData = {
	instructions?: string | null;
	categories: { id: CategoryId; label: string; sub?: string | null }[];
	terms: (Origin & {
		id: string;
		text: string;
		category: CategoryId;
		explanation?: string | null;
	})[];
};

export type FlashcardModuleData = {
	instructions?: string | null;
	entries: (Origin & { id: string; front: string; back: string })[];
};

export type ClozeSegment = { text: string } | { id: string; answers: string[] };

export type ClozeModuleData = Origin & {
	instructions?: string | null;
	segments: ClozeSegment[];
};

// Math profile: a task the child calculates; the server checks the answer (App\Lessons\ExerciseAnswer)
export type Exercise = {
	id: string;
	question: string;
	kind: 'number' | 'fraction' | 'text';
	answer: string;
	tolerance?: number | null;
	unit?: string | null;
	hint?: string | null;
	solution_path: string;
};

export type ExerciseModuleData = {
	instructions?: string | null;
	entries: Exercise[];
};

export type LessonContent = {
	meta: {
		title: string;
		instructions: string;
		topic: string;
		key_idea: string;
		emoji: string;
		palette: string;
	};
	sections: { title: string; blocks: LessonBlock[] }[];
	try_it: {
		experiments: string[];
		everyday_comparison?: string | null;
	} | null;
	modules: {
		// null if the parents didn't want a quiz
		quiz: QuizQuestion[] | null;
		sorting: SortModuleData | null;
		flashcards: FlashcardModuleData | null;
		cloze: ClozeModuleData | null;
		// Only in the math profile; missing on older pages
		exercises?: ExerciseModuleData | null;
	};
	reflect: { question: string };
};

export type PaletteColors = Record<
	| 'accent'
	| 'accent-bg'
	| 'cat1'
	| 'cat1-bg'
	| 'cat2'
	| 'cat2-bg'
	| 'cat3'
	| 'cat3-bg',
	string
>;

export type Palette = {
	label: string;
	light: PaletteColors;
	dark: PaletteColors;
};

export type LessonGraphic = {
	url: string;
	description: string;
};

// Finished graphics by position (1 at the top, 2 and 3 at their block in the content)
export type LessonGraphics = Partial<Record<number, LessonGraphic>>;

// Parents only: state of every graphic, including failed ones
export type GraphicState = {
	number: number;
	error: string | null;
	canRegenerate: boolean;
	// Hidden by the parents in the edit view
	hidden: boolean;
};

// Result of a single answer, for the progress (phase 4)
export type ModuleAnswer = {
	module: 'quiz' | 'sorting' | 'cloze' | 'exercises';
	itemId: string;
	// Chosen option (quiz), chosen basket (sorting) or input (cloze, exercises);
	// the server checks itself whether it is right
	answer: number | string;
	correct: boolean;
};

// Subject profile of a lesson (see app/Lessons/Profile.php)
export type LessonProfile =
	| 'science'
	| 'general'
	| 'languages'
	| 'math'
	| 'geometry'
	| 'german';

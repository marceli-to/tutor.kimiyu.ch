/**
 * Scales a photo down in the browser and saves it as JPEG.
 *
 * - The orientation from the EXIF data is applied to the image (imageOrientation).
 * - The new JPEG contains no metadata (no GPS, no camera).
 * - Only Safari can read HEIC photos from the iPhone; other browsers throw an error.
 */
export async function resizeImage(file: File, maxEdge: number): Promise<File> {
	let bitmap: ImageBitmap;

	try {
		bitmap = await createImageBitmap(file, {
			imageOrientation: 'from-image',
		});
	} catch {
		throw new Error(
			`«${file.name}» kann dieser Browser nicht lesen. Bitte als JPEG speichern oder direkt mit der Kamera fotografieren.`,
		);
	}

	const scale = Math.min(1, maxEdge / Math.max(bitmap.width, bitmap.height));
	const width = Math.round(bitmap.width * scale);
	const height = Math.round(bitmap.height * scale);

	const canvas = document.createElement('canvas');
	canvas.width = width;
	canvas.height = height;
	canvas.getContext('2d')?.drawImage(bitmap, 0, 0, width, height);
	bitmap.close();

	const blob = await new Promise<Blob | null>((resolve) =>
		canvas.toBlob(resolve, 'image/jpeg', 0.85),
	);

	if (!blob) {
		throw new Error(`«${file.name}» konnte nicht verarbeitet werden.`);
	}

	const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';

	return new File([blob], name, { type: 'image/jpeg' });
}

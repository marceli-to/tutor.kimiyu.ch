export type ImageQuality = { dark: boolean; blurry: boolean };

// Startwerte, noch an echten Handyfotos von Buchseiten abzustimmen; lieber zu selten warnen als zu oft
const DARK_BELOW = 70; // mittlere Helligkeit 0–255
const BLURRY_BELOW = 60; // Varianz des Laplace-Filters
const SAMPLE_EDGE = 400;

/**
 * Grobe Prüfung eines Fotos im Browser: zu dunkel oder unscharf?
 * Arbeitet auf einer verkleinerten Graustufen-Kopie, damit es auch auf dem Handy schnell ist.
 */
export async function checkImageQuality(file: Blob): Promise<ImageQuality> {
    try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(
            1,
            SAMPLE_EDGE / Math.max(bitmap.width, bitmap.height),
        );
        const width = Math.max(1, Math.round(bitmap.width * scale));
        const height = Math.max(1, Math.round(bitmap.height * scale));

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');

        if (!context) {
            bitmap.close();

            return { dark: false, blurry: false };
        }

        context.drawImage(bitmap, 0, 0, width, height);
        bitmap.close();

        const { data } = context.getImageData(0, 0, width, height);
        const gray = new Uint8ClampedArray(width * height);

        for (let i = 0; i < gray.length; i++) {
            gray[i] =
                0.299 * data[i * 4] +
                0.587 * data[i * 4 + 1] +
                0.114 * data[i * 4 + 2];
        }

        const { brightness, sharpness } = measure(gray, width, height);

        return {
            dark: brightness < DARK_BELOW,
            blurry: sharpness < BLURRY_BELOW,
        };
    } catch {
        // Eine fehlgeschlagene Prüfung darf das Hochladen nie verhindern
        return { dark: false, blurry: false };
    }
}

/** Reine Berechnung, getrennt vom Canvas, damit sie nachvollziehbar bleibt. */
export function measure(
    gray: Uint8ClampedArray,
    width: number,
    height: number,
): { brightness: number; sharpness: number } {
    let sum = 0;

    for (let i = 0; i < gray.length; i++) {
        sum += gray[i];
    }

    const brightness = gray.length ? sum / gray.length : 0;

    // Varianz des 4-Nachbarn-Laplace-Filters über die inneren Pixel
    let count = 0;
    let lapSum = 0;
    let lapSquares = 0;

    for (let y = 1; y < height - 1; y++) {
        for (let x = 1; x < width - 1; x++) {
            const i = y * width + x;
            const value =
                4 * gray[i] -
                gray[i - width] -
                gray[i + width] -
                gray[i - 1] -
                gray[i + 1];

            lapSum += value;
            lapSquares += value * value;
            count++;
        }
    }

    if (count === 0) {
        return { brightness, sharpness: 0 };
    }

    const mean = lapSum / count;
    const sharpness = lapSquares / count - mean * mean;

    return { brightness, sharpness };
}

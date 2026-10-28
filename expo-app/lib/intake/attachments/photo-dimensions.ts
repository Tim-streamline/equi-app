export const MAX_PHOTO_LONG_EDGE = 7680;
export const MAX_PHOTO_SHORT_EDGE = 4320;
export const PHOTO_DIMENSIONS_ERROR = 'De foto is te groot. Maximaal 7680 × 4320 pixels (of 4320 × 7680 voor een staande foto).';

export function validatePhotoDimensions({ width, height }: { width: number; height: number }) {
  if (Math.max(width, height) > MAX_PHOTO_LONG_EDGE || Math.min(width, height) > MAX_PHOTO_SHORT_EDGE) {
    throw new Error(PHOTO_DIMENSIONS_ERROR);
  }
}
